<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\ExchangeRetentionManager;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\ReportClient;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Core\Update\RetentionManager;
use Okay\Modules\Format\CoreSync\Core\Update\Updater;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\JobFilesEntityStub;
use Tests\Modules\Format\CoreSync\Support\JobsEntityStub;

require_once __DIR__ . '/Support/JobsEntityStub.php';
require_once __DIR__ . '/Support/JobFilesEntityStub.php';

/**
 * Настройка "запуск обмена" (coresync-trigger-module): `cron` по умолчанию, `core` только по пинку ядра.
 *
 * В режиме `core` тик крона ничего не делает (ни pull, ни обновления, ни уборки), а прогон, который
 * запускает пинок ядра фоновым процессом, это тот же {@see SyncRunner::run()}: та же версия снимка
 * догоняет отложенные документы, проверяет обновление модуля и делает уборку.
 */
class CoreTriggerRunTest extends TestCase
{
    /** @var JobsEntityStub */
    private $jobs;
    /** @var JobFilesEntityStub */
    private $jobFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = new JobsEntityStub();
        $this->jobFiles = new JobFilesEntityStub();
    }

    public function testRunTriggerDefaultsToCronAndUnknownValuesFallBackToCron(): void
    {
        $this->assertSame(Contract::RUN_TRIGGER_CRON, Contract::runTrigger(null));
        $this->assertSame(Contract::RUN_TRIGGER_CRON, Contract::runTrigger([]));
        $this->assertSame(Contract::RUN_TRIGGER_CRON, Contract::runTrigger(['run_trigger' => 'cron']));
        $this->assertSame(Contract::RUN_TRIGGER_CORE, Contract::runTrigger(['run_trigger' => 'core']));
        // Неизвестное значение не останавливает обмен: крон остаётся рабочим путём.
        $this->assertSame(Contract::RUN_TRIGGER_CRON, Contract::runTrigger(['run_trigger' => 'CORE ']));
        $this->assertSame(Contract::RUN_TRIGGER_CRON, Contract::runTrigger(['run_trigger' => ['core']]));
        $this->assertSame('run_trigger', Contract::SETTINGS_RUN_TRIGGER_FIELD);
        $this->assertSame(['cron', 'core'], Contract::RUN_TRIGGERS);
    }

    /**
     * Acceptance 2: в режиме `core` тик крона возвращает исход "отключено триггером" и не трогает ни
     * замок, ни ядро, ни обновление, ни уборку.
     * Kill-проба: убрать гейт в SyncRunner::runScheduled() -> красный.
     */
    public function testCronTickInCoreModeDoesNothing(): void
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects(self::never())->method('fetchManifest');
        $lock = $this->createMock(LockHelper::class);
        $lock->expects(self::never())->method('acquire');
        $updater = $this->createMock(Updater::class);
        $updater->expects(self::never())->method('checkAndUpdate');
        $retention = $this->createMock(RetentionManager::class);
        $retention->expects(self::never())->method('cleanup');
        $exchange = $this->createMock(ExchangeRetentionManager::class);
        $exchange->expects(self::never())->method('cleanupTick');
        $settings = $this->settings(['run_trigger' => 'core'], 1);
        $settings->expects(self::never())->method('set');

        $runner = $this->runner($settings, $http, $lock, $this->createMock(SnapshotDownloader::class),
            $this->createMock(Applier::class), $updater, $retention, $exchange);

        $this->assertSame(SyncRunner::OUTCOME_DISABLED_BY_TRIGGER, $runner->runScheduled());
    }

    /** Режим `cron` (явный и по умолчанию): тик крона это прежний run(). */
    public function testCronTickInCronModeRunsAsBefore(): void
    {
        foreach ([[], ['run_trigger' => 'cron']] as $override) {
            $this->jobs = new JobsEntityStub();
            $manifest = $this->manifest();
            $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
            $http = $this->createMock(SnapshotHttpClient::class);
            $http->expects(self::once())->method('fetchManifest')->willReturn((string) json_encode($manifest));
            $lock = $this->createMock(LockHelper::class);
            $lock->expects(self::once())->method('acquire')->willReturn(true);
            $applier = $this->createMock(Applier::class);
            $applier->method('deferredDocumentFiles')->willReturn([]);

            $runner = $this->runner($this->settings($override), $http, $lock,
                $this->createMock(SnapshotDownloader::class), $applier);

            $this->assertSame(SyncRunner::OUTCOME_STARTED, $runner->runScheduled());
        }
    }

    /**
     * Acceptance 4: пинок ядра по расписанию приходит с уже применённой версией. Фоновый прогон в
     * режиме `core` (это run()) догоняет отложенные документы той же версии, проверяет обновление
     * модуля, отправляет инвентарь и делает обе уборки, полного применения и нового job нет.
     * Kill-проба: в run() поставить ранний выход для режима `core` -> красный.
     */
    public function testCoreModeRunOfTheSameVersionCatchesUpDocumentsUpdatesAndCleansUp(): void
    {
        $manifest = $this->manifest();
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $this->jobs->appliedJob = (object) ['id' => 77, 'status' => Contract::STATUS_APPLIED];
        $deferred = [$manifest['files'][6]];

        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects(self::once())->method('fetchManifest')->willReturn((string) json_encode($manifest));
        $lock = $this->createMock(LockHelper::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::once())->method('download')
            ->with('https://core.example', '42', 'secret-token', $deferred, self::anything(),
                self::anything(), self::isType('callable'), 'store')
            ->willReturn(Contract::STATUS_DOWNLOADED);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('deferredDocumentFiles')->willReturn($deferred);
        $applier->expects(self::once())->method('applyDeferredDocuments')
            ->with($manifest, self::isType('string'), self::isInstanceOf(ApplyStats::class), 4, self::anything());
        $updater = $this->createMock(Updater::class);
        $updater->expects(self::once())->method('checkAndUpdate')
            ->with('https://core.example', '42', 'secret-token')->willReturn('1.9.0');
        $retention = $this->createMock(RetentionManager::class);
        $retention->expects(self::once())->method('cleanup')->willReturn(['errors' => []]);
        $exchange = $this->createMock(ExchangeRetentionManager::class);
        $exchange->expects(self::once())->method('cleanupTick')->willReturn([]);
        $report = $this->createMock(ReportClient::class);
        $report->expects(self::never())->method('send');
        $report->expects(self::once())->method('sendInventory')
            ->with('https://core.example', '42', 'secret-token', Updater::MODULE_NAME, '1.9.0');

        $runner = $this->runner($this->settings(['run_trigger' => 'core']), $http, $lock, $downloader,
            $applier, $updater, $retention, $exchange, $report);

        $this->assertSame(SyncRunner::OUTCOME_STARTED, $runner->run());
        $this->assertSame([], $this->jobs->addCalls, 'новый job не создаётся');
    }

    /**
     * Отложенный пинок (взведён, пока шёл прогон) в режиме `core` добирается тем же фоновым run(),
     * как и в режиме `cron`: семантика pending не меняется.
     */
    public function testCoreModeRunDrainsPendingPing(): void
    {
        $manifest = $this->manifest();
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->expects(self::exactly(2))->method('fetchManifest')->willReturn((string) json_encode($manifest));
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(true);
        $applier = $this->createMock(Applier::class);
        $applier->method('deferredDocumentFiles')->willReturn([]);
        $settings = $this->settings(['run_trigger' => 'core'], 1);
        $settings->expects(self::once())->method('set')->with(Contract::SETTINGS_PING_PENDING_KEY, 0);

        $runner = $this->runner($settings, $http, $lock, $this->createMock(SnapshotDownloader::class), $applier);

        $this->assertSame(SyncRunner::OUTCOME_STARTED, $runner->run());
    }

    /**
     * F2: отметку pending ставит ДРУГОЙ процесс уже после старта прогона (пинок во время прогона,
     * проигравший coresync:run). Settings Okay кэширует таблицу с конструктора, поэтому держатель
     * замка перечитывает настройки перед проверкой флага и обслуживает пинок добор-прогоном.
     * Kill-проба: убрать initSettings() в consumePingPending() -> красный.
     */
    public function testPendingSetByAnotherProcessAfterStartIsServed(): void
    {
        $manifest = $this->manifest();
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $cfg = [
            'core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret-token',
            'source_instance' => 'store-fixture', 'run_trigger' => 'core',
        ];
        $db = (object) ['pending' => 0];
        $cache = (object) ['pending' => 0];
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($cfg, $cache) {
            if ($key === Contract::SETTINGS_KEY) {
                return $cfg;
            }

            return $key === Contract::SETTINGS_PING_PENDING_KEY ? $cache->pending : null;
        });
        $settings->method('initSettings')->willReturnCallback(static function () use ($db, $cache): void {
            $cache->pending = $db->pending;
        });
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use ($db, $cache): void {
            if ($key === Contract::SETTINGS_PING_PENDING_KEY) {
                $db->pending = (int) $value;
                $cache->pending = (int) $value;
            }
        });
        $fetches = 0;
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturnCallback(static function () use (&$fetches, $db, $manifest): string {
            $fetches++;
            if ($fetches === 1) {
                $db->pending = 1; // пинок другого процесса во время первого прогона
            }

            return (string) json_encode($manifest);
        });
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(true);
        $applier = $this->createMock(Applier::class);
        $applier->method('deferredDocumentFiles')->willReturn([]);

        $runner = $this->runner($settings, $http, $lock, $this->createMock(SnapshotDownloader::class), $applier);

        $this->assertSame(SyncRunner::OUTCOME_STARTED, $runner->run());
        $this->assertSame(2, $fetches, 'прогон + добор пинка, поставленного извне');
        $this->assertSame(0, $db->pending, 'флаг потреблён в БД');
    }

    /**
     * @param array<string, mixed> $override
     * @return Settings&MockObject
     */
    private function settings(array $override, int $pending = 0): MockObject
    {
        $state = (object) ['pending' => $pending];
        $cfg = array_merge([
            'core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret-token',
            'source_instance' => 'store-fixture',
        ], $override);
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($cfg, $state) {
            if ($key === Contract::SETTINGS_KEY) {
                return $cfg;
            }
            if ($key === Contract::SETTINGS_PING_PENDING_KEY) {
                $value = $state->pending;
                $state->pending = 0;

                return $value;
            }

            return null;
        });

        return $settings;
    }

    private function runner(
        MockObject $settings,
        MockObject $http,
        MockObject $lock,
        MockObject $downloader,
        MockObject $applier,
        ?MockObject $updater = null,
        ?MockObject $retention = null,
        ?MockObject $exchange = null,
        ?MockObject $report = null
    ): SyncRunner {
        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        $images->method('countRetryableFailed')->willReturn(0);
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->method('countByState')->willReturn(array_fill_keys(Contract::IMAGE_STATES, 0));
        $categoryImages->method('countRetryableFailed')->willReturn(0);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) use ($images, $categoryImages) {
            switch ($class) {
                case CoreSyncJobsEntity::class: return $this->jobs;
                case CoreSyncJobFilesEntity::class: return $this->jobFiles;
                case CoreSyncImagesEntity::class: return $images;
                case CoreSyncCategoryImagesEntity::class: return $categoryImages;
            }
            throw new \InvalidArgumentException('Unexpected entity: ' . $class);
        });
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $key) {
            return $key === 'root_dir' ? sys_get_temp_dir() . '/' : null;
        });

        return new SyncRunner(
            $settings,
            $http,
            new ManifestValidator(),
            $downloader,
            $report ?? $this->createMock(ReportClient::class),
            $applier,
            $factory,
            $lock,
            $config,
            null,
            $updater,
            null,
            $retention,
            $exchange
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(
            __DIR__ . '/fixtures/golden-documents-store/store/manifest.json'
        ), true);
    }
}
