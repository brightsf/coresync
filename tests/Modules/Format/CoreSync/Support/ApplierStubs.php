<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Modules\Format\CoreSync\Core\Apply\ImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Apply\CategoryImageDownloader;

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
    /**
     * Журнал записей карты по порядку (замок порядка bind-меток): каждая запись —
     * ['op','entity_type','external_id','applied_hash' (результирующее значение)].
     *
     * @var list<array<string, mixed>>
     */
    public $writeLog = [];
    /** @var int */
    private $nextId = 1;
    /** @var callable|null */
    public $onWrite;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $object['id'] = $this->nextId++;
        $this->rows[$object['id']] = $object;
        if (is_callable($this->onWrite)) {
            ($this->onWrite)('map_add');
        }
        $this->writeLog[] = [
            'op'           => 'add',
            'entity_type'  => $object['entity_type'] ?? null,
            'external_id'  => $object['external_id'] ?? null,
            'applied_hash' => $object['applied_hash'] ?? null,
        ];

        return $object['id'];
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($id, $object): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
            $row = $this->rows[$id];
            $this->writeLog[] = [
                'op'           => 'update',
                'entity_type'  => $row['entity_type'] ?? null,
                'external_id'  => $row['external_id'] ?? null,
                'applied_hash' => array_key_exists('applied_hash', (array) $object)
                    ? ((array) $object)['applied_hash']
                    : ($row['applied_hash'] ?? null),
            ];
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

    public function get($id)
    {
        return isset($this->rows[(int) $id]) ? (object) $this->rows[(int) $id] : null;
    }

    public function count($filter = [])
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->matches($row, (array) $filter)) {
                $n++;
            }
        }

        return $n;
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
class CategoriesEntityStub extends UpsertEntityStub
{
    /** @var list<array{product_id:int, category_id:int, position:int}> */
    public $productCategories = [];
    /** @var int|null Active language for v2 translation assertions; null preserves legacy stub behaviour. */
    public $currentLangId;
    /** @var array<int, array<int, array<string, mixed>>> category id => lang id => lang fields */
    public $languageRows = [];

    /** @var string[] */
    private $languageFields = [
        'name', 'meta_title', 'meta_keywords', 'meta_description', 'annotation', 'description',
    ];

    public function add($object)
    {
        $id = parent::add($object);
        $this->rememberLanguageFields($id, (array) $object);

        return $id;
    }

    public function update($id, $object)
    {
        $result = parent::update($id, $object);
        $this->rememberLanguageFields((int) $id, (array) $object);

        return $result;
    }

    /** @param array<string, mixed> $fields */
    private function rememberLanguageFields(int $id, array $fields): void
    {
        if ($this->currentLangId === null) {
            return;
        }
        $langFields = [];
        foreach ($this->languageFields as $field) {
            if (array_key_exists($field, $fields)) {
                $langFields[$field] = $fields[$field];
            }
        }
        if (!empty($langFields)) {
            $existing = $this->languageRows[$id][$this->currentLangId] ?? [];
            $this->languageRows[$id][$this->currentLangId] = array_merge($existing, $langFields);
        }
    }

    /**
     * Контракт реального Okay CategoriesEntity: find() без фильтра возвращает дерево, но buildFilter
     * понимает только category id/product_id/brand_id. Неизвестные url/module-marker фильтры дают
     * пустой результат и не должны маскироваться generic-возможностями базового тестового стаба.
     *
     * @param array<string, mixed> $filter
     * @return array<int, object>
     */
    public function find(array $filter = [])
    {
        foreach (array_keys($filter) as $field) {
            if (!in_array($field, ['id', 'product_id', 'brand_id'], true)) {
                return [];
            }
        }

        return parent::find($filter);
    }

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

    public function count($filter = [])
    {
        return count($this->find((array) $filter));
    }

    public function mutations(): int
    {
        return count($this->addCalls) + count($this->updateCalls);
    }
}

