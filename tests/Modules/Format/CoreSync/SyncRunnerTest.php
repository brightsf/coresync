<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\Sha256MismatchException;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\ReportClient;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\JobFilesEntityStub;
use Tests\Modules\Format\CoreSync\Support\JobsEntityStub;

// Нет PSR-4 автозагрузки для Tests\ (репо-паттерн APIImport) — подключаем стабы явно.
require_once __DIR__ . '/Support/JobsEntityStub.php';
require_once __DIR__ . '/Support/JobFilesEntityStub.php';

/**
 * Оркестрация SyncRunner на моках: lock-скип, fail-closed мажор, version-гейты,
 * терминальные статусы job'а (downloaded/cancelled/failed). БД не нужна (стаб-сущности).
 */
class SyncRunnerTest extends TestCase
{
    /** @var JobsEntityStub */
    private $jobsStub;

    /** @var JobFilesEntityStub */
    private $jobFilesStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobsStub = new JobsEntityStub();
        $this->jobFilesStub = new JobFilesEntityStub();
    }

    /**
     * @param array<string, mixed> $manifestOverride
     */
    private function manifestJson(int $version, string $schemaVersion = '1.0.0', array $manifestOverride = []): string
    {
        $manifest = array_merge([
            'schema_version'   => $schemaVersion,
            'snapshot_version' => $version,
            'channel_code'     => 'site-a',
            'generated_at'     => '2026-07-07T12:00:00Z',
            'language'         => 'ru',
            'currency'         => 'UAH',
            'sync_mode'        => 'full',
            'absent_policy'    => 'out_of_stock',
            'counts'           => [
                'categories' => 1, 'brands' => 1, 'features' => 1,
                'products' => 1, 'variants' => 1, 'redirects' => 0,
            ],
            'files'            => [
                ['name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1],
            ],
        ], $manifestOverride);

        return (string) json_encode($manifest);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function entityFactoryMock(): MockObject
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) {
            if ($class === CoreSyncJobsEntity::class) {
                return $this->jobsStub;
            }
            if ($class === CoreSyncJobFilesEntity::class) {
                return $this->jobFilesStub;
            }
            throw new \InvalidArgumentException('Unexpected entity: ' . $class);
        });

        return $factory;
    }

    private function settingsMock(bool $complete = true): MockObject
    {
        $value = $complete
            ? ['core_url' => 'https://core.example', 'channel_code' => 'site-a', 'token' => 'secret-token']
            : ['core_url' => '', 'channel_code' => '', 'token' => ''];

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($value) {
            return $key === Contract::SETTINGS_KEY ? $value : null;
        });

        return $settings;
    }

    private function configMock(): MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $key) {
            return $key === 'root_dir' ? sys_get_temp_dir() . '/' : null;
        });

        return $config;
    }

    private function lockMock(bool $acquire): MockObject
    {
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn($acquire);

        return $lock;
    }

    private function makeRunner(
        MockObject $settings,
        MockObject $http,
        MockObject $downloader,
        MockObject $reportClient,
        MockObject $lock,
        ?MockObject $applier = null
    ): SyncRunner {
        if ($applier === null) {
            // По умолчанию apply-фаза недостижима (гейты/отмена/ошибки до неё).
            $applier = $this->createMock(Applier::class);
            $applier->expects($this->never())->method('apply');
        }

        return new SyncRunner(
            $settings,
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock(),
            $lock,
            $this->configMock(),
            null
        );
    }

    /**
     * @param string $return Contract::STATUS_APPLIED|HELD|FAILED|CANCELLED
     */
    private function applierMock(string $return): MockObject
    {
        $applier = $this->createMock(Applier::class);
        $applier->expects($this->once())->method('apply')->willReturn($return);

        return $applier;
    }

    public function testSecondRunUnderLiveLockIsSkipped(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(false));
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls, 'при живом lock прогон не создаёт job');
    }

    public function testFailClosedOnUnsupportedMajorReportsFailedAndDownloadsNothing(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5, '2.0.0'));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->once())->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo(Contract::REPORT_FAILED),
                $this->anything(),
                $this->stringContains('unsupported schema_version')
            );

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $failed = $this->jobsStub->lastAddWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'должен быть создан failed-job');
        $this->assertSame(Contract::PHASE_MANIFEST, $failed['phase']);
        $this->assertSame([], $this->jobFilesStub->seedCalls, 'staging не засевается — ничего не качаем');
    }

    public function testEqualVersionIsNoopNoDownload(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls, 'no-op не создаёт job');
    }

    public function testOlderVersionIsIgnoredNoDownload(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(3));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls);
    }

    public function testNewVersionDownloadsThenAppliesAndMarksApplied(): void
    {
        $this->jobsStub->lastAppliedVersion = null;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->once())->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        // apply-report started + applied.
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->exactly(2))->method('send');

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_APPLIED)
        );
        $runner->run();

        $running = $this->jobsStub->lastAddWithStatus(Contract::STATUS_RUNNING);
        $this->assertNotNull($running, 'RUN создаёт running-job');
        $this->assertSame(9, $running['snapshot_version']);
        $this->assertCount(1, $this->jobFilesStub->seedCalls, 'файлы засеяны');

        $this->assertNotNull($this->jobsStub->lastUpdateWithStatus(Contract::STATUS_APPLYING), 'фаза apply стартует со статуса applying');
        $applied = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_APPLIED);
        $this->assertNotNull($applied, 'успешное применение → applied');
        $this->assertSame(Contract::PHASE_DONE, $applied['phase']);
    }

    public function testSyncModeNotFullFailsClosedBeforeDownload(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9, '1.0.0', ['sync_mode' => 'price_stock']));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->once())->method('send')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->equalTo(Contract::REPORT_FAILED), $this->anything(), $this->stringContains('price_stock'));

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $failed = $this->jobsStub->lastAddWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'sync_mode != full → failed-job');
        $this->assertSame([], $this->jobFilesStub->seedCalls, 'ничего не скачивается');
    }

    public function testApplyHeldMarksJobHeldAndReportsHeld(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $reportClient = $this->createMock(ReportClient::class);
        $sentStatuses = [];
        $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentStatuses): void {
            $sentStatuses[] = $args[3];
        });

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_HELD)
        );
        $runner->run();

        $held = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_HELD);
        $this->assertNotNull($held, 'absent > порога → held');
        $this->assertContains(Contract::REPORT_HELD, $sentStatuses, 'apply-report held отправлен');
    }

    public function testApplyFailedMarksJobFailedAndReportsFailed(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $reportClient = $this->createMock(ReportClient::class);
        $sentStatuses = [];
        $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentStatuses): void {
            $sentStatuses[] = $args[3];
        });

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_FAILED)
        );
        $runner->run();

        $failed = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'apply failed → job failed');
        $this->assertSame(Contract::PHASE_APPLY, $failed['phase']);
        $this->assertContains(Contract::REPORT_FAILED, $sentStatuses);
    }

    public function testCancelledDownloadMarksJobCancelled(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_CANCELLED);
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->never())->method('send');

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $cancelled = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_CANCELLED);
        $this->assertNotNull($cancelled, 'отменённый прогон → cancelled');
    }

    public function testSha256FailureMarksJobFailedAndReportsFailed(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willThrowException(new Sha256MismatchException('sha256 не совпал для файла снапшота: x'));

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->once())->method('send')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->equalTo(Contract::REPORT_FAILED), $this->anything(), $this->stringContains('sha256'));

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $failed = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'sha256-провал → failed');
        $this->assertSame(Contract::PHASE_DOWNLOAD, $failed['phase']);
    }

    public function testIncompleteSettingsRecordsFailedJobWithoutFetch(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner($this->settingsMock(false), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $failed = $this->jobsStub->lastAddWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'неполные настройки → failed-job для видимости');
    }
}
