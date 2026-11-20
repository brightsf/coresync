<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\ReportClient;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
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
 * SyncRunner и документы store: режим манифеста уходит в загрузчик (старая форма без ключа качает
 * как раньше), а отложенные лимитом файлы применённой версии догоняются той же версией снимка без
 * повторного применения каталога.
 */
class SyncRunnerDocumentsTest extends TestCase
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

    public function testNewStoreVersionPassesStoreModeToDownloader(): void
    {
        $this->assertSame('store', $this->downloaderModeFor($this->manifest('store')));
    }

    /** Kill-проба: SyncRunner передаёт store и для старой формы -> красный. */
    public function testLegacyManifestPassesNoModeToDownloader(): void
    {
        $manifest = $this->manifest('store');
        unset($manifest['product_documents']);
        $manifest['files'] = [[
            'name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1,
        ]];

        $this->assertNull($this->downloaderModeFor($manifest));
    }

    /**
     * Та же применённая версия, у которой остались отложенные файлы: качаются только они (режим
     * store), документы довязываются, полного применения и нового job нет.
     * Kill-проба: убрать догон из ветки NOOP -> красный.
     */
    public function testSameVersionCatchesUpDeferredDocumentsOnly(): void
    {
        $manifest = $this->manifest('store');
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $this->jobs->appliedJob = (object) ['id' => 77, 'status' => Contract::STATUS_APPLIED];
        $deferred = [$manifest['files'][6]];
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::once())->method('download')
            ->with('https://core.example', '42', 'secret-token', $deferred,
                self::stringEndsWith('/files/coresync/' . $manifest['snapshot_version']),
                self::anything(), self::isType('callable'), 'store')
            ->willReturn(Contract::STATUS_DOWNLOADED);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::once())->method('deferredDocumentFiles')->willReturn($deferred);
        $applier->expects(self::once())->method('applyDeferredDocuments')
            ->with($manifest, self::isType('string'), self::isInstanceOf(ApplyStats::class), 4);
        $report = $this->createMock(ReportClient::class);
        $report->expects(self::never())->method('send');

        $this->runner($manifest, $downloader, $applier, $report)->run();

        $this->assertSame([], $this->jobs->addCalls, 'новый job не создаётся');
        $this->assertSame(
            [['snapshot_version' => $manifest['snapshot_version'], 'status' => Contract::STATUS_APPLIED]],
            $this->jobs->findOneFilters
        );
    }

    public function testSameVersionWithoutDeferredDocumentsStaysNoop(): void
    {
        $manifest = $this->manifest('store');
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::once())->method('deferredDocumentFiles')->willReturn([]);
        $applier->expects(self::never())->method('applyDeferredDocuments');
        $applier->expects(self::never())->method('apply');

        $this->runner($manifest, $downloader, $applier, $this->createMock(ReportClient::class))->run();

        $this->assertSame([], $this->jobs->addCalls);
    }

    public function testSameLegacyVersionDoesNotLookForDeferredDocuments(): void
    {
        $manifest = $this->manifest('store');
        unset($manifest['product_documents']);
        $manifest['files'] = [[
            'name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1,
        ]];
        $this->jobs->lastAppliedVersion = $manifest['snapshot_version'];
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::never())->method('download');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('deferredDocumentFiles');
        $applier->expects(self::never())->method('applyDeferredDocuments');

        $this->runner($manifest, $downloader, $applier, $this->createMock(ReportClient::class))->run();
    }

    /** @param array<string, mixed> $manifest */
    private function downloaderModeFor(array $manifest): ?string
    {
        $mode = 'not-called';
        $downloader = $this->createMock(SnapshotDownloader::class);
        $downloader->expects(self::once())->method('download')
            ->willReturnCallback(static function (...$args) use (&$mode): string {
                $mode = $args[7] ?? null;

                return Contract::STATUS_DOWNLOADED;
            });
        $applier = $this->createMock(Applier::class);
        $applier->method('apply')->willReturn(Contract::STATUS_APPLIED);

        $this->runner($manifest, $downloader, $applier, $this->createMock(ReportClient::class))->run();

        return $mode;
    }

    /** @param array<string, mixed> $manifest */
    private function runner(array $manifest, MockObject $downloader, MockObject $applier, MockObject $report): SyncRunner
    {
        $http = $this->createMock(SnapshotHttpClient::class);
        $http->method('fetchManifest')->willReturn((string) json_encode($manifest));
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) {
            return $key === Contract::SETTINGS_KEY ? [
                'core_url' => 'https://core.example', 'channel_code' => '42', 'token' => 'secret-token',
                'source_instance' => 'store-fixture',
            ] : null;
        });
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
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(true);
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $key) {
            return $key === 'root_dir' ? sys_get_temp_dir() . '/' : null;
        });

        return new SyncRunner($settings, $http, new ManifestValidator(), $downloader, $report, $applier, $factory, $lock, $config);
    }

    /** @return array<string, mixed> */
    private function manifest(string $mode): array
    {
        return json_decode((string) file_get_contents(
            __DIR__ . '/fixtures/golden-documents-store/' . $mode . '/manifest.json'
        ), true);
    }
}
