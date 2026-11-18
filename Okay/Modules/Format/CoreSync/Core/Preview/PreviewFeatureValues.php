<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

final class PreviewFeatureValues extends PreviewLocalizedRows
{
    private $links;

    public function __construct(
        iterable $values,
        PreviewRows $links,
        ?PreviewLanguages $languages = null,
        ?PreviewRows $translations = null
    ) {
        parent::__construct('feature_value', $values, ['feature_id', 'value', 'translit'],
            $languages, $translations, \Okay\Entities\FeaturesValuesEntity::getLangFields());
        $this->links = $links;
    }

    public function getProductValuesIds(array $productIds): array
    {
        $links = [];
        foreach ($productIds as $id) {
            array_push($links, ...$this->links->find(['product_id' => (int) $id]));
        }

        return $links;
    }

    public function addProductValue(int $productId, int $valueId): bool
    {
        if ($productId <= 0 || $valueId <= 0) {
            return false;
        }
        $key = ['product_id' => $productId, 'value_id' => $valueId];
        if (!$this->links->findOne($key)) {
            $this->links->add($key);
        }

        return true;
    }

    /**
     * The native query selects value IDs and managed feature IDs conjunctively.
     * CoreSync only calls this port for a particular product.
     *
     * @param int|int[]|null $valueIds
     */
    public function deleteProductValue(int $productId, $valueIds = null, ?array $featureIds = null): bool
    {
        if ($productId <= 0) {
            return false;
        }
        $values = array_fill_keys(array_map('intval', (array) $valueIds), true);
        $features = array_fill_keys(array_map('intval', $featureIds ?? []), true);
        foreach ($this->getProductValuesIds([$productId]) as $link) {
            $valueId = (int) $link->value_id;
            if ($values !== [] && !isset($values[$valueId])) {
                continue;
            }
            if ($features !== []) {
                $value = $this->get($valueId);
                if (!$value || !isset($features[$value->feature_id])) {
                    continue;
                }
            }
            $this->links->delete((int) $link->id);
        }

        return true;
    }

    public function transaction(callable $apply): void
    {
        $this->links->transaction(function () use ($apply): void {
            parent::transaction($apply);
        });
    }

    public function baselineDigest(): string
    {
        return hash('sha256', parent::baselineDigest() . $this->links->baselineDigest());
    }

    public function changes(): array
    {
        return array_merge(parent::changes(), $this->links->changes());
    }
}
