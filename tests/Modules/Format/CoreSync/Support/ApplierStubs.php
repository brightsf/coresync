<?php

namespace Tests\Modules\Format\CoreSync\Support;

/**
 * In-memory стаб-сущности Okay для интеграционных тестов Applier (паттерн APIImport: моки Entities,
 * реальная БД не нужна). Каждый стаб хранит состояние между прогонами (идемпотентность) и пишет
 * журнал add/update-вызовов (нетавтологичность: 2-й прогон = 0 мутаций).
 */

/** Утилита сопоставления фильтра со строкой-массивом. */
trait StubMatch
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filter
     */
    private function matches(array $row, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}

/** Карта __format__coresync_map (idempotency-хранилище). */
final class MapEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var int */
    private $nextId = 1;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $object['id'] = $this->nextId++;
        $this->rows[$object['id']] = $object;

        return $object['id'];
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($id, $object): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @return object|false
     */
    public function findOne(array $filter)
    {
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                return (object) $row;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, object>
     */
    public function find(array $filter = [])
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                $out[] = (object) $row;
            }
        }

        return $out;
    }
}

/** Базовый upsert-стаб с журналом вызовов и симуляцией мутации slug ядром. */
abstract class UpsertEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var list<array<string, mixed>> */
    public $addCalls = [];
    /** @var list<array{0: mixed, 1: array<string, mixed>}> */
    public $updateCalls = [];
    /** @var list<string> slugs, которые ядро «мутирует» при add (коллизия url) */
    public $collisionSlugs = [];
    /** @var int */
    protected $nextId = 1;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;
        $id = $this->nextId++;
        if (isset($object['url']) && in_array($object['url'], $this->collisionSlugs, true)) {
            $object['url'] = $object['url'] . '_1'; // мутация ядром при коллизии
        }
        $object['id'] = $id;
        $this->rows[$id] = $object;

        return $id;
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($id, $object)
    {
        $this->updateCalls[] = [$id, (array) $object];
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     * @return object|false
     */
    public function findOne(array $filter)
    {
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                return (object) $row;
            }
        }

        return false;
    }

    public function get($id)
    {
        return isset($this->rows[(int) $id]) ? (object) $this->rows[(int) $id] : null;
    }

    public function mutations(): int
    {
        return count($this->addCalls) + count($this->updateCalls);
    }
}

final class BrandsEntityStub extends UpsertEntityStub
{
}

final class FeaturesEntityStub extends UpsertEntityStub
{
}

/** Товары + product-категорийные связи (Applier резолвит одну CategoriesEntity для того и другого). */
final class CategoriesEntityStub extends UpsertEntityStub
{
    /** @var list<array{product_id:int, category_id:int, position:int}> */
    public $productCategories = [];

    /**
     * @param array<int, int> $productIds
     * @return array<int, object>
     */
    public function getProductCategories($productIds = [])
    {
        $ids = array_map('intval', (array) $productIds);
        $out = [];
        foreach ($this->productCategories as $link) {
            if (empty($ids) || in_array((int) $link['product_id'], $ids, true)) {
                $out[] = (object) $link;
            }
        }

        return $out;
    }

    public function addProductCategory($productId, $categoryId, $position = 0)
    {
        $this->productCategories[] = [
            'product_id'  => (int) $productId,
            'category_id' => (int) $categoryId,
            'position'    => (int) $position,
        ];

        return true;
    }

    public function deleteProductCategory($productsIds, $categoriesIds = [])
    {
        $pids = array_map('intval', (array) $productsIds);
        $cids = array_map('intval', (array) $categoriesIds);
        foreach ($this->productCategories as $k => $link) {
            if (in_array((int) $link['product_id'], $pids, true)
                && (empty($cids) || in_array((int) $link['category_id'], $cids, true))) {
                unset($this->productCategories[$k]);
            }
        }
        $this->productCategories = array_values($this->productCategories);

        return true;
    }
}

final class ProductsEntityStub extends UpsertEntityStub
{
}

final class VariantsEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var list<array<string, mixed>> */
    public $addCalls = [];
    /** @var list<array{0: mixed, 1: array<string, mixed>}> */
    public $updateCalls = [];
    /** @var int */
    private $nextId = 1;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;
        $object['id'] = $this->nextId++;
        $this->rows[$object['id']] = $object;

        return $object['id'];
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($id, $object)
    {
        $this->updateCalls[] = [$id, (array) $object];
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, object>
     */
    public function find(array $filter = [])
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                $out[] = (object) $row;
            }
        }

        return $out;
    }

    public function mutations(): int
    {
        return count($this->addCalls) + count($this->updateCalls);
    }
}

final class FeaturesValuesEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> value-строки */
    public $values = [];
    /** @var list<array{product_id:int, value_id:int}> */
    public $productValues = [];
    /** @var list<array<string, mixed>> */
    public $addCalls = [];
    /** @var int */
    private $nextId = 1;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;
        $object['id'] = $this->nextId++;
        $this->values[$object['id']] = $object;

        return $object['id'];
    }

    public function update($id, $object)
    {
        if (isset($this->values[$id])) {
            $this->values[$id] = array_merge($this->values[$id], (array) $object);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, object>
     */
    public function find(array $filter = [])
    {
        $out = [];
        foreach ($this->values as $row) {
            if ($this->matches($row, $filter)) {
                $out[] = (object) $row;
            }
        }

        return $out;
    }

    public function deleteProductValue($productsIds, $valuesIds = null, $featuresIds = null)
    {
        $pids = array_map('intval', (array) $productsIds);
        foreach ($this->productValues as $k => $link) {
            if (in_array((int) $link['product_id'], $pids, true)) {
                unset($this->productValues[$k]);
            }
        }
        $this->productValues = array_values($this->productValues);

        return true;
    }

    public function addProductValue($productId, $valueId)
    {
        $this->productValues[] = ['product_id' => (int) $productId, 'value_id' => (int) $valueId];

        return true;
    }
}

/** Format/Redirects RedirectsEntity (upsert по request_url). */
final class RedirectsEntityStub extends UpsertEntityStub
{
}
