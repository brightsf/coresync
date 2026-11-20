<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Languages;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentSynchronizer;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\InMemoryDocumentStoreRepository;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';
require_once __DIR__ . '/Support/InMemoryDocumentStoreRepository.php';

/**
 * Применение golden-пакетов ядра product_documents_store 1.0.0 через Applier: full и price_stock
 * пишут документы в своё хранилище, повтор без изменений ничего не делает, disabled убирает своё,
 * манифест без ключа идёт старым путём ProductDocumentSynchronizer.
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

    /** Без ключа манифеста документы идут прежним путём, хранилище не трогается. */
    public function testManifestWithoutKeyUsesLegacySynchronizer(): void
    {
        $env = $this->env();
        $legacy = $this->createMock(ProductDocumentSynchronizer::class);
        $legacy->expects($this->exactly(3))->method('reconcile');
        $this->inject($env, 'productDocumentSynchronizer', $legacy);
        $this->stageGolden('disabled');
        $manifest = $this->manifest('disabled');
        unset($manifest['product_documents']);

        [$status] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $this->repo->writes);
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
}
