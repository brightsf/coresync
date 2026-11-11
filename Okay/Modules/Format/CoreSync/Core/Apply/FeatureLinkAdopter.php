<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Claims explicitly reviewed legacy links once, before normal link reconciliation. */
final class FeatureLinkAdopter
{
    private $map;
    private $values;

    public function __construct(MapGateway $map, $values)
    {
        $this->map = $map;
        $this->values = $values;
    }

    public function preflight(array $adoption): bool
    {
        return $this->pending($adoption);
    }

    public function adopt(array $adoption): bool
    {
        if (!$this->pending($adoption)) {
            return false;
        }
        $valueId = $adoption['local_value_id'];
        $key = $this->key($adoption);
        $alreadyOwned = $this->map->find(Contract::ENTITY_FEATURE_LINK, $key) !== null;
        if (!$alreadyOwned) {
            $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_LINK, $key, $valueId, 'owned');
        }
        // Completion must be durable before reconciliation can remove the claimed link.
        $this->map->recordCreateChecked(
            Contract::ENTITY_FEATURE_LINK_ADOPTION, $key, $valueId, $this->fingerprint($adoption)
        );
        return !$alreadyOwned;
    }

    private function pending(array $adoption): bool
    {
        $key = $this->key($adoption);
        $productId = $adoption['local_product_id'];
        $valueId = $adoption['local_value_id'];
        $done = $this->map->find(Contract::ENTITY_FEATURE_LINK_ADOPTION, $key);
        if ($done !== null) {
            if ((int) $done->local_id !== $valueId
                || (string) $done->applied_hash !== $this->fingerprint($adoption)) {
                throw new ManifestException('v5 feature-link adoption journal conflicts with plan: ' . $key);
            }
            return false;
        }
        $product = $this->map->find(Contract::ENTITY_PRODUCT, $adoption['product_external_id']);
        $option = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $adoption['option_key']);
        $parent = $this->map->find(Contract::ENTITY_FEATURE, explode(':', $adoption['option_key'], 2)[0]);
        $physical = $this->map->findEntityOne($this->values, ['id'=>$valueId]);
        if ($product === null || (int) $product->local_id !== $productId
            || $option === null || (int) $option->local_id !== $valueId
            || $parent === null || $physical === false
            || (int) $parent->local_id !== (int) $physical->feature_id
            || !in_array($valueId, $this->map->productValueIdsChecked($this->values, $productId), true)) {
            throw new ManifestException('v5 feature-link adoption exact physical proof missing: ' . $key);
        }
        $claim = $this->map->find(Contract::ENTITY_FEATURE_LINK, $key);
        if ($claim !== null
            && ((int) $claim->local_id !== $valueId || (string) $claim->applied_hash !== 'owned')) {
            throw new ManifestException('v5 feature-link adoption encountered a protected or pending link: ' . $key);
        }
        return true;
    }

    private function key(array $adoption): string
    {
        return 'p' . $adoption['local_product_id'] . ':v' . $adoption['local_value_id'];
    }

    private function fingerprint(array $adoption): string
    {
        return hash('sha256', implode('|', [
            'v5-feature-link-adoption', $adoption['product_external_id'], $adoption['option_key'],
            $adoption['local_product_id'], $adoption['local_value_id'],
        ]));
    }
}
