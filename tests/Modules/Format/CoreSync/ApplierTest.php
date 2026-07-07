<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BrandsEntityStub;
use Tests\Modules\Format\CoreSync\Support\CategoriesEntityStub;
use Tests\Modules\Format\CoreSync\Support\FeaturesEntityStub;
use Tests\Modules\Format\CoreSync\Support\FeaturesValuesEntityStub;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;
use Tests\Modules\Format\CoreSync\Support\MapEntityStub;
use Tests\Modules\Format\CoreSync\Support\ProductsEntityStub;
use Tests\Modules\Format\CoreSync\Support\RedirectsEntityStub;
use Tests\Modules\Format\CoreSync\Support\VariantsEntityStub;

// Нет PSR-4 автозагрузки для Tests\ — подключаем стабы явно (паттерн APIImport).
require_once __DIR__ . '/Support/ApplierStubs.php';
require_once __DIR__ . '/Support/InMemoryCheckpointStore.php';

/**
 * Интеграция Applier против golden-набора и синтетических снапшотов на in-memory стаб-сущностях
 * (реальный NdjsonGzReader + реальные .gz-файлы staging). Проверяет: порядок фаз, счётчики,
 * идемпотентность (0 мутаций 2-го прогона), точечный update, мутацию slug ядром, currency-гейт,
 * absent (оба режима + порог held), исчезнувший/новый вариант, kill-resume по чекпоинту файла.
 */
class ApplierTest extends TestCase
{
    /** @var string */
    private $stagingDir;
    /** @var list<string> */
    private $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingDir = $this->newStagingDir();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    // ----------------------------------------------------------------- env

    /**
     * @param array<string, int> $currencyMap
     * @return object окружение прогона (стабы + applier)
     */
    private function env(array $currencyMap = ['UAH' => 7]): object
    {
        $map = new MapEntityStub();
        $cat = new CategoriesEntityStub();
        $brand = new BrandsEntityStub();
        $feat = new FeaturesEntityStub();
        $fv = new FeaturesValuesEntityStub();
        $prod = new ProductsEntityStub();
        $var = new VariantsEntityStub();
        $redir = new RedirectsEntityStub();

        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static function (string $class) use ($map, $cat, $brand, $feat, $fv, $prod, $var, $redir) {
            switch ($class) {
                case CoreSyncMapEntity::class: return $map;
                case CategoriesEntity::class: return $cat;
                case BrandsEntity::class: return $brand;
                case FeaturesEntity::class: return $feat;
                case FeaturesValuesEntity::class: return $fv;
                case ProductsEntity::class: return $prod;
                case VariantsEntity::class: return $var;
                case Applier::REDIRECTS_ENTITY_CLASS: return $redir;
            }
            throw new \InvalidArgumentException('Unexpected entity: ' . $class);
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($currencyMap) {
            if ($key === Contract::SETTINGS_KEY) {
                return ['currency_map' => $currencyMap, 'lang_id' => 1];
            }
            if ($key === 'product_routes_template__default') {
                return 'products';
            }
            if ($key === 'category_routes_template__default') {
                return 'catalog';
            }

            return null;
        });

        $applier = new Applier($factory, $settings, new NdjsonGzReader(), null, null);

        return (object) compact('map', 'cat', 'brand', 'feat', 'fv', 'prod', 'var', 'redir', 'applier');
    }

