<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
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
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
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

    private function entityFactoryMock(
        ?MockObject $imagesEntity = null,
        ?MockObject $categoryImagesEntity = null
    ): MockObject
    {
        if ($imagesEntity === null) {
            $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
            $imagesEntity->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
            $imagesEntity->method('countRetryableFailed')->willReturn(0);
        }
        if ($categoryImagesEntity === null) {
            $categoryImagesEntity = $this->createMock(CoreSyncCategoryImagesEntity::class);
            $categoryImagesEntity->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
            $categoryImagesEntity->method('countRetryableFailed')->willReturn(0);
        }
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) use ($imagesEntity, $categoryImagesEntity) {
            if ($class === CoreSyncJobsEntity::class) {
                return $this->jobsStub;
            }
            if ($class === CoreSyncJobFilesEntity::class) {
                return $this->jobFilesStub;
            }
            if ($class === CoreSyncImagesEntity::class) {
                return $imagesEntity;
            }
            if ($class === CoreSyncCategoryImagesEntity::class) {
                return $categoryImagesEntity;
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
        $http->method('fetchManifest')->willReturn($this->manifestJson(5, '3.0.0'));

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

    public function testV2WithoutSafeSourceInstanceFailsBeforeDownload(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5, '2.0.0'));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);

        $runner = $this->makeRunner(
            $this->settingsMock(true, ['source_instance' => '../grundfos']),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true)
        );
        $runner->run();

        $failed = $this->jobsStub->lastAddWithStatus(Contract::STATUS_FAILED);
        $this->assertNotNull($failed);
        $this->assertSame(Contract::PHASE_MANIFEST, $failed['phase']);
        $this->assertSame([], $this->jobFilesStub->seedCalls);
    }

    public function testEqualVersionIsNoopNoDownload(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::never())->method('applyPendingImages');

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $applier
        );
        $runner->run();

        $this->assertSame([], $this->jobsStub->addCalls, 'no-op не создаёт job');
    }

    public function testEqualVersionWithPendingImagesRunsOnlyCatchupPhase(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects(self::never())->method('send');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('applyPendingImages')
            ->with(self::isType('callable'), self::isInstanceOf(ApplyStats::class), 1)
            ->willReturnCallback(static function (callable $isCancelled, ApplyStats $stats): string {
                $stats->imagesDownloaded = 2;
                $stats->imagesFailed = 1;

                return Contract::STATUS_APPLIED;
            });
        $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
        $imagesEntity->method('countByState')->willReturnOnConsecutiveCalls(
            [
                Contract::IMAGE_STATE_PENDING => 3,
                Contract::IMAGE_STATE_DONE => 4,
                Contract::IMAGE_STATE_FAILED => 0,
            ],
            [
                Contract::IMAGE_STATE_PENDING => 1,
                Contract::IMAGE_STATE_DONE => 6,
                Contract::IMAGE_STATE_FAILED => 1,
            ]
        );
        $imagesEntity->method('countRetryableFailed')
            ->with(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS)
            ->willReturnOnConsecutiveCalls(0, 1);
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $runner = new SyncRunner(
            $this->settingsMock(),
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock($imagesEntity),
            $this->lockMock(true),
            $this->configMock(),
            $logger
        );
        $runner->run();

        self::assertSame([], $this->jobsStub->addCalls, 'images-only catch-up does not create a full apply job');
        self::assertContains(
            'CoreSync: добор хвоста картинок: товарные pending=3 failed_retryable=0 exhausted=0',
            $messages
        );
        self::assertContains(
            'CoreSync: итог добора: downloaded=2 failed=1 pending=1 failed_retryable=1 exhausted=0',
            $messages
        );
    }

    public function testEqualV2VersionWithOnlyCategoryPendingRunsCatchupAndLogsBothQueues(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5, '2.0.0'));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects(self::never())->method('send');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('applyPendingImages')
            ->with(self::isType('callable'), self::isInstanceOf(ApplyStats::class), 2)
            ->willReturnCallback(static function (callable $isCancelled, ApplyStats $stats): string {
                $stats->categoryImagesPending = 1;

                return Contract::STATUS_APPLIED;
            });
        $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
        $imagesEntity->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        $imagesEntity->method('countRetryableFailed')->willReturn(0);
        $categoryImagesEntity = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImagesEntity->method('countRetryableFailed')->willReturn(0);
        $categoryImagesEntity->method('countByState')->willReturnOnConsecutiveCalls(
            [
                Contract::IMAGE_STATE_PENDING => 1,
                Contract::IMAGE_STATE_DONE => 0,
                Contract::IMAGE_STATE_FAILED => 0,
            ],
            [
                Contract::IMAGE_STATE_PENDING => 0,
                Contract::IMAGE_STATE_DONE => 1,
                Contract::IMAGE_STATE_FAILED => 0,
            ]
        );
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $runner = new SyncRunner(
            $this->settingsMock(true, ['source_instance' => 'grundfos']),
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock($imagesEntity, $categoryImagesEntity),
            $this->lockMock(true),
            $this->configMock(),
            $logger
        );
        $runner->run();

        self::assertSame([], $this->jobsStub->addCalls, 'category-only catch-up does not create a full apply job');
        self::assertContains(
            'CoreSync: добор хвоста картинок: товарные pending=0 failed_retryable=0 exhausted=0'
            . ', категорийные pending=1 failed_retryable=0 exhausted=0',
            $messages
        );
        self::assertContains(
            'CoreSync: итог добора: downloaded=0 failed=0 pending=0 failed_retryable=0 exhausted=0',
            $messages
        );
        self::assertContains(
            'CoreSync: итог категорийного добора: attempted=1 failed=0 pending=0 failed_retryable=0 exhausted=0',
            $messages
        );
    }

    /**
     * A1. Тик стабильной версии добирает ТОВАРНЫЙ failed-хвост, пока попытки не исчерпаны
     * (D-CORESYNC-FAILED-TAIL-NOT-RETRIED): pending=0, но retryable-failed>0 → ровно один
     * applyPendingImages, без скачивания снапшота и без job'а.
     */
    public function testEqualVersionWithOnlyRetryableFailedProductImagesRunsCatchup(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects(self::never())->method('send');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('applyPendingImages')
            ->with(self::isType('callable'), self::isInstanceOf(ApplyStats::class), 1)
            ->willReturnCallback(static function (callable $isCancelled, ApplyStats $stats): string {
                $stats->imagesDownloaded = 2;

                return Contract::STATUS_APPLIED;
            });

        $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
        $imagesEntity->method('countByState')->willReturnOnConsecutiveCalls(
            [
                Contract::IMAGE_STATE_PENDING => 0,
                Contract::IMAGE_STATE_DONE => 4,
                Contract::IMAGE_STATE_FAILED => 3,
            ],
            [
                Contract::IMAGE_STATE_PENDING => 0,
                Contract::IMAGE_STATE_DONE => 6,
                Contract::IMAGE_STATE_FAILED => 1,
            ]
        );
        // 3 failed, из них 2 ещё в пределах капа и 1 исчерпанная.
        $imagesEntity->method('countRetryableFailed')
            ->with(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS)
            ->willReturnOnConsecutiveCalls(2, 0);
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $runner = new SyncRunner(
            $this->settingsMock(),
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock($imagesEntity),
            $this->lockMock(true),
            $this->configMock(),
            $logger
        );
        $runner->run();

        self::assertSame([], $this->jobsStub->addCalls, 'failed-tail catch-up does not create a full apply job');
        self::assertContains(
            'CoreSync: добор хвоста картинок: товарные pending=0 failed_retryable=2 exhausted=1',
            $messages
        );
        self::assertContains(
            'CoreSync: итог добора: downloaded=2 failed=0 pending=0 failed_retryable=0 exhausted=1',
            $messages
        );
    }

    /** A2. То же для КАТЕГОРИЙНОЙ очереди (только v2): pending=0 у обеих, retryable-failed есть у категорийной. */
    public function testEqualV2VersionWithOnlyRetryableFailedCategoryImagesRunsCatchup(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5, '2.0.0'));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects(self::never())->method('send');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('applyPendingImages')
            ->with(self::isType('callable'), self::isInstanceOf(ApplyStats::class), 2)
            ->willReturnCallback(static function (callable $isCancelled, ApplyStats $stats): string {
                $stats->categoryImagesPending = 1;

                return Contract::STATUS_APPLIED;
            });

        $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
        $imagesEntity->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        $imagesEntity->method('countRetryableFailed')->willReturn(0);
        $categoryImagesEntity = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImagesEntity->method('countByState')->willReturnOnConsecutiveCalls(
            [
                Contract::IMAGE_STATE_PENDING => 0,
                Contract::IMAGE_STATE_DONE => 0,
                Contract::IMAGE_STATE_FAILED => 1,
            ],
            [
                Contract::IMAGE_STATE_PENDING => 0,
                Contract::IMAGE_STATE_DONE => 1,
                Contract::IMAGE_STATE_FAILED => 0,
            ]
        );
        $categoryImagesEntity->method('countRetryableFailed')
            ->with(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS)
            ->willReturnOnConsecutiveCalls(1, 0);
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $runner = new SyncRunner(
            $this->settingsMock(true, ['source_instance' => 'grundfos']),
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock($imagesEntity, $categoryImagesEntity),
            $this->lockMock(true),
            $this->configMock(),
            $logger
        );
        $runner->run();

        self::assertSame([], $this->jobsStub->addCalls, 'category failed-tail catch-up does not create a job');
        self::assertContains(
            'CoreSync: добор хвоста картинок: товарные pending=0 failed_retryable=0 exhausted=0'
            . ', категорийные pending=0 failed_retryable=1 exhausted=0',
            $messages
        );
    }

    /**
     * A3. Обратная сторона капа: failed-хвост ИСЧЕРПАН (attempts >= капа) → тик не зовёт фазу вовсе
     * (иначе мёртвая ссылка донора переигрывается каждым тиком крона вечно).
     */
    public function testEqualVersionWithOnlyExhaustedFailedImagesStaysNoop(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $reportClient = $this->createMock(ReportClient::class);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::never())->method('applyPendingImages');

        $imagesEntity = $this->createMock(CoreSyncImagesEntity::class);
        $imagesEntity->method('countByState')->willReturn([
            Contract::IMAGE_STATE_PENDING => 0,
            Contract::IMAGE_STATE_DONE => 4,
            Contract::IMAGE_STATE_FAILED => 2,
        ]);
        $imagesEntity->method('countRetryableFailed')
            ->with(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS)
            ->willReturn(0);
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $runner = new SyncRunner(
            $this->settingsMock(),
            $http,
            new ManifestValidator(),
            $downloader,
            $reportClient,
            $applier,
            $this->entityFactoryMock($imagesEntity),
            $this->lockMock(true),
            $this->configMock(),
            $logger
        );
        $runner->run();

        self::assertSame([], $this->jobsStub->addCalls);
        self::assertContains('CoreSync: версия 5 уже применена — no-op', $messages);
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

    public function testUnconfirmedTerminalJobUpdateFailsClosedForAppliedHeldAndBound(): void
    {
        foreach ([
            Contract::STATUS_APPLIED => Contract::REPORT_APPLIED,
            Contract::STATUS_HELD => Contract::REPORT_HELD,
            Contract::STATUS_BOUND => Contract::REPORT_BOUND,
        ] as $jobStatus => $terminalReport) {
            $this->jobsStub = new JobsEntityStub();
            $this->jobsStub->ignoredUpdateStatuses = [$jobStatus];
            $http = $this->createMock(SnapshotHttpClient::class);
            $http->method('fetchManifest')->willReturn($this->manifestJson(9));
            $downloader = $this->createMock(SnapshotDownloader::class);
            $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);
            $sentStatuses = [];
            $reportClient = $this->createMock(ReportClient::class);
            $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentStatuses): void {
                $sentStatuses[] = $args[3];
            });
            $errors = [];
            $logger = $this->createMock(LoggerInterface::class);
            $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
                $errors[] = $message;
            });

            $runner = $this->makeRunner(
                $this->settingsMock(),
                $http,
                $downloader,
                $reportClient,
                $this->lockMock(true),
                $this->applierMock($jobStatus),
                $logger
            );
            $runner->run();

            $this->assertNotContains($terminalReport, $sentStatuses, $jobStatus . ' report requires persisted job status');
            $this->assertContains(Contract::REPORT_FAILED, $sentStatuses, 'confirmed fallback failure is reported');
            $this->assertNotNull($this->jobsStub->lastUpdateWithStatus(Contract::STATUS_FAILED));
            $this->assertStringContainsString('job_id=100', implode(' ', $errors));
            $this->assertStringContainsString('actual=applying', implode(' ', $errors));
        }

        // If even the compensating failed update is not persisted, no terminal report is truthful.
        $this->jobsStub = new JobsEntityStub();
        $this->jobsStub->ignoredUpdateStatuses = [Contract::STATUS_APPLIED, Contract::STATUS_FAILED];
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(10));
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->method('download')->willReturn(Contract::STATUS_DOWNLOADED);
        $sentStatuses = [];
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->method('send')->willReturnCallback(static function (...$args) use (&$sentStatuses): void {
            $sentStatuses[] = $args[3];
        });
        $errors = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_APPLIED),
            $logger
        );
        $runner->run();

        $this->assertNotContains(Contract::REPORT_APPLIED, $sentStatuses);
        $this->assertNotContains(Contract::REPORT_FAILED, $sentStatuses);
        $this->assertGreaterThanOrEqual(2, count($errors), 'both unconfirmed writes are observable');
    }

    /**
     * A (живой дефект счётчика, замечен на канарейке Grundfos: прогон #3, applied, «файлов 0/5»).
     * `add()` заводит job с files_done=0, а успешный путь уходит в applyPhase() не записав его —
     * до этой правки применённый прогон НАВСЕГДА показывал 0, хотя файлы сверены. Перед вызовом
     * applyPhase() job обязан получить files_done = countVerified($jobId), тем же значением, что уже
     * пишут ветки failed/cancelled чуть выше по коду.
     *
     * KILL-ПРОБА: закомментировать новый `$jobsEntity->update($jobId, ['files_done' => $verified])`
     * перед вызовом applyPhase() → $filesDoneIndex не найдётся → тест красный (assertNotNull падает).
     * Проверено: мутация (закомментирована строка в SyncRunner.php) покрасила ИМЕННО этот тест,
     * `testNewVersionDownloadsThenAppliesAndMarksApplied` и весь остальной класс остались зелёными
     * (applied-статус пишется отдельным update() дальше и не зависит от этой строки).
     */
    public function testSuccessfulRunPersistsVerifiedFilesDoneBeforeApplyPhase(): void
    {
        $this->jobsStub->lastAppliedVersion = null;
        $manifest = $this->manifestJson(9, '1.0.0', ['files' => [
            ['name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1],
            ['name' => 'products-0002.ndjson.gz', 'sha256' => str_repeat('b', 64), 'bytes' => 10, 'rows' => 1],
        ]]);

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($manifest);

        // Реальный download() отмечает верифицированные файлы через JobFilesCheckpointStore (на
        // каждый файл сет-файл-статус verified) — здесь download() замокан, поэтому симулируем ЭТОТ
        // побочный эффект в колбэке колбэком, как и делает боевой код (после seedFiles()).
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects($this->once())->method('download')->willReturnCallback(
            function () {
                $this->jobFilesStub->setFileStatus(null, 'products-0001.ndjson.gz', Contract::FILE_VERIFIED);
                $this->jobFilesStub->setFileStatus(null, 'products-0002.ndjson.gz', Contract::FILE_VERIFIED);

                return Contract::STATUS_DOWNLOADED;
            }
        );

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->method('send');

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $downloader,
            $reportClient,
            $this->lockMock(true),
            $this->applierMock(Contract::STATUS_APPLIED)
        );
        $runner->run();

        $filesDoneIndex = null;
        $applyingIndex = null;
        foreach ($this->jobsStub->updateCalls as $i => $call) {
            $payload = $call[1];
            if (($payload['files_done'] ?? null) === 2 && !array_key_exists('status', $payload)) {
                $filesDoneIndex = $i;
            }
            if (($payload['status'] ?? null) === Contract::STATUS_APPLYING) {
                $applyingIndex = $i;
            }
        }

        $this->assertNotNull($filesDoneIndex, 'успешный прогон обязан записать files_done = countVerified() отдельным update() до applyPhase()');
        $this->assertNotNull($applyingIndex);
        $this->assertLessThan($applyingIndex, $filesDoneIndex, 'files_done обязан быть записан ДО фазы apply (applying)');
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

    public function testReachedUpdateCheckReportsLiveInstalledModuleIdentity(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $updater = $this->createMock(Updater::class);
        $updater->expects($this->once())->method('checkAndUpdate')
            ->with('https://core.example', '42', 'secret-token')
            ->willReturn('1.5.4');

        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->once())->method('sendInventory')
            ->with(
                'https://core.example',
                '42',
                'secret-token',
                Updater::MODULE_NAME,
                '1.5.4'
            );

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $this->createMock(SnapshotDownloader::class),
            $reportClient,
            $this->lockMock(true),
            null,
            null,
            $updater
        );
        $runner->run();
    }

    public function testInventoryFailureIsBoundedAndNextNoopTickRetries(): void
    {
        $this->jobsStub->lastAppliedVersion = 5;

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn($this->manifestJson(5));
        $updater = $this->createMock(Updater::class);
        $updater->expects($this->exactly(2))->method('checkAndUpdate')->willReturn('1.5.4');

        $inventoryCalls = 0;
        $reportClient = $this->createMock(ReportClient::class);
        $reportClient->expects($this->exactly(2))->method('sendInventory')
            ->willReturnCallback(static function () use (&$inventoryCalls): void {
                $inventoryCalls++;
                if ($inventoryCalls === 1) {
                    throw new \RuntimeException('secret-token-marker raw-body-marker');
                }
            });

        $warnings = [];
        $errors = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $runner = $this->makeRunner(
            $this->settingsMock(),
            $http,
            $this->createMock(SnapshotDownloader::class),
            $reportClient,
            $this->lockMock(true),
            null,
            $logger,
            $updater
        );

        $runner->run();
        $runner->run();

        $this->assertSame(2, $inventoryCalls, 'следующий естественный no-op тик повторяет inventory');
        $this->assertSame(['CoreSync: inventory-report не доставлен — повторит следующий тик'], $warnings);
        $this->assertSame([], $errors, 'best-effort отказ не красит sync и не логирует exception body');
        $this->assertSame([], $this->jobsStub->addCalls, 'оба snapshot no-op тика не меняют sync outcome');
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
