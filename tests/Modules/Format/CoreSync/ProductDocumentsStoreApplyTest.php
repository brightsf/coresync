<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Languages;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;
use Tests\Modules\Format\CoreSync\Support\InMemoryDocumentStoreRepository;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';
require_once __DIR__ . '/Support/InMemoryDocumentStoreRepository.php';

/**
 * Применение golden-пакетов ядра product_documents_store 1.0.0 через Applier: full и price_stock
 * пишут документы в своё хранилище, повтор без изменений ничего не делает, disabled убирает своё,
 * манифест без ключа (старая форма) документы не применяет и предупреждает один раз за обмен.
 */
class ProductDocumentsStoreApplyTest extends TestCase
{
    use BuildsApplierEnv;

    private const SHA_A = '8e504a494dea96d9428d320880bde5acabe77353ff81da1f9615c732e36b4360';
    private const SHA_B = 'd2a3f0e786e5e929a1d27ef5ce8cb5732742e5098b7d3ac6180453647e03ee2f';

    /** @var string */
    private $root;
    /** @var InMemoryDocumentStoreRepository */
    private $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initStaging();
        $this->root = sys_get_temp_dir() . '/coresync_docapply_' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->tmpDirs[] = $this->root;
        $this->repo = new InMemoryDocumentStoreRepository($this->root);
    }

    protected function tearDown(): void
    {
        $this->cleanupStaging();
        parent::tearDown();
    }

    public function testFullStoreGoldenLinksReadyDocumentsAndReportsCounters(): void
    {
        $env = $this->env();
        $this->stageGolden('store');

        [$status, $stats] = $this->runApply($env, $this->manifest('store'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(['9001' => self::SHA_A], $this->repo->links('101'), '9002 pending: не привязан');
        $this->assertSame(['9003' => self::SHA_A, '9004' => self::SHA_B], $this->repo->links('102'));
        $this->assertSame([], $this->repo->links('103'));
        $this->assertFileExists($this->root . '/files/coresync/documents/' . self::SHA_A . '.pdf');
        $this->assertFileExists($this->root . '/files/coresync/documents/' . self::SHA_B . '.pdf');
        $report = $stats->toArray();
        $this->assertSame(3, $report['documents_linked']);
        $this->assertSame(1, $report['documents_pending']);
        $this->assertSame(2, $report['document_files_downloaded']);
        $this->assertSame(0, $report['document_files_deferred']);
        $this->assertSame(0, $report['documents_unlinked']);
        $this->assertSame(0, $report['document_files_removed']);
        $localIds = [];
        foreach ($this->repo->documents as $row) {
            $localIds[$row['product_external_id']] = $row['product_local_id'];
        }
        $mapped = $env->map->findOne(['entity_type' => 'product', 'external_id' => '101']);
        $this->assertSame((int) $mapped->local_id, $localIds['101']);
    }

    /** Acceptance 1: повтор того же снимка (force-reapply той же версии) ничего не качает и не меняет. */
    public function testRepeatedStoreSnapshotIsNoop(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        $this->runApply($env, $this->manifest('store'));
        $writes = $this->repo->writes;

        [$status, $stats] = $this->runApply($env, $this->manifest('store'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame($writes, $this->repo->writes);
        $this->assertSame(0, $stats->documentFilesDownloaded);
        $this->assertSame(0, $stats->documentsLinked + $stats->documentsUnlinked + $stats->documentFilesRemoved);
    }

    /** Acceptance 4: disabled поверх store убирает всё своё. */
    public function testDisabledAfterStoreRemovesStoreDocumentsAndFiles(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        $this->runApply($env, $this->manifest('store'));
        $this->stageGolden('disabled');

        [$status, $stats] = $this->runApply($env, $this->manifest('disabled'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $this->repo->documents);
        $this->assertSame([], $this->repo->files);
        $this->assertSame([], array_values(array_diff(scandir($this->root . '/files/coresync/documents'), ['.', '..'])));
        $this->assertSame(3, $stats->documentsUnlinked);
        $this->assertSame(2, $stats->documentFilesRemoved);
    }

    /** Список документов несётся и в price_stock: связанные товары получают документы. */
    public function testPriceStockAppliesStoreDocumentsToMappedProducts(): void
    {
        $env = $this->env();
        $this->stageGolden('disabled');
        $this->runApply($env, $this->manifest('disabled'));
        $this->stageGolden('store');
        $manifest = $this->manifest('store');
        $manifest['sync_mode'] = Contract::SYNC_MODE_PRICE_STOCK;

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(['9001' => self::SHA_A], $this->repo->links('101'));
        $this->assertSame(['9003' => self::SHA_A, '9004' => self::SHA_B], $this->repo->links('102'));
        $this->assertSame(3, $stats->documentsLinked);
    }

    /**
     * Старая форма от необновлённого ядра (в манифесте нет product_documents, у строк непустые
     * documents, PDF в staging не скачаны): снимок применяется, документы не применяются, хранилище
     * не трогается, в лог ровно одно предупреждение на обмен. Kill-проба: убрать игнор старой формы ->
     * красный.
     */
    public function testLegacyFormDocumentsAreIgnoredWithOneWarning(): void
    {
        $env = $this->env();
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $this->inject($env, 'logger', $logger);
        $manifest = $this->stageLegacyForm();

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(3, $stats->upserted);
        $this->assertSame(0, $this->repo->writes);
        $this->assertSame(0, $stats->documentsLinked);
        $legacy = array_values(array_filter($warnings, static function (string $message): bool {
            return strpos($message, 'старой формы') !== false;
        }));
        $this->assertCount(1, $legacy, implode("\n", $warnings));
        $this->assertStringContainsString('обновите ядро', $legacy[0]);
    }

    /** Старая форма без документов в строках: предупреждения нет. */
    public function testLegacyFormWithoutDocumentsDoesNotWarn(): void
    {
        $env = $this->env();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $this->inject($env, 'logger', $logger);
        $this->stageGolden('disabled');
        $manifest = $this->manifest('disabled');
        unset($manifest['product_documents']);

        [$status] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $this->repo->writes);
    }

    /**
     * Файл, отложенный лимитом, довязывается той же применённой версией: только документы, без
     * повторной записи товаров и карты. Kill-проба: догон без prepare (привязка до файла) -> красный.
     */
    public function testDeferredDocumentIsLinkedLaterWithoutReapplyingCatalog(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        @unlink($this->stagingDir . '/document-' . self::SHA_B . '.pdf'); // B не доехал в этот обмен
        $manifest = $this->manifest('store');

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->documentFilesDeferred);
        $this->assertSame(['9003' => self::SHA_A], $this->repo->links('102'));
        $deferred = $env->applier->deferredDocumentFiles($manifest, $this->stagingDir);
        $this->assertSame(['document-' . self::SHA_B . '.pdf'], array_column($deferred, 'name'));

        copy(__DIR__ . '/fixtures/golden-documents-store/store/document-' . self::SHA_B . '.pdf',
            $this->stagingDir . '/document-' . self::SHA_B . '.pdf');
        $products = $env->prod->rows;
        $variants = $env->var->rows;
        $map = $env->map->rows;
        $productMutations = $env->prod->mutations();
        $catchUp = new ApplyStats();

        $env->applier->applyDeferredDocuments($manifest, $this->stagingDir, $catchUp, 4);

        $this->assertSame(['9003' => self::SHA_A, '9004' => self::SHA_B], $this->repo->links('102'));
        $this->assertSame(['9001' => self::SHA_A], $this->repo->links('101'));
        $this->assertSame(1, $catchUp->documentFilesDownloaded);
        $this->assertSame(1, $catchUp->documentsLinked);
        $this->assertSame([], $env->applier->deferredDocumentFiles($manifest, $this->stagingDir));
        $this->assertSame($products, $env->prod->rows, 'товары не переприменялись');
        $this->assertSame($variants, $env->var->rows);
        $this->assertSame($map, $env->map->rows);
        $this->assertSame($productMutations, $env->prod->mutations());
    }

    /** Подменённый файл товаров в staging: догон отказывает до первой записи. */
    public function testDeferredCatchUpRefusesChangedStagingRows(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        @unlink($this->stagingDir . '/document-' . self::SHA_B . '.pdf');
        $manifest = $this->manifest('store');
        $this->runApply($env, $manifest);
        copy(__DIR__ . '/fixtures/golden-documents-store/disabled/products-0001.ndjson.gz',
            $this->stagingDir . '/products-0001.ndjson.gz');
        copy(__DIR__ . '/fixtures/golden-documents-store/store/document-' . self::SHA_B . '.pdf',
            $this->stagingDir . '/document-' . self::SHA_B . '.pdf');
        $writes = $this->repo->writes;

        $this->expectException(ManifestException::class);
        try {
            $env->applier->applyDeferredDocuments($manifest, $this->stagingDir,
                new ApplyStats(), 4);
        } finally {
            $this->assertSame($writes, $this->repo->writes);
        }
    }

    /**
     * HIGH приёмки: price_stock, товаров пакета на витрине нет (skipped_new_products). Их файлы не
     * переносятся, не убираются и не считаются отложенными, чекпоинты документов закрыты applied,
     * и следующий тик той же версии ничего не качает. Kill-проба: нужные файлы без фильтра по
     * связанным товарам -> красный.
     */
    public function testPriceStockWithUnlinkedProductsNeverRedownloadsTheirDocuments(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        $manifest = $this->manifest('store');
        $manifest['sync_mode'] = Contract::SYNC_MODE_PRICE_STOCK;
        $checkpoints = new InMemoryCheckpointStore();

        $this->assertSame([], $env->applier->neededDocumentFiles($manifest, $this->stagingDir), 'основной обмен их не качает');
        [$status, $stats] = $this->runApply($env, $manifest, $checkpoints);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(3, $stats->skippedNewProducts);
        $this->assertSame(0, $stats->documentFilesDownloaded);
        $this->assertSame(0, $stats->documentFilesRemoved);
        $this->assertSame(0, $stats->documentFilesDeferred);
        $this->assertSame([], $this->repo->files);
        $this->assertSame([], $env->applier->deferredDocumentFiles($manifest, $this->stagingDir), 'NOOP-тик ничего не качает');
        foreach ([self::SHA_A, self::SHA_B] as $sha) {
            $this->assertSame(Contract::FILE_APPLIED, $checkpoints->getStatus('document-' . $sha . '.pdf'));
        }
    }

    /** Связанный потом товар делает свои файлы нужными: догон той же версии их докачивает. */
    public function testProductLinkedLaterMakesItsDocumentsDeferred(): void
    {
        $env = $this->env();
        $this->stageGolden('disabled');
        $this->runApply($env, $this->manifest('disabled'));
        $this->stageGolden('store');
        $manifest = $this->manifest('store');
        $manifest['sync_mode'] = Contract::SYNC_MODE_PRICE_STOCK;
        foreach (array_keys($env->map->rows) as $id) {
            if ($env->map->rows[$id]['entity_type'] === 'product' && $env->map->rows[$id]['external_id'] === '102') {
                unset($env->map->rows[$id]);
            }
        }

        $this->assertSame(['document-' . self::SHA_A . '.pdf'],
            array_column($env->applier->neededDocumentFiles($manifest, $this->stagingDir), 'name'), 'только 101');
    }

    /** Чекпоинты документов store: перенесённый applied, отложенный нужный остаётся pending. */
    public function testStoreCheckpointsMarkMovedDocumentsAppliedAndKeepDeferredPending(): void
    {
        $env = $this->env();
        $this->stageGolden('store');
        @unlink($this->stagingDir . '/document-' . self::SHA_B . '.pdf');
        $checkpoints = new InMemoryCheckpointStore();
        $checkpoints->setStatus('document-' . self::SHA_B . '.pdf', Contract::FILE_PENDING);

        $this->runApply($env, $this->manifest('store'), $checkpoints);

        $this->assertSame(Contract::FILE_APPLIED, $checkpoints->getStatus('document-' . self::SHA_A . '.pdf'));
        $this->assertSame(Contract::FILE_PENDING, $checkpoints->getStatus('document-' . self::SHA_B . '.pdf'));
    }

    /** Без хранилища store-манифест падает до записи первого товара. */
    public function testStoreModeWithoutStoreServiceFailsBeforeWrites(): void
    {
        $env = $this->env(false);
        $this->stageGolden('store');

        try {
            $this->runApply($env, $this->manifest('store'));
            $this->fail('без хранилища store-манифест не применяется');
        } catch (ManifestException $e) {
            $this->assertStringContainsString('хранилище документов', $e->getMessage());
        }
        $this->assertSame([], $env->prod->rows, 'ни одного товара не создано');
        $this->assertEmpty($env->map->findOne(['entity_type' => 'product', 'external_id' => '101']));
    }

    private function env(bool $withStore = true): object
    {
        $currentLanguageId = 1;
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn([(object) ['id' => 1, 'href_lang' => 'ru']]);
        $languages->method('getLangId')->willReturnCallback(static function () use (&$currentLanguageId): int {
            return $currentLanguageId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use (&$currentLanguageId): void {
            $currentLanguageId = (int) $id;
        });
        $env = $this->buildEnv(['RUB' => 7], $languages);
        if ($withStore) {
            $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
            $root = $this->root;
            $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
                return $key === 'root_dir' ? $root : null;
            });
            $settings = $this->createMock(Settings::class);
            $settings->method('get')->willReturn([]);
            $this->inject($env, 'productDocumentStore',
                new ProductDocumentStore($this->repo, new ProductDocumentStoreFiles($config), $settings));
        }

        return $env;
    }

    /** @param mixed $value */
    private function inject(object $env, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($env->applier, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($env->applier, $value);
    }

    private function stageGolden(string $mode): void
    {
        $source = __DIR__ . '/fixtures/golden-documents-store/' . $mode;
        foreach (scandir($source) ?: [] as $name) {
            if (substr($name, -3) === '.gz' || substr($name, -4) === '.pdf') {
                copy($source . '/' . $name, $this->stagingDir . '/' . $name);
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(string $mode): array
    {
        return json_decode((string) file_get_contents(
            __DIR__ . '/fixtures/golden-documents-store/' . $mode . '/manifest.json'
        ), true);
    }

    /**
     * Пакет старой формы V4 из golden store: ключа product_documents нет, у строк документы прежнего
     * вида (только ready, без state), файлов PDF в staging нет (CoreSync 1.9.1 их не качает).
     *
     * @return array<string, mixed>
     */
    private function stageLegacyForm(): array
    {
        $source = __DIR__ . '/fixtures/golden-documents-store/store';
        $lines = [];
        foreach (file($source . '/products.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $line = json_decode($raw, true);
            $documents = [];
            foreach ($line['data']['documents'] as $document) {
                if ($document['state'] === Contract::PRODUCT_DOCUMENT_STATE_READY) {
                    unset($document['state']);
                    $documents[] = $document;
                }
            }
            $line['data']['documents'] = $documents;
            $lines[] = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->assertNotSame([], array_filter($lines, static function (string $line): bool {
            return strpos($line, '"documents":[]') === false;
        }), 'фикстура старой формы должна нести документы');
        $gz = gzencode(implode("\n", $lines) . "\n");
        foreach (scandir($source) ?: [] as $name) {
            if (substr($name, -3) === '.gz') {
                copy($source . '/' . $name, $this->stagingDir . '/' . $name);
            }
        }
        file_put_contents($this->stagingDir . '/products-0001.ndjson.gz', $gz);
        $manifest = $this->manifest('store');
        unset($manifest['product_documents']);
        foreach ($manifest['files'] as &$file) {
            if ($file['name'] === 'products-0001.ndjson.gz') {
                $file['sha256'] = hash('sha256', $gz);
                $file['bytes'] = strlen($gz);
            }
        }
        unset($file);

        return $manifest;
    }
}