    private function applyRun(object $env, array $manifest, ?InMemoryCheckpointStore $checkpoints = null): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $manifest,
            $this->stagingDir,
            $checkpoints ?? new InMemoryCheckpointStore(),
            static function (): bool {
                return false;
            },
            $stats
        );

        return [$status, $stats];
    }

    // ----------------------------------------------------------------- golden

    public function testAppliesGoldenSnapshotInPhaseOrderWithCounts(): void
    {
        $this->stageGolden();
        $env = $this->env();

        [$status, $stats] = $this->applyRun($env, $this->goldenManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(8, $stats->upserted, '2 cat + 1 brand + 2 feat + 3 prod');
        $this->assertSame(0, $stats->updated);
        $this->assertSame(0, $stats->skipped);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(3, $stats->imagesPending, '2 категории с image_url + 1 товар с images');

        // Порядок фаз: категории применены раньше товаров (по журналу карты).
        $types = array_map(static function (array $r): string {
            return (string) $r['entity_type'];
        }, array_values($env->map->rows));
        $this->assertSame('category', $types[0], 'первой фазой применяются категории');
        $firstProduct = array_search('product', $types, true);
        $lastFeature = array_keys($types, 'feature', true);
        $this->assertGreaterThan(end($lastFeature), $firstProduct, 'товары применяются после словарей');

        // url диктует ядро — записан ЯВНО из slug.
        $product = $env->prod->findOne(['external_id' => '1']);
        $this->assertSame('telefon-x', $product->url);

        // Валюта варианта = замапленный currency_id (UAH → 7).
        $this->assertNotEmpty($env->var->rows);
        $anyVariant = reset($env->var->rows);
        $this->assertSame(7, (int) $anyVariant['currency_id']);
        $this->assertTrue($this->allVariantsStockAreExplicitInt($env->var), 'stock записан явным числом (0=0, не NULL)');
    }

    public function testIdempotentSecondRunZeroMutations(): void
    {
        $this->stageGolden();
        $env = $this->env();
        $manifest = $this->goldenManifest();

        $this->applyRun($env, $manifest);
        $before = $this->totalMutations($env);

        [$status2, $stats2] = $this->applyRun($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(8, $stats2->skipped, 'все строки словарей и товаров — skip');
        $this->assertSame(0, $stats2->upserted);
        $this->assertSame(0, $stats2->updated);
        $this->assertSame($before, $this->totalMutations($env), '2-й прогон не делает ни одной мутации Entity');
    }

    public function testSingleHashChangeCausesExactlyOneUpdate(): void
    {
        $this->stageGolden();
        $env = $this->env();
        $this->applyRun($env, $this->goldenManifest());
        $addsAfterFirst = count($env->prod->addCalls);

        // Меняем hash одной строки товара + поле (регенерируем products-файл).
        $products = $this->readGoldenLines('products-0001.ndjson');
        $products[0]['hash'] = str_repeat('f', 64);
        $products[0]['data']['name'] = 'Телефон X (обновлён)';
        $this->gzLines('products-0001.ndjson.gz', array_map(static function (array $l): string {
            return (string) json_encode($l);
        }, $products));

        [$status, $stats] = $this->applyRun($env, $this->goldenManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->updated, 'ровно один update');
        $this->assertSame(7, $stats->skipped, 'остальные 7 строк — skip');
        $this->assertSame($addsAfterFirst, count($env->prod->addCalls), 'новых товаров не создаётся');
        $this->assertCount(1, $env->prod->updateCalls, 'ровно один update товара');
    }

    public function testSlugMutationByCoreIsRowErrorNotMapped(): void
    {
        $this->stageGolden();
        $env = $this->env();
        // Ядро мутирует url товара telefon-x (коллизия) → строка-ошибка, не в карте.
        $env->prod->collisionSlugs = ['telefon-x'];

        [, $stats] = $this->applyRun($env, $this->goldenManifest());

        $this->assertGreaterThanOrEqual(1, $stats->errors, 'мутация slug засчитана как ошибка строки');
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'мутированная строка НЕ попадает в карту');
    }

    public function testCurrencyNotMappedFailsClosedBeforeProducts(): void
    {
        $this->stageGolden();
        $env = $this->env([]); // пустой currency_map — UAH не замаплена

        [$status] = $this->applyRun($env, $this->goldenManifest());

        $this->assertSame(Contract::STATUS_FAILED, $status, 'валюта без маппинга → fail-closed');
        $this->assertSame([], $env->prod->addCalls, 'товары не применяются');
        $this->assertSame([], $env->cat->addCalls, 'fail-closed ДО фазы products (ничего не применено)');
    }

    // ----------------------------------------------------------------- absent

    public function testAbsentOutOfStockDeactivatesVanishedProductVariants(): void
    {
        $env = $this->env();
        $env->map->add(['entity_type' => 'category', 'external_id' => '1', 'local_id' => 10, 'applied_hash' => 'x']);

        // Прогон A: 6 товаров, у каждого один вариант.
        $this->stageProducts($this->syntheticProducts(range(1, 6)));
        $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));
        $addsA = count($env->var->addCalls);

        // Прогон B: товар 6 исчез (absent 1/6 = 16.7% < 20% → out_of_stock применяется).
        $this->stageProducts($this->syntheticProducts(range(1, 5)));
        [$status, $stats] = $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->absentCount);
        $this->assertGreaterThanOrEqual(1, $stats->deactivated, 'absent-товар деактивирован');
        // Вариант absent-товара обнулён (stock=0), новых вариантов не добавлено.
        $this->assertSame($addsA, count($env->var->addCalls), 'absent не создаёт варианты');
        $this->assertTrue($this->hasStockZeroUpdate($env->var), 'у absent-товара вариант stock=0');
    }

    public function testAbsentHideModeHidesVanishedProducts(): void
    {
        $env = $this->env();
        $env->map->add(['entity_type' => 'category', 'external_id' => '1', 'local_id' => 10, 'applied_hash' => 'x']);

        $this->stageProducts($this->syntheticProducts(range(1, 6)));
        $this->applyRun($env, $this->productsOnlyManifest('hide'));

        $this->stageProducts($this->syntheticProducts(range(1, 5)));
        [$status, $stats] = $this->applyRun($env, $this->productsOnlyManifest('hide'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->absentCount);
        $this->assertTrue($this->hasVisibleZeroUpdate($env->prod), 'absent-товар скрыт (visible=0)');
    }

    public function testAbsentAboveThresholdHeldSkipsPhase(): void
    {
        $env = $this->env();
        $env->map->add(['entity_type' => 'category', 'external_id' => '1', 'local_id' => 10, 'applied_hash' => 'x']);

        // 3 товара применены, затем остаётся 2 → absent 1/3 = 33% > 20% → held (фаза absent пропущена).
        $this->stageProducts($this->syntheticProducts(range(1, 3)));
        $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));
        $varUpdatesBefore = count($env->var->updateCalls);
        $prodUpdatesBefore = count($env->prod->updateCalls);

        $this->stageProducts($this->syntheticProducts(range(1, 2)));
        [$status, $stats] = $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));

        $this->assertSame(Contract::STATUS_HELD, $status);
        $this->assertSame(1, $stats->absentCount);
        $this->assertSame(0, $stats->deactivated, 'при held фаза absent пропущена — деактиваций нет');
        $this->assertSame($varUpdatesBefore, count($env->var->updateCalls), 'варианты не тронуты');
        $this->assertSame($prodUpdatesBefore, count($env->prod->updateCalls), 'товары не скрыты');
    }

    // ----------------------------------------------------------------- variants

    public function testVanishedVariantStockZeroAndNewVariantCreated(): void
    {
        $env = $this->env();
        $env->map->add(['entity_type' => 'category', 'external_id' => '1', 'local_id' => 10, 'applied_hash' => 'x']);

        // Прогон 1: товар '1' с вариантами a, b.
        $this->gzLines('products-0001.ndjson.gz', [
            $this->productLine('1', 'p1', 'h1', [$this->variant('a', '5'), $this->variant('b', '3')]),
        ]);
        $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));
        $addsBefore = count($env->var->addCalls);

        // Прогон 2: hash товара изменён; вариант b исчез, добавлен c.
        $this->gzLines('products-0001.ndjson.gz', [
            $this->productLine('1', 'p1', 'h2', [$this->variant('a', '7'), $this->variant('c', '9')]),
        ]);
        [$status, $stats] = $this->applyRun($env, $this->productsOnlyManifest('out_of_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->updated, 'товар обновлён (hash изменился)');
        $this->assertSame($addsBefore + 1, count($env->var->addCalls), 'новый вариант c создан');
        $this->assertGreaterThanOrEqual(1, $stats->deactivated, 'исчезнувший вариант b деактивирован');
        $this->assertTrue($this->hasStockZeroUpdate($env->var), 'исчезнувший вариант → stock=0');
    }

    // ----------------------------------------------------------------- kill/resume

    public function testResumeSkipsAlreadyAppliedFiles(): void
    {
        $this->stageGolden();
        $checkpoints = new InMemoryCheckpointStore();

        // Прогон 1: применяет всё, помечает файлы applied в чекпоинте.
        $env1 = $this->env();
        [$status1] = $this->applyRun($env1, $this->goldenManifest(), $checkpoints);
        $this->assertSame(Contract::STATUS_APPLIED, $status1);
        $this->assertSame(Contract::FILE_APPLIED, $checkpoints->getStatus('products-0001.ndjson.gz'));

        // Прогон 2: СВЕЖИЕ стабы (пустая карта!) + ТОТ ЖЕ чекпоинт. Если resume работает — файлы
        // пропущены по чекпоинту (не по hash), значит 0 мутаций несмотря на пустую карту.
        $env2 = $this->env();
        [$status2] = $this->applyRun($env2, $this->goldenManifest(), $checkpoints);

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(0, $this->totalMutations($env2), 'already-applied файлы не переприменяются (resume по чекпоинту)');
    }

    // ----------------------------------------------------------------- redirects

    public function testRedirectsUpsertFullPageUrls(): void
    {
        $env = $this->env();
        $this->gzLines('redirects.ndjson.gz', [
            (string) json_encode([
                'external_id' => 'r1',
                'hash'        => str_repeat('c', 64),
                'data'        => ['entity_type' => 'product', 'old_slug' => 'old-phone', 'new_slug' => 'new-phone'],
            ]),
            (string) json_encode([
                'external_id' => 'r2',
                'hash'        => str_repeat('d', 64),
                'data'        => ['entity_type' => 'category', 'old_slug' => 'old-cat', 'new_slug' => 'new-cat'],
            ]),
        ]);

        $manifest = [
            'currency'      => 'UAH',
            'absent_policy' => 'out_of_stock',
            'files'         => [['name' => 'redirects.ndjson.gz']],
        ];
        [$status, $stats] = $this->applyRun($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(2, $stats->upserted);
        $productRedirect = $env->redir->findOne(['request_url' => '/products/old-phone']);
        $this->assertNotFalse($productRedirect, 'request_url — полный page-url товара');
        $this->assertSame('/products/new-phone', $productRedirect->result_url);
        $this->assertSame(301, (int) $productRedirect->status_code);
        $categoryRedirect = $env->redir->findOne(['request_url' => '/catalog/old-cat']);
        $this->assertNotFalse($categoryRedirect, 'request_url — полный page-url категории');
    }

    // ----------------------------------------------------------------- fixtures / helpers

    /**
     * @return array<string, mixed>
     */
    private function goldenManifest(): array
    {
        $path = __DIR__ . '/fixtures/golden/manifest.json';

        return json_decode((string) file_get_contents($path), true);
    }

    private function stageGolden(): void
    {
        foreach (['categories', 'brands', 'features', 'products-0001', 'redirects'] as $base) {
            $raw = (string) file_get_contents(__DIR__ . '/fixtures/golden/' . $base . '.ndjson');
            $lines = [];
            foreach (preg_split('/\r?\n/', $raw) as $line) {
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
            $this->gzLines($base . '.ndjson.gz', $lines);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readGoldenLines(string $file): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/golden/' . $file);
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (trim($line) !== '') {
                $out[] = json_decode($line, true);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $absentPolicy
     * @return array<string, mixed>
     */
    private function productsOnlyManifest(string $absentPolicy): array
    {
        return [
            'currency'      => 'UAH',
            'absent_policy' => $absentPolicy,
            'files'         => [['name' => 'products-0001.ndjson.gz']],
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function syntheticProducts(array $ids): array
    {
        $lines = [];
        foreach ($ids as $id) {
            $lines[] = $this->productLine((string) $id, 'p' . $id, 'h' . $id, [$this->variant('v' . $id, '100')]);
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function productLine(string $externalId, string $slug, string $hash, array $variants): string
    {
        return (string) json_encode([
            'external_id' => $externalId,
            'hash'        => str_pad($hash, 64, '0'),
            'data'        => [
                'name'              => 'P' . $externalId,
                'slug'              => $slug,
                'visible'           => true,
                'brand_external_id' => null,
                'categories'        => ['primary' => '1', 'additional' => []],
                'description_html'  => '',
                'seo'               => ['title' => '', 'description' => '', 'keywords' => ''],
                'images'            => [],
                'feature_values'    => [],
                'variants'          => $variants,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(string $externalId, string $stock): array
    {
        return [
            'external_id' => $externalId,
            'sku'         => 'SKU-' . $externalId,
            'price'       => ['amount' => '100.00', 'currency' => 'UAH'],
            'stock'       => (int) $stock,
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function stageProducts(array $lines): void
    {
        $this->gzLines('products-0001.ndjson.gz', $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function gzLines(string $name, array $lines): void
    {
        $gz = gzopen($this->stagingDir . '/' . $name, 'wb9');
        foreach ($lines as $line) {
            gzwrite($gz, $line . "\n");
        }
        gzclose($gz);
    }

    private function totalMutations(object $env): int
    {
        return $env->cat->mutations() + $env->brand->mutations() + $env->feat->mutations()
            + $env->prod->mutations() + $env->var->mutations()
            + count($env->fv->addCalls) + count($env->redir->addCalls) + count($env->redir->updateCalls)
            + count($env->cat->productCategories) + count($env->fv->productValues);
    }

    private function allVariantsStockAreExplicitInt(VariantsEntityStub $var): bool
    {
        foreach ($var->rows as $row) {
            if (!is_int($row['stock'])) {
                return false;
            }
        }

        return true;
    }

    private function hasStockZeroUpdate(VariantsEntityStub $var): bool
    {
        foreach ($var->updateCalls as $call) {
            if (array_key_exists('stock', $call[1]) && (int) $call[1]['stock'] === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasVisibleZeroUpdate(ProductsEntityStub $prod): bool
    {
        foreach ($prod->updateCalls as $call) {
            if (array_key_exists('visible', $call[1]) && (int) $call[1]['visible'] === 0) {
                return true;
            }
        }

        return false;
    }

    private function newStagingDir(): string
    {
        $dir = sys_get_temp_dir() . '/coresync_apply_' . uniqid('', true);
        mkdir($dir, 0775, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
