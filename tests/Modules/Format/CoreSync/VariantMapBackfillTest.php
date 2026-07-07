<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Apply\VariantMapBackfill;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\MapEntityStub;
use Tests\Modules\Format\CoreSync\Support\VariantsEntityStub;

require_once __DIR__ . '/Support/ApplierStubs.php';

/**
 * Миграционный досев variant-строк карты (M3 §0.1): по существующим product-строкам досоздаёт
 * variant-строки (DB external_id → local id, applied_hash=NULL). Идемпотентно.
 */
class VariantMapBackfillTest extends TestCase
{
    private function factory(MapEntityStub $map, VariantsEntityStub $var): EntityFactory
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static function (string $class) use ($map, $var) {
            if ($class === CoreSyncMapEntity::class) {
                return $map;
            }
            if ($class === VariantsEntity::class) {
                return $var;
            }
            throw new \InvalidArgumentException('Unexpected: ' . $class);
        });

        return $factory;
    }

    public function testBackfillCreatesVariantRowsForExistingProducts(): void
    {
        $map = new MapEntityStub();
        $var = new VariantsEntityStub();
        // 2 товара в карте, 0 variant-строк.
        $map->add(['entity_type' => 'product', 'external_id' => '1', 'local_id' => 100, 'applied_hash' => 'h']);
        $map->add(['entity_type' => 'product', 'external_id' => '2', 'local_id' => 200, 'applied_hash' => 'h']);
        // Витрина: варианты с DB external_id.
        $var->rows[1] = ['id' => 1, 'product_id' => 100, 'external_id' => 'v1', 'sku' => 'A'];
        $var->rows[2] = ['id' => 2, 'product_id' => 100, 'external_id' => 'v2', 'sku' => 'B'];
        $var->rows[3] = ['id' => 3, 'product_id' => 200, 'external_id' => 'v3', 'sku' => 'C'];
        $var->rows[4] = ['id' => 4, 'product_id' => 200, 'external_id' => '', 'sku' => 'D']; // без external_id — пропуск

        $created = (new VariantMapBackfill($this->factory($map, $var)))->run();

        $this->assertSame(3, $created, 'досеяны 3 variant-строки (пустой external_id пропущен)');
        $v1 = $map->findOne(['entity_type' => 'variant', 'external_id' => 'v1']);
        $this->assertNotFalse($v1);
        $this->assertSame(1, (int) $v1->local_id);
        $this->assertNull($v1->applied_hash, 'applied_hash=NULL (следующий прогон обновит)');
        $this->assertNotFalse($map->findOne(['entity_type' => 'variant', 'external_id' => 'v3']));
        $this->assertFalse($map->findOne(['entity_type' => 'variant', 'external_id' => '']));
    }

    public function testBackfillIsIdempotent(): void
    {
        $map = new MapEntityStub();
        $var = new VariantsEntityStub();
        $map->add(['entity_type' => 'product', 'external_id' => '1', 'local_id' => 100, 'applied_hash' => 'h']);
        $var->rows[1] = ['id' => 1, 'product_id' => 100, 'external_id' => 'v1', 'sku' => 'A'];

        $backfill = new VariantMapBackfill($this->factory($map, $var));
        $first = $backfill->run();
        $second = $backfill->run();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'повторный досев не дублирует variant-строки');
        $this->assertCount(1, $map->find(['entity_type' => 'variant']));
    }
}
