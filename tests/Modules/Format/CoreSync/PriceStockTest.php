<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Режим price_stock (M3 §3): трогает ТОЛЬКО price/stock связанных вариантов (variant-карта); ничего
 * не создаёт (skipped_new_*); пропавший связанный → stock=0; порог >20% товаров → held; идемпотентность
 * по per-variant hash; пустая карта + непустой каталог → bind.
 */
class PriceStockTest extends TestCase
{
    use BuildsApplierEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initStaging();
    }

    protected function tearDown(): void
    {
        $this->cleanupStaging();
        parent::tearDown();
    }

    /** Каталог + карта: товар '1'→100 с вариантами v1→1, v2→2 (уже связаны). */
    private function seedLinked(object $env): void
    {
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => '1', 'name' => 'Original', 'visible' => 1];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => 'v1', 'price' => '1500.00', 'stock' => 5];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 100, 'sku' => 'SKU-B', 'external_id' => 'v2', 'price' => '1600.00', 'stock' => 3];
        $env->map->add(['entity_type' => 'product', 'external_id' => '1', 'local_id' => 100, 'applied_hash' => 'ph', 'image_state' => null]);
        $env->map->add(['entity_type' => 'variant', 'external_id' => 'v1', 'local_id' => 1, 'applied_hash' => 'old1', 'image_state' => null]);
        $env->map->add(['entity_type' => 'variant', 'external_id' => 'v2', 'local_id' => 2, 'applied_hash' => 'old2', 'image_state' => null]);
    }

    public function testOnlyPriceStockTouchedNegativeAsserts(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1400.00', 2),
                $this->variant('v2', 'SKU-B', '1600.00', 4),
            ], [$this->image('https://cdn/x.jpg', 'imghash', 0)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(2, $stats->updated, 'оба связанных варианта обновлены');

        // НЕГАТИВНЫЕ asserts: тронуты ТОЛЬКО price/stock/currency варианта.
        $this->assertSame([], $env->prod->updateCalls, 'контент/имя/visible товара НЕ трогаются');
        $this->assertSame([], $env->prod->addCalls);
        $this->assertSame([], $env->cat->addCalls, 'категории не трогаются');
        $this->assertSame(0, count($env->cat->productCategories), 'связи категорий не трогаются');
        $this->assertSame([], $env->feat->addCalls, 'характеристики не трогаются');
        $this->assertSame([], $env->fv->addCalls, 'значения характеристик не трогаются');
        $this->assertSame([], $env->fv->productValues);
        $this->assertSame([], $env->img->addCalls, 'картинки не качаются в price_stock');
        $this->assertSame([], $env->csimg->rows, 'durable-список картинок не наполняется');
        $this->assertSame(0, count($env->downloader->requested), 'фаза картинок не запускается');

        foreach ($env->var->updateCalls as $call) {
            $this->assertSame(['price', 'stock', 'currency_id'], array_keys($call[1]), 'у варианта меняются только price/stock/currency');
        }
    }

    public function testSkippedNewProductsAndVariantsNotCreated(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        $this->gz('products-0001.ndjson.gz', [
            // связанный товар с НОВЫМ вариантом v9 (существующего товара) — не создаём.
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1400.00', 2),
                $this->variant('v9', 'SKU-NEW', '10.00', 1),
            ]),
            // новый товар '2' — не создаём.
            $this->productLine('2', 'tablet', 'ph2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->skippedNewProducts, 'новый товар не создан');
        $this->assertSame(1, $stats->skippedNewVariants, 'новый вариант существующего товара не создан');
        $this->assertSame([], $env->var->addCalls, 'price_stock ничего не создаёт');
        $this->assertSame([], $env->prod->addCalls);
    }

    public function testVanishedLinkedVariantStockZeroed(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        // v2 пропал из снапшота → stock=0.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [$this->variant('v1', 'SKU-A', '1500.00', 5)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->stockZeroed, 'пропавший связанный вариант обнулён');
        $this->assertTrue($this->hasStockZeroFor($env->var, 2), 'вариант v2 (id2) → stock=0 явным нулём');
    }

    public function testThresholdOverTwentyPercentHeldSkipsZeroing(): void
    {
        $env = $this->buildEnv();
        // 5 связанных товаров, у каждого 1 вариант.
        for ($i = 1; $i <= 5; $i++) {
            $pid = 100 + $i;
            $vid = $i;
            $env->prod->rows[$pid] = ['id' => $pid, 'url' => 'p' . $i, 'external_id' => (string) $i];
            $env->var->rows[$vid] = ['id' => $vid, 'product_id' => $pid, 'sku' => 'SKU-' . $i, 'external_id' => 'v' . $i, 'price' => '100.00', 'stock' => 10];
            $env->map->add(['entity_type' => 'product', 'external_id' => (string) $i, 'local_id' => $pid, 'applied_hash' => 'p', 'image_state' => null]);
            $env->map->add(['entity_type' => 'variant', 'external_id' => 'v' . $i, 'local_id' => $vid, 'applied_hash' => 'o' . $i, 'image_state' => null]);
        }
        // Снапшот содержит только 3 товара → absent 2/5 = 40% > 20% → held.
        $lines = [];
        for ($i = 1; $i <= 3; $i++) {
            $lines[] = $this->productLine((string) $i, 'p' . $i, 'p', [$this->variant('v' . $i, 'SKU-' . $i, '90.00', 8)]);
        }
        $this->gz('products-0001.ndjson.gz', $lines);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_HELD, $status, 'absent >20% → held');
        $this->assertSame(0, $stats->stockZeroed, 'обнуление стока пропущено');
        $this->assertFalse($this->hasStockZeroFor($env->var, 4), 'вариант отсутствующего товара НЕ обнулён (held)');
        // Цены/стоки присутствующих применены.
        $this->assertSame(3, $stats->updated, 'цены/стоки присутствующих товаров применены');
    }

    public function testIdempotentSecondRunZeroMutations(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1400.00', 2),
                $this->variant('v2', 'SKU-B', '1600.00', 4),
            ]),
        ]);

        $this->runApply($env, $this->productsManifest('price_stock'));
        $updatesAfterFirst = count($env->var->updateCalls);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame($updatesAfterFirst, count($env->var->updateCalls), '2-й прогон той же версии — 0 мутаций вариантов');
        $this->assertSame(2, $stats->skipped, 'оба варианта — skip по per-variant hash');
        $this->assertSame(0, $stats->updated);
    }

    public function testExplicitNullStockIsWrittenAsNull(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1500.00', null),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);

        [$status] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertNull($env->var->rows[1]['stock'], 'price_stock writes strict null to entity');
        $this->assertSame(3, $env->var->rows[2]['stock']);
    }

    public function testHashDistinguishesZeroFromNullAndRepeatSkips(): void
    {
        $env = $this->buildEnv();
        $this->seedLinked($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1500.00', 0),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest('price_stock'));
        $updatesAfterZero = count($env->var->updateCalls);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [
                $this->variant('v1', 'SKU-A', '1500.00', null),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        [$status, $changed] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $changed->updated, '0 to null changes the per-variant hash');
        $this->assertSame(1, $changed->skipped, 'unchanged sibling remains skipped');
        $this->assertSame($updatesAfterZero + 1, count($env->var->updateCalls));
        $this->assertNull($env->var->rows[1]['stock']);

        $updatesAfterNull = count($env->var->updateCalls);
        [, $repeat] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(0, $repeat->updated, 'repeat null is idempotent');
        $this->assertSame(2, $repeat->skipped);
        $this->assertSame($updatesAfterNull, count($env->var->updateCalls), 'repeat null performs no entity update');
    }

    public function testEmptyMapNonEmptyCatalogBindsEvenInPriceStock(): void
    {
        $env = $this->buildEnv();
        // Каталог непуст, карта пуста → bind вместо price_stock-применения (§3).
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 5];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'ph', [$this->variant('v1', 'SKU-A', '1500.00', 5)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_BOUND, $status, 'пустая карта + непустой каталог → bind');
        $this->assertSame(2, $stats->bound, '1 товар + 1 вариант связаны');
    }

    private function hasStockZeroFor($var, int $id): bool
    {
        foreach ($var->updateCalls as $call) {
            if ((int) $call[0] === $id && array_key_exists('stock', $call[1]) && (int) $call[1]['stock'] === 0) {
                return true;
            }
        }

        return false;
    }
}
