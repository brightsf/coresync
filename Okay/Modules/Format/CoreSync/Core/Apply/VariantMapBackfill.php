<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\EntityFactory;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;

/**
 * Миграционный досев variant-строк карты (line-item M3 §0.1): по существующим product-строкам карты
 * досоздаёт entity_type=variant строки (DB external_id варианта → local variant id, applied_hash=NULL).
 * Идемпотентно (пропускает уже существующие variant-строки). Запускается из Init::install() на
 * апгрейде M2→M3; на свежей установке (пустая карта) — no-op. bind/price_stock работают по этим строкам.
 */
class VariantMapBackfill
{
    /** @var EntityFactory */
    private $entityFactory;

    public function __construct(EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
    }

    /**
     * @return int число досозданных variant-строк карты
     */
    public function run(): int
    {
        /** @var CoreSyncMapEntity $map */
        $map = $this->entityFactory->get(CoreSyncMapEntity::class);
        /** @var VariantsEntity $variantsEntity */
        $variantsEntity = $this->entityFactory->get(VariantsEntity::class);

        $existingVariants = [];
        foreach ($map->findChecked(['entity_type' => Contract::ENTITY_VARIANT]) as $row) {
            $existingVariants[(string) $row->external_id] = true;
        }

        $created = 0;
        foreach ($map->findChecked(['entity_type' => Contract::ENTITY_PRODUCT]) as $productRow) {
            $localProductId = (int) $productRow->local_id;
            if ($localProductId <= 0) {
                continue;
            }
            foreach ($variantsEntity->find(['product_id' => $localProductId]) as $variantRow) {
                $external = (string) $variantRow->external_id;
                if ($external === '' || isset($existingVariants[$external])) {
                    continue;
                }
                $map->add([
                    'entity_type'  => Contract::ENTITY_VARIANT,
                    'external_id'  => $external,
                    'local_id'     => (int) $variantRow->id,
                    'applied_hash' => null,
                    'image_state'  => null,
                ]);
                $existingVariants[$external] = true;
                $created++;
            }
        }

        return $created;
    }
}
