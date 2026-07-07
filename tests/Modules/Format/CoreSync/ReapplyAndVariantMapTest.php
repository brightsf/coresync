<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Variant-grain карта (M3 §0.1): full наполняет entity_type=variant строки; и «полное перепринятие»
 * (M3 §4): сброс applied_hash → следующий прогон переприменяет всё той же версией (update, не skip).
 */
class ReapplyAndVariantMapTest extends TestCase
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

    public function testFullApplyPopulatesVariantMapRows(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'p1', 'h1', [
                $this->variant('v1', 'SKU-A', '10.00', 3),
                $this->variant('v2', 'SKU-B', '20.00', 4),
            ]),
        ]);

        $this->runApply($env, $this->productsManifest());

        $variantRows = $env->map->find(['entity_type' => 'variant']);
        $this->assertCount(2, $variantRows, 'full наполняет variant-карту');
        $v1 = $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1']);
        $this->assertNotFalse($v1);
        $this->assertNotNull($v1->local_id, 'variant-строка ссылается на local variant id');
        $this->assertNotNull($v1->applied_hash, 'full проставляет per-variant hash');
    }

    public function testResetAppliedHashCausesFullReapplySameVersion(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'p1', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 3)]),
            $this->productLine('2', 'p2', 'h2', [$this->variant('v2', 'SKU-B', '20.00', 4)]),
        ]);

        [$status1, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_APPLIED, $status1);
        $this->assertSame(2, $stats1->upserted);

        // Идемпотентный повтор без сброса → всё skip.
        [, $statsNoReset] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(2, $statsNoReset->skipped, 'без сброса — skip');
        $this->assertSame(0, $statsNoReset->updated);

        // «Полное перепринятие»: сброс applied_hash всех строк карты (эффект resetForReapply).
        foreach ($env->map->rows as $id => $row) {
            $env->map->rows[$id]['applied_hash'] = null;
        }

        [$status3, $stats3] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status3);
        $this->assertSame(0, $stats3->skipped, 'после сброса ничего не пропускается');
        $this->assertSame(2, $stats3->updated, 'все товары переприменены той же версией');
        $this->assertSame(0, $stats3->upserted, 'ничего не создаётся заново (записи существуют)');
    }
}
