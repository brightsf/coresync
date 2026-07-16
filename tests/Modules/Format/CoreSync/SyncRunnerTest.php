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
use Okay\Modules\Format\CoreSync\Core\Update\SchemaUpgrader;
use Okay\Modules\Format\CoreSync\Core\Update\Updater;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    /**
     * Настройки БЕЗ ключа `enabled` — ровно то, что лежит на уже настроенных установках (ключа не
     * было, пока его никто не читал). Все прочие тесты этого класса гоняются на них, поэтому они же
     * стерегут дефолт «отсутствует = включено»: смени дефолт на «выключено» — покраснеет весь класс.
     *
     * @param array<string, mixed> $overrides
     */
    private function settingsMock(bool $complete = true, array $overrides = []): MockObject
    {
        $value = $complete
            ? ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret-token']
            : ['core_url' => '', 'channel_code' => '', 'token' => ''];
        $value = array_merge($value, $overrides);

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
        ?MockObject $applier = null,
        ?LoggerInterface $logger = null,
        ?MockObject $updater = null,
        ?MockObject $schemaUpgrader = null
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
            $logger,
            $updater,
            $schemaUpgrader
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

    public function testPriceStockModeIsSupportedDownloadsAndApplies(): void
    {
        // M3: price_stock больше НЕ fail-closed (гейт M2 снят) — режим качает и применяет.
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9, '1.0.0', ['sync_mode' => 'price_stock']));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->once())->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->exactly(2))->method('send'); // started + applied

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
        $this->assertNotNull($running, 'price_stock создаёт running-job и качает набор');
        $this->assertNotNull($this->jobsStub->lastUpdateWithStatus(Contract::STATUS_APPLIED), 'price_stock применяется');
    }

    public function testUnsupportedSyncModeFailsClosedBeforeDownload(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9, '1.0.0', ['sync_mode' => 'weird_mode']));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->once())->method('send')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->equalTo(Contract::REPORT_FAILED), $this->anything(), $this->stringContains('weird_mode'));

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true));
        $runner->run();

        $failed = $this->jobsStub->lastAddWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed, 'неизвестный sync_mode → failed-job');
        $this->assertSame([], $this->jobFilesStub->seedCalls, 'ничего не скачивается');
    }

    public function testForceReapplyBypassesNoopAndReapplies(): void
    {
        // «Полное перепринятие»: версия уже применена, но force-флаг заставляет переприменить.
        $this->jobsStub->lastAppliedVersion = 9;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(9));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->once())->method('download')->willReturn(Contract::STATUS_DOWNLOADED);
        $reportClient = $this->createMock(ReportClient::class);

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) {
            if ($key === Contract::SETTINGS_KEY) {
                return ['core_url' => 'https://core.example', 'channel_code' => 'site-a', 'token' => 'secret-token'];
            }
            if ($key === Contract::SETTINGS_FORCE_REAPPLY_KEY) {
                return 1; // force-флаг взведён (кнопкой перепринятия)
            }

            return null;
        });
        $clearedForce = false;
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use (&$clearedForce): void {
            if ($key === Contract::SETTINGS_FORCE_REAPPLY_KEY && (int) $value === 0) {
                $clearedForce = true;
            }
        });

        $runner = new SyncRunner(
            $settings,
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $this->applierMock(Contract::STATUS_APPLIED),
            $this->entityFactoryMock(),
            $this->lockMock(true),
            $this->configMock(),
            null
        );
        $runner->run();

        $this->assertNotNull($this->jobsStub->lastAddWithStatus(Contract::STATUS_RUNNING), 'force → прогон не no-op, качает');
        $this->assertNotNull($this->jobsStub->lastUpdateWithStatus(Contract::STATUS_APPLIED), 'force → переприменено');
        $this->assertTrue($clearedForce, 'force-флаг снят после старта прогона');
    }

    public function testBoundStatusReportedForBindPhase(): void
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
            $this->applierMock(Contract::STATUS_BOUND)
        );
        $runner->run();

        $bound = $this->jobsStub->lastUpdateWithStatus(Contract::STATUS_BOUND);
        $this->assertNotNull($bound, 'bind-фаза → job bound');
        $this->assertSame(Contract::PHASE_DONE, $bound['phase']);
        $this->assertContains(Contract::REPORT_BOUND, $sentStatuses, 'apply-report bound отправлен');
    }

    /**
     * §B: «связано 0 из N» перестаёт выглядеть успехом. Исход холостого bind (ни один SKU витрины не
     * наш) — рабочий, но оператор обязан его РАЗЛИЧАТЬ: витрина получит каталог как НОВЫЙ, а не
     * подтянет цены к своим товарам. Лог — warning (не info), в stats — явный признак исхода.
     * Контракт apply-report не меняется: статус прежний (bound), признак едет внутри свободного stats.
     */
    public function testBoundWithZeroLinkedLogsWarningAndMarksOutcomeInStats(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(11));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $sentPayloads = [];
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentPayloads): void {
            $sentPayloads[$args[3]] = $args[4];
        });

        // Applier связал 0 при 7 несовпавших SKU (счётчики едут через ApplyStats by-ref).
        $applier = $this->createMock(Applier::class);
        $applier->expects($this->once())->method('apply')
            ->willReturnCallback(static function ($manifest, $dir, $checkpoints, $cancel, $stats): string {
                $stats->bound = 0;
                $stats->unmatched = 7;

                return Contract::STATUS_BOUND;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $warnings = [];
        $logger->method('warning')->willReturnCallback(static function ($message) use (&$warnings): void {
            $warnings[] = (string) $message;
        });
        $logger->method('info')->willReturnCallback(static function ($message) use (&$warnings): void {
            // info-сообщения намеренно игнорируем: холостой bind обязан быть именно warning.
        });

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $applier,
            $logger
        );
        $runner->run();

        $this->assertNotEmpty($warnings, 'связано 0 — исход логируется warning, а не info');
        $this->assertStringContainsString('0', implode(' ', $warnings));
        $payload = $sentPayloads[Contract::REPORT_BOUND] ?? null;
        $this->assertNotNull($payload, 'apply-report bound отправлен');
        $this->assertSame(Contract::BIND_OUTCOME_NOTHING_LINKED, $payload['outcome'] ?? null, 'исход помечен в stats');
        $this->assertSame(7, $payload['unmatched'] ?? null);
    }

    /** Обратная сторона §B: связали хоть что-то → исход прежний (info, outcome=linked). */
    public function testBoundWithLinkedRowsIsNotWarned(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(12));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $sentPayloads = [];
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentPayloads): void {
            $sentPayloads[$args[3]] = $args[4];
        });

        $applier = $this->createMock(Applier::class);
        $applier->expects($this->once())->method('apply')
            ->willReturnCallback(static function ($manifest, $dir, $checkpoints, $cancel, $stats): string {
                $stats->bound = 3;

                return Contract::STATUS_BOUND;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $warnings = [];
        $logger->method('warning')->willReturnCallback(static function ($message) use (&$warnings): void {
            $warnings[] = (string) $message;
        });

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $applier,
            $logger
        );
        $runner->run();

        $this->assertSame([], $warnings, 'успешное связывание не warning-ится');
        $this->assertSame(Contract::BIND_OUTCOME_LINKED, $sentPayloads[Contract::REPORT_BOUND]['outcome'] ?? null);
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

    // ------------------------------------------------------------------
    // Стоп-кран (SATGO-1 §A) — вход «крон»
    // ------------------------------------------------------------------

    /**
     * ВХОД 1 из 3 (крон, `Init::init()` → Schedule([SyncRunner::class, 'run'])). Выключенный модуль
     * не ходит в ядро и не трогает витрину. Тик крона — тихий no-op: ни job'а, ни failed-строки
     * (выключено — это норма, а не сбой; failed-строка врала бы оператору о поломке).
     *
     * KILL-ПРОБА (мутация 1 из 3, независимая): убрать гейт из SyncRunner::run() → fetchManifest
     * вызовется → тест красный. Гейты в PingController/CoreSyncAdmin этот тест НЕ прикрывают —
     * крон зовёт run() напрямую, мимо них.
     */
    public function testDisabledModuleDoesNotSyncFromCron(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->never())->method('send');

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['enabled' => 0]),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true)
        );
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls, 'выключенный модуль не создаёт job (тихий no-op, не failed)');
    }

    /**
     * Дефолт не ломает живое (приёмка §2): у уже настроенных установок ключа `enabled` в настройках
     * НЕТ — и обмен обязан продолжать работать ровно как до этой ветки.
     */
    public function testMissingEnabledKeyMeansEnabled(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->once())->method('fetchManifest')->willReturn($this->manifestJson(7));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $reportClient = $this->createMock(ReportClient::class);

        $settings = $this->settingsMock(); // ← ключа `enabled` в этом массиве нет
        $runner = $this->makeRunner(
            $settings,
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_APPLIED)
        );
        $runner->run();

        $this->assertNotSame([], $this->jobsStub->addCalls, 'нет ключа `enabled` → модуль включён, прогон идёт');
    }

    // ------------------------------------------------------------------
    // Добор отложенных пингов (D-SAT-PING-PENDING-UNREAD, SAT-RT §0.2)
    // ------------------------------------------------------------------

    /** @var list<array{0:string,1:mixed}> записи settings->set (для проверки потребления флага) */
    private $pingSets = [];

    /**
     * Settings-мок с изменяемым флагом отложенного пинка. $stickyPending=true имитирует непрерывный
     * поток пингов (флаг взводится снова сразу после сброса — job активен во время добора).
     */
    private function pingSettingsMock(int $pendingInitial, bool $stickyPending = false, ?int $enabled = null): MockObject
    {
        $this->pingSets = [];
        $state = (object) ['pending' => $pendingInitial];
        $cfg = ['core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret-token'];
        if ($enabled !== null) {
            $cfg['enabled'] = $enabled;
        }

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($state, $cfg, $stickyPending) {
            if ($key === Contract::SETTINGS_KEY) {
                return $cfg;
            }
            if ($key === Contract::SETTINGS_PING_PENDING_KEY) {
                return $stickyPending ? 1 : $state->pending;
            }

            return null;
        });
        $sets = &$this->pingSets;
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use ($state, &$sets): void {
            $sets[] = [$key, $value];
            if ($key === Contract::SETTINGS_PING_PENDING_KEY) {
                $state->pending = (int) $value;
            }
        });

        return $settings;
    }

    /**
     * @param callable(int):void|null $onFetch
     */
    private function countingHttp(&$fetchCount): MockObject
    {
        $fetchCount = 0;
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturnCallback(function () use (&$fetchCount) {
            $fetchCount++;

            return $this->manifestJson(9);
        });

        return $http;
    }

    private function applierAlwaysApplies(): MockObject
    {
        $applier = $this->createMock(Applier::class);
        $applier->method('apply')->willReturn(Contract::STATUS_APPLIED);

        return $applier;
    }

    /**
     * Пинок во время прогона взвёл флаг PING_PENDING → по завершении текущего прогона (всё ещё под
     * lock) делается ОДИН добор-прогон, флаг потреблён (сброшен в 0). Раньше флаг никто не читал.
     */
    public function testPendingPingTriggersOneFollowupRun(): void
    {
        $settings = $this->pingSettingsMock(1);
        $http = $this->countingHttp($fetchCount);
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $runner = $this->makeRunner(
            $settings,
            $http,
            $downloader,
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            $this->applierAlwaysApplies()
        );
        $runner->run();

        $this->assertSame(2, $fetchCount, 'начальный прогон + один добор отложенного пинка');
        $this->assertContains([Contract::SETTINGS_PING_PENDING_KEY, 0], $this->pingSets, 'флаг пинка сброшен (потреблён)');
    }

    /**
     * Непрерывный поток пингов (флаг взводится снова сразу после сброса) НЕ держит воркер вечно:
     * жёсткий кап итераций ограничивает число доборов. initial + кап доборов, не бесконечно.
     */
    public function testPendingPingFollowupIsCapped(): void
    {
        $settings = $this->pingSettingsMock(1, true); // sticky: флаг всегда взведён
        $http = $this->countingHttp($fetchCount);
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $runner = $this->makeRunner(
            $settings,
            $http,
            $downloader,
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            $this->applierAlwaysApplies()
        );
        $runner->run();

        // initial (1) + кап доборов (3) = 4 — конечно, несмотря на вечно взведённый флаг.
        $this->assertSame(4, $fetchCount, 'кап держит поток пингов конечным');
    }

    /** Флаг не взведён → добора нет: ровно один прогон, флаг не трогается. */
    public function testNoPendingPingMeansNoFollowup(): void
    {
        $settings = $this->pingSettingsMock(0);
        $http = $this->countingHttp($fetchCount);
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);

        $runner = $this->makeRunner(
            $settings,
            $http,
            $downloader,
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            $this->applierAlwaysApplies()
        );
        $runner->run();

        $this->assertSame(1, $fetchCount, 'без отложенного пинка — ровно один прогон');
        $this->assertNotContains([Contract::SETTINGS_PING_PENDING_KEY, 0], $this->pingSets, 'нечего сбрасывать');
    }

    /**
     * Выключенный модуль не добирает: стоп-кран на входе run() возвращает управление ДО прогона и
     * добора — ни ядра, ни потребления флага (флаг доживёт до включения, а не выстрелит «за прошлое»).
     */
    public function testDisabledModuleDoesNotDrainPending(): void
    {
        $settings = $this->pingSettingsMock(1, false, 0); // enabled=0, флаг взведён
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');

        $runner = $this->makeRunner(
            $settings,
            $http,
            $this->createMock(SnapshotDownloader::class),
            $this->createMock(ReportClient::class),
            $this->lockMock(true)
        );
        $runner->run();

        $this->assertSame([], $this->pingSets, 'выключенный модуль флаг не потребляет и не добирает');
    }

    // ------------------------------------------------------------------
    // Шаг самообновления модуля (пре-спека фаза 1) — под lock, за стоп-краном
    // ------------------------------------------------------------------

    /**
     * После snapshot-прохода тик спрашивает обновление ровно с настроенными адресом/каналом/токеном.
     * doRun — no-op (версия уже применена), но шаг обновления всё равно выполняется каждый тик.
     */
    public function testUpdateStepInvokedAfterSyncWithConfig(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5)); // no-op версия
        $downloader = $this->createMock(SnapshotDownloader::class);
        $reportClient = $this->createMock(ReportClient::class);

        $updater = $this->createMock(Updater::class);
        $updater->expects($this->once())->method('checkAndUpdate')
            ->with('https://core.example', '42', 'secret-token');

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true), null, null, $updater);
        $runner->run();
    }

    /** Стоп-кран останавливает и обновление: выключенный модуль до шага обновления не доходит. */
    public function testDisabledModuleSkipsUpdateStep(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');
        $updater = $this->createMock(Updater::class);
        $updater->expects($this->never())->method('checkAndUpdate');

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['enabled' => 0]),
            $http,
            $this->createMock(SnapshotDownloader::class),
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            null,
            null,
            $updater
        );
        $runner->run();
    }

    /** Неполные настройки (нет адреса/канала/токена) — шаг обновления не зовётся (нечего спрашивать). */
    public function testIncompleteSettingsSkipUpdateStep(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $downloader = $this->createMock(SnapshotDownloader::class);
        $reportClient = $this->createMock(ReportClient::class);
        $updater = $this->createMock(Updater::class);
        $updater->expects($this->never())->method('checkAndUpdate');

        $runner = $this->makeRunner($this->settingsMock(false), $http, $downloader, $reportClient, $this->lockMock(true), null, null, $updater);
        $runner->run();
    }

    /** Явная галка «включён» работает как включено (симметрия к testDisabledModuleDoesNotSyncFromCron). */
    public function testExplicitlyEnabledModuleSyncs(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->once())->method('fetchManifest')->willReturn($this->manifestJson(7));

        $downloader = $this->createMock(SnapshotDownloader::class);
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['enabled' => 1]),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_APPLIED)
        );
        $runner->run();

        $this->assertNotSame([], $this->jobsStub->addCalls, 'enabled=1 → прогон идёт');
    }

    /**
     * Выключение — стоп-кран для СЛЕДУЮЩИХ прогонов, а не kill уже бегущего: гейт стоит на входе,
     * поэтому уже захваченный lock/бегущий прогон он не трогает. Здесь это видно так: при
     * enabled=0 гейт срабатывает ДО lock — чужой прогон не прерывается и lock не дёргается.
     */
    public function testDisabledGateDoesNotTouchRunningExchange(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');

        $lock = $this->createMock(LockHelper::class);
        $lock->expects($this->never())->method('acquire');
        $lock->expects($this->never())->method('release');

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['enabled' => 0]),
            $http,
            $this->createMock(SnapshotDownloader::class),
            $this->createMock(ReportClient::class),
            $lock
        );
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls);
    }

    // ------------------------------------------------------------------
    // Догон схемы таблиц (D-SAT-UPDATE-SCHEMA-MIGRATE) — старт тика, под lock, за стоп-краном
    // ------------------------------------------------------------------

    /**
     * Догон схемы — ПЕРВЫМ шагом тика (до применения данных), свап (update) — ПОСЛЕДНИМ. Догон
     * вызывается РОВНО один раз на старте: доказывает, что после swap в том же процессе схему не
     * трогаем (мина same-process, тест 3 брифа).
     */
    public function testSchemaUpgradeRunsFirstThenSyncThenUpdate(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;
        $order = [];

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturnCallback(function () use (&$order) {
            $order[] = 'sync';

            return $this->manifestJson(5); // версия уже применена → doRun no-op (ничего не качает)
        });
        $downloader = $this->createMock(SnapshotDownloader::class);
        $reportClient = $this->createMock(ReportClient::class);

        $updater = $this->createMock(Updater::class);
        $updater->method('checkAndUpdate')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'update';
        });

        $schema = $this->createMock(SchemaUpgrader::class);
        $schema->expects($this->once())->method('upgrade')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'schema';

            return true;
        });

        $runner = $this->makeRunner($this->settingsMock(), $http, $downloader, $reportClient, $this->lockMock(true), null, null, $updater, $schema);
        $runner->run();

        $this->assertSame(['schema', 'sync', 'update'], $order, 'догон схемы первым; свап последним; догон ровно один раз (не после swap)');
    }

    /**
     * Провал догона схемы сворачивает тик ДО применения данных и ДО шага обновления: стейл-схема не
     * принимает снапшот и не тянет новый код (fail-closed, тест 2 брифа). Маркер поднимет/повторит
     * следующий тик — это ответственность SchemaUpgrader (см. SchemaUpgraderTest).
     */
    public function testSchemaUpgradeFailureAbortsTickBeforeSyncAndUpdate(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects($this->never())->method('fetchManifest');

        $updater = $this->createMock(Updater::class);
        $updater->expects($this->never())->method('checkAndUpdate');

        $schema = $this->createMock(SchemaUpgrader::class);
        $schema->expects($this->once())->method('upgrade')->willReturn(false);

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $this->createMock(SnapshotDownloader::class),
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            null,
            null,
            $updater,
            $schema
        );
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls, 'провал догона схемы → тик свёрнут, данные не применяются');
    }

    /** Стоп-кран останавливает и догон схемы: выключенный модуль до шага схемы не доходит. */
    public function testDisabledModuleSkipsSchemaUpgrade(): void
    {
        $schema = $this->createMock(SchemaUpgrader::class);
        $schema->expects($this->never())->method('upgrade');

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['enabled' => 0]),
            $this->createMock(SnapshotHttpClient::class),
            $this->createMock(SnapshotDownloader::class),
            $this->createMock(ReportClient::class),
            $this->lockMock(true),
            null,
            null,
            null,
            $schema
        );
        $runner->run();
    }
}
