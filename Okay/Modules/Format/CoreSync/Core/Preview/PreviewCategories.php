<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use LogicException;
use Okay\Entities\CategoriesEntity;

final class PreviewCategories extends PreviewLocalizedRows
{
    private $links;

    public function __construct(
        iterable $categories,
        PreviewRows $links,
        ?PreviewLanguages $languages = null,
        ?PreviewRows $translations = null
    ) {
        parent::__construct('category', $categories, ['url', 'external_id', 'coresync_external_id', 'parent_id'],
            $languages, $translations, CategoriesEntity::getLangFields());
        $this->links = $links;
    }

    public function add(array $fields): int
    {
        return parent::add($this->withDepth($fields));
    }

    public function update(int $id, array $fields): int
    {
        return parent::update($id, $this->withDepth($fields, $id));
    }

    private function withDepth(array $fields, ?int $id = null): array
    {
        if (!array_key_exists('parent_id', $fields)) {
            return $fields;
        }
        $parents = $id === null ? [] : [$id => true];
        $depth = 1;
        $parent = (int) $fields['parent_id'];
        while ($parent > 0) {
            $row = $this->get($parent);
            if (!$row || isset($parents[$parent])) {
                throw new LogicException('Offline category hierarchy is missing a parent or contains a cycle');
            }
            $parents[$parent] = true;
            $depth++;
            $parent = (int) ($row->parent_id ?? 0);
        }
        $fields['level_depth'] = $depth;

        return $fields;
    }

    public function getProductCategories(array $productIds): array
    {
        $links = [];
        foreach ($productIds as $id) {
            array_push($links, ...$this->links->find(['product_id' => (int) $id]));
        }
        usort($links, static function (object $left, object $right): int {
            return [$left->position, $left->id] <=> [$right->position, $right->id];
        });

        return $links;
    }

    public function addProductCategory(int $productId, int $categoryId, int $position = 0): bool
    {
        $key = ['product_id' => $productId, 'category_id' => $categoryId];
        if (!$this->links->findOne($key)) {
            $this->links->add($key + ['position' => $position]);
        }

        return true;
    }

    public function deleteProductCategory(int $productId, array $categoryIds): bool
    {
        foreach ($categoryIds as $id) {
            foreach ($this->links->find(['product_id' => $productId, 'category_id' => (int) $id]) as $link) {
                $this->links->delete((int) $link->id);
            }
        }

        return true;
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