/** ImagesEntity витрины (product_id, filename, position) + delete файлов (в стабе — просто удаление строки). */
final class ImagesEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var list<array<string, mixed>> */
    public $addCalls = [];
    /** @var list<array{0: mixed, 1: array<string, mixed>}> */
    public $updateCalls = [];
    /** @var list<int> */
    public $deleteCalls = [];
    /** @var int */
    private $nextId = 1;

    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;
        $object['id'] = $this->nextId++;
        $this->rows[$object['id']] = $object;

        return $object['id'];
    }

    public function update($id, $object)
    {
        $this->updateCalls[] = [$id, (array) $object];
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
        }

        return true;
    }

    public function delete($ids)
    {
        foreach ((array) $ids as $id) {
            $this->deleteCalls[] = (int) $id;
            unset($this->rows[(int) $id]);
        }

        return true;
    }

    public function get($id)
    {
        return isset($this->rows[(int) $id]) ? (object) $this->rows[(int) $id] : null;
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

/** Durable-список картинок CoreSync (__format__coresync_images). */
final class CoreSyncImagesEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var int */
    private $nextId = 1;

    public function add($object)
    {
        $object = (array) $object;
        $object['id'] = $this->nextId++;
        $this->rows[$object['id']] = $object;

        return $object['id'];
    }

    public function update($id, $object): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], (array) $object);
        }
    }

    public function delete($ids): void
    {
        foreach ((array) $ids as $id) {
            unset($this->rows[(int) $id]);
        }
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

/** One-row-per-category durable v2 image state. */
final class CoreSyncCategoryImagesEntityStub
{
    use StubMatch;

    /** @var array<int, array<string, mixed>> */
    public $rows = [];
    /** @var list<array<string, mixed>> */
    public $addCalls = [];
    /** @var list<array{0:int,1:array<string,mixed>}> */
    public $updateCalls = [];
    /** @var int */
    private $nextId = 1;
    /** @var callable|null */
    public $onWrite;

    public function add($object)
    {
        $row = (array) $object;
        $this->addCalls[] = $row;
        $row['id'] = $this->nextId++;
        $this->rows[$row['id']] = $row;
        if (is_callable($this->onWrite)) {
            ($this->onWrite)('category_image_add');
        }

        return $row['id'];
    }

    public function update($id, $object): void
    {
        $id = (int) $id;
        $fields = (array) $object;
        $this->updateCalls[] = [$id, $fields];
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $fields);
        }
    }

    public function findOne(array $filter)
    {
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                return (object) $row;
            }
        }

        return false;
    }

    public function find(array $filter = [])
    {
        $result = [];
        foreach ($this->rows as $row) {
            if ($this->matches($row, $filter)) {
                $result[] = (object) $row;
            }
        }

        return $result;
    }
}

final class FakeCategoryImageDownloader extends CategoryImageDownloader
{
    /** @var list<array{descriptor:array<string,mixed>,identity:string}> */
    public $requested = [];
    /** @var list<string> */
    public $deleted = [];
    /** @var bool */
    public $fail = false;
    /** @var string */
    public $filename = 'coresync_category_aaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbb.jpg';

    public function __construct()
    {
    }

    public function download(array $descriptor, string $opaqueIdentity): ?string
    {
        $this->requested[] = ['descriptor' => $descriptor, 'identity' => $opaqueIdentity];

        return $this->fail ? null : $this->filename;
    }

    public function lastErrorCode(): ?string
    {
        return $this->fail ? 'test_failure' : null;
    }

    public function deleteOwned(string $filename): bool
    {
        $this->deleted[] = $filename;

        return true;
    }
}

/**
 * Фейк-загрузчик картинок: без реального HTTP. По умолчанию «скачивает» (возвращает имя из URL);
 * URL из $failUrls «падают» (null). Журналит запросы (нетавтологичность фазы картинок).
 */
final class FakeImageDownloader implements ImageDownloader
{
    /** @var list<string> */
    public $requested = [];
    /** @var list<string> URL, которые должны «упасть» */
    public $failUrls = [];

    public function download(string $url): ?string
    {
        $this->requested[] = $url;
        if (in_array($url, $this->failUrls, true)) {
            return null;
        }

        return 'mirror_' . substr(md5($url), 0, 8) . '.jpg';
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
