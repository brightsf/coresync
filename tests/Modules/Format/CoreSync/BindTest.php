<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Bind-фаза (M3 §1): пустая карта + непустой каталог → связывание по SKU варианта, каталог НЕ пишется.
 * Конфликты (дубль SKU / варианты разъехались / SKU не найден) в счётчики. После bind: full обновляет
 * связанное, price_stock — только связанное.
 */
class BindTest extends TestCase
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

    /** Заселить «живой» каталог Okay: товар 100 с вариантами SKU-A/SKU-B (без external_id ядра). */
    private function seedCatalog(object $env): void
    {
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 5];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 100, 'sku' => 'SKU-B', 'external_id' => '', 'stock' => 3];
    }

    private function catalogMutations(object $env): int
    {
        return $env->prod->mutations() + $env->var->mutations() + $env->cat->mutations()
            + $env->brand->mutations() + $env->feat->mutations()
            + count($env->fv->addCalls) + $env->redir->mutations()
            + count($env->img->addCalls) + count($env->img->updateCalls) + count($env->img->deleteCalls);
    }

    public function testBindFillsMapBySkuWithZeroCatalogMutations(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status, 'пустая карта + непустой каталог → bind');
        // Каталог НЕ мутирован (нетавтологичная считалка реальных вызовов записи).
        $this->assertSame(0, $this->catalogMutations($env), 'bind не пишет в каталог');

        // Карта заполнена: product '1' → 100, variants v1→1, v2→2, applied_hash=NULL.
        $productRow = $env->map->findOne(['entity_type' => 'product', 'external_id' => '1']);
        $this->assertNotFalse($productRow);
        $this->assertSame(100, (int) $productRow->local_id);
        $this->assertNull($productRow->applied_hash, 'bind: applied_hash=NULL (связано, не применялось)');
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1'])->local_id);
        $this->assertSame(2, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v2'])->local_id);

        $this->assertSame(3, $stats->bound, '1 товар + 2 варианта связаны');
        $this->assertSame(0, $stats->unmatched);
        $this->assertSame(0, $stats->conflicts);
    }

    public function testUnmatchedSkuCounted(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v9', 'SKU-NOPE', '10.00', 1)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(0, $stats->bound);
        $this->assertSame(1, $stats->unmatched, 'SKU не найден в каталоге');
        $this->assertContains('SKU-NOPE', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'несвязанный товар не в карте');
    }

    public function testDuplicateSkuIsConflict(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        // Дубль SKU в каталоге (два варианта с одинаковым SKU-DUP).
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-DUP', 'external_id' => '', 'stock' => 1];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 101, 'sku' => 'SKU-DUP', 'external_id' => '', 'stock' => 1];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-DUP', '10.00', 1)]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->bound);
        $this->assertSame(1, $stats->conflicts, 'дубль SKU → конфликт');
        $this->assertNotEmpty($stats->conflictSamples);
    }

    public function testVariantsSpreadAcrossProductsIsConflict(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'p100', 'external_id' => ''];
        $env->prod->rows[200] = ['id' => 200, 'url' => 'p200', 'external_id' => ''];
        // SKU-A принадлежит товару 100, SKU-B — товару 200 (варианты одного снапшот-товара разъехались).
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 1];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 200, 'sku' => 'SKU-B', 'external_id' => '', 'stock' => 1];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '10.00', 1),
                $this->variant('v2', 'SKU-B', '20.00', 1),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->bound, 'разъехавшиеся варианты не связываются');
        $this->assertGreaterThanOrEqual(1, $stats->conflicts);
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']));
    }

    public function testAfterBindFullUpdatesLinkedAndCreatesUnlinked(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        // 1) bind.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        [$bindStatus] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_BOUND, $bindStatus);
        $varAddsAfterBind = count($env->var->addCalls);

        // 2) full той же версии: карта непуста → full. Связанный товар обновляется, несвязанный — создаётся.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1499.00', 4),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
            $this->productLine('2', 'tablet', 'h2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);
        [$fullStatus, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $fullStatus);
        // Связанный товар 1 обновлён (bind оставил applied_hash=NULL).
        $this->assertSame(1, $stats->updated, 'связанный товар обновлён');
        // Несвязанный товар 2 создан.
        $this->assertSame(1, $stats->upserted, 'несвязанный товар создан');
        // Связанные варианты обновлены ЧЕРЕЗ variant-карту (id 1/2), а не пере-созданы.
        $this->assertTrue($this->hasUpdateFor($env->var, 1), 'связанный вариант v1 (id1) обновлён');
        $this->assertTrue($this->hasUpdateFor($env->var, 2), 'связанный вариант v2 (id2) обновлён');
        // Новый вариант v3 несвязанного товара создан (ровно один новый add).
        $this->assertSame($varAddsAfterBind + 1, count($env->var->addCalls), 'создан только новый вариант v3');
    }

    public function testAfterBindPriceStockUpdatesOnlyLinkedNotCreatesUnlinked(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        // bind.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $varAddsAfterBind = count($env->var->addCalls);

        // price_stock: связанное обновляется, несвязанный товар 2 — НЕ создаётся.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1400.00', 2),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
            $this->productLine('2', 'tablet', 'h2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->skippedNewProducts, 'несвязанный товар 2 не создан (счётчик)');
        $this->assertSame($varAddsAfterBind, count($env->var->addCalls), 'price_stock ничего не создаёт');
        $this->assertGreaterThanOrEqual(1, $stats->updated, 'связанный вариант обновлён');
    }

    private function hasUpdateFor($var, int $id): bool
    {
        foreach ($var->updateCalls as $call) {
            if ((int) $call[0] === $id) {
                return true;
            }
        }

        return false;
    }
}
