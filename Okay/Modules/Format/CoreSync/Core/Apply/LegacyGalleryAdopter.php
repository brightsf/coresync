<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Psr\Log\LoggerInterface;

/**
 * Adopts already-installed legacy gallery rows into CoreSync durable ownership.
 * It never creates, updates or deletes Okay gallery rows/files and never changes main_image_id.
 */
class LegacyGalleryAdopter
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var Database */
    private $database;
    /** @var QueryFactory */
    private $queryFactory;
    /** @var Config */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /**
     * Единственный набор проверок файла галереи, общий с автоматическим усыновлением по содержимому.
     * Необязательный аргумент: существующее связывание (services.php) и прямое конструирование в
     * тестах остаются валидными, поведение донора не меняется.
     *
     * @var GalleryFileProbe
     */
    private $fileProbe;

    public function __construct(
        EntityFactory $entityFactory,
        Database $database,
        QueryFactory $queryFactory,
        Config $config,
        LoggerInterface $logger,
        ?GalleryFileProbe $fileProbe = null
    ) {
        $this->entityFactory = $entityFactory;
        $this->database = $database;
        $this->queryFactory = $queryFactory;
        $this->config = $config;
        $this->logger = $logger;
        $this->fileProbe = $fileProbe ?? new GalleryFileProbe($config);
    }

    /**
     * @param array{sha256:string,header:array<string,mixed>,rows:array<int,array<string,mixed>>} $plan
     * @return array{plan_sha256:string,rows:int,writes:array<string,int>}
     */
    public function preview(array $plan): array
    {
        $operations = $this->operations($plan);

        return [
            'plan_sha256' => $plan['sha256'],
            'rows' => count($plan['rows']),
            'writes' => [
                'durable_adds' => count($operations['adds']),
                'durable_updates' => count($operations['updates']),
                'map_updates' => count($operations['maps']),
            ],
        ];
    }

    /**
     * @param array{sha256:string,header:array<string,mixed>,rows:array<int,array<string,mixed>>} $plan
     * @return array{plan_sha256:string,rows:int,writes:int}
     * @throws GalleryAdoptionException
     */
    public function apply(array $plan, string $expectedSha256, string $confirmation): array
    {
        $actual = isset($plan['sha256']) ? (string) $plan['sha256'] : '';
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1
            || !hash_equals($actual, $expectedSha256)
            || !hash_equals('ADOPT_EXISTING_GALLERY', $confirmation)) {
            throw new GalleryAdoptionException('Gallery adoption expected SHA-256 or explicit confirmation token is invalid.');
        }
        $operations = $this->operations($plan);
        $writes = count($operations['adds']) + count($operations['updates']) + count($operations['maps']);
        if ($writes === 0) {
            $this->logger->info('CoreSync gallery adoption exact repeat', ['rows' => count($plan['rows']), 'writes' => 0]);

            return ['plan_sha256' => $actual, 'rows' => count($plan['rows']), 'writes' => 0];
        }
        foreach (['beginTransaction', 'commit', 'rollBack', 'inTransaction'] as $method) {
            if (!method_exists($this->database, $method)) {
                throw new GalleryAdoptionException('Gallery adoption requires the transactional Okay database contract.');
            }
        }
        if ($this->database->inTransaction()) {
            throw new GalleryAdoptionException('Gallery adoption refuses a pre-existing database transaction.');
        }
        if ($this->database->beginTransaction() !== true) {
            throw new GalleryAdoptionException('Gallery adoption could not open its transaction.');
        }

        try {
            foreach ($operations['adds'] as $fields) {
                $this->checkedInsert(CoreSyncImagesEntity::getTable(), $fields, 'durable insert');
            }
            foreach ($operations['updates'] as $operation) {
                $this->checkedUpdate(CoreSyncImagesEntity::getTable(), $operation['id'], $operation['fields'], 'durable update');
            }
            foreach ($operations['maps'] as $operation) {
                $this->checkedUpdate(
                    CoreSyncMapEntity::getTable(),
                    $operation['id'],
                    ['image_state' => Contract::IMAGE_STATE_DONE],
                    'coarse marker update'
                );
            }
            if ($this->database->commit() !== true) {
                throw new GalleryAdoptionException('Gallery adoption transaction commit failed.');
            }
        } catch (\Throwable $e) {
            $this->database->rollBack();
            $this->logger->warning('CoreSync gallery adoption rolled back', ['writes_planned' => $writes]);
            throw $e;
        }

        $this->logger->info('CoreSync gallery adoption applied', ['rows' => count($plan['rows']), 'writes' => $writes]);

        return ['plan_sha256' => $actual, 'rows' => count($plan['rows']), 'writes' => $writes];
    }

    /** @param array<string,mixed> $fields */
    private function checkedInsert(string $table, array $fields, string $step): void
    {
        $query = $this->queryFactory->newInsert();
        $query->into($table)->cols($fields);
        if ($this->database->query($query) === false) {
            throw new GalleryAdoptionException('Gallery adoption ' . $step . ' failed.');
        }
    }

    /** @param array<string,mixed> $fields */
    private function checkedUpdate(string $table, int $id, array $fields, string $step): void
    {
        $query = $this->queryFactory->newUpdate();
        $query->table($table)->cols($fields)->where('id = :gallery_adoption_id')
            ->bindValue('gallery_adoption_id', $id);
        if ($this->database->query($query) === false) {
            throw new GalleryAdoptionException('Gallery adoption ' . $step . ' failed.');
        }
    }

    /**
     * @param array{sha256:string,header:array<string,mixed>,rows:array<int,array<string,mixed>>} $plan
     * @return array{adds:array<int,array<string,mixed>>,updates:array<int,array<string,mixed>>,maps:array<int,array<string,mixed>>}
     */
    private function operations(array $plan): array
    {
        if (!isset($plan['header'], $plan['rows'], $plan['sha256'])
            || !is_array($plan['header']) || !is_array($plan['rows'])
            // Second pin on the already parsed plan; the literal itself lives in one place only.
            || ($plan['header']['format'] ?? null) !== GalleryAdoptionPlanReader::FORMAT
            || ($plan['header']['database'] ?? null) !== 'b2bcrm_artaz'
            || ($plan['header']['source_identity'] ?? null) !== 'okay:artaz'
            || count($plan['rows']) !== (int) ($plan['header']['rows_count'] ?? -1)
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $plan['sha256']) !== 1) {
            throw new GalleryAdoptionException('Gallery adoption parsed plan contract is invalid.');
        }

        /** @var CoreSyncImagesEntity $durable */
        $durable = $this->entityFactory->get(CoreSyncImagesEntity::class);
        /** @var CoreSyncMapEntity $map */
        $map = $this->entityFactory->get(CoreSyncMapEntity::class);
        /** @var ImagesEntity $images */
        $images = $this->entityFactory->get(ImagesEntity::class);
        /** @var ProductsEntity $products */
        $products = $this->entityFactory->get(ProductsEntity::class);
        $root = $this->galleryRoot();
        $byProduct = [];
        $localIds = [];
        $imageIds = [];
        foreach ($plan['rows'] as $row) {
            $byProduct[(string) $row['product_external_id']][] = $row;
            $localIds[(int) $row['product_local_id']] = true;
            $imageIds[(int) $row['image_id']] = true;
        }

        // Four chunked inventories, not one query per product/image. Every IN list is capped at
        // 1000 and the exact in-memory indexes make missing/duplicate/extra rows visible.
        $mapByExternal = [];
        foreach ($this->findChunked($map, 'external_id', array_keys($byProduct), [
            'entity_type' => Contract::ENTITY_PRODUCT,
        ]) as $row) {
            $external = (string) ($row->external_id ?? '');
            if (!isset($byProduct[$external]) || isset($mapByExternal[$external])) {
                throw new GalleryAdoptionException('Gallery adoption product ownership map is duplicated or unexpected.');
            }
            $mapByExternal[$external] = $row;
        }
        $productsById = [];
        foreach ($this->findChunked($products, 'id', array_keys($localIds)) as $row) {
            $id = (int) ($row->id ?? 0);
            if (!isset($localIds[$id]) || isset($productsById[$id])) {
                throw new GalleryAdoptionException('Gallery adoption product inventory is duplicated or unexpected.');
            }
            $productsById[$id] = $row;
        }
        $galleryById = [];
        foreach ($this->findChunked($images, 'product_id', array_keys($localIds)) as $row) {
            $id = (int) ($row->id ?? 0);
            if (!isset($imageIds[$id]) || isset($galleryById[$id])) {
                throw new GalleryAdoptionException('Gallery adoption legacy gallery inventory is duplicated or unexpected.');
            }
            $galleryById[$id] = $row;
        }
        $durableByProduct = [];
        foreach ($this->findChunked($durable, 'product_external_id', array_keys($byProduct)) as $row) {
            $external = (string) ($row->product_external_id ?? '');
            $hash = (string) ($row->url_hash ?? '');
            if (!isset($byProduct[$external]) || $hash === '' || isset($durableByProduct[$external][$hash])) {
                throw new GalleryAdoptionException('Gallery adoption durable ownership is duplicated or unexpected.');
            }
            $durableByProduct[$external][$hash] = $row;
        }

        $adds = [];
        $updates = [];
        $maps = [];
        foreach ($byProduct as $external => $rows) {
            $local = (int) $rows[0]['product_local_id'];
            $mapRow = $mapByExternal[$external] ?? null;
            if (!$mapRow || (int) $mapRow->local_id !== $local) {
                throw new GalleryAdoptionException('Gallery adoption product ownership map drifted.');
            }
            $product = $productsById[$local] ?? null;
            $first = $rows[0];
            foreach ($rows as $candidate) {
                if ((int) $candidate['sort'] < (int) $first['sort']) {
                    $first = $candidate;
                }
            }
            if (!$product || (int) ($product->main_image_id ?? 0) !== (int) $first['image_id']) {
                throw new GalleryAdoptionException('Gallery adoption main image identity drifted.');
            }
            $desiredByHash = [];
            foreach ($rows as $row) {
                if ((int) $row['product_local_id'] !== $local) {
                    throw new GalleryAdoptionException('Gallery adoption product local identity drifted.');
                }
                $gallery = $galleryById[(int) $row['image_id']] ?? null;
                if (!$gallery || (int) $gallery->product_id !== $local
                    || (string) $gallery->filename !== (string) $row['filename']
                    || (int) $gallery->position !== (int) $row['position']) {
                    throw new GalleryAdoptionException('Gallery adoption legacy gallery row drifted.');
                }
                $this->assertFile($root, $row);
                $desiredByHash[(string) $row['url_hash']] = $row;
            }

            $existingByHash = [];
            foreach ($durableByProduct[$external] ?? [] as $existing) {
                $hash = (string) ($existing->url_hash ?? '');
                if ($hash === '' || isset($existingByHash[$hash]) || !isset($desiredByHash[$hash])) {
                    throw new GalleryAdoptionException('Gallery adoption durable ownership conflicts with the exact plan.');
                }
                $existingByHash[$hash] = $existing;
            }
            foreach ($rows as $row) {
                $fields = [
                    'product_external_id' => $external,
                    'product_local_id' => $local,
                    'url' => (string) $row['url'],
                    'url_hash' => (string) $row['url_hash'],
                    'sort' => (int) $row['sort'],
                    'state' => Contract::IMAGE_STATE_DONE,
                    'attempts' => 0,
                    'filename' => (string) $row['filename'],
                    'image_id' => (int) $row['image_id'],
                ];
                $existing = $existingByHash[$fields['url_hash']] ?? null;
                if ($existing === null) {
                    $adds[] = $fields;
                    continue;
                }
                foreach (['product_external_id', 'product_local_id', 'url', 'url_hash', 'sort', 'filename', 'image_id'] as $field) {
                    if ((string) ($existing->$field ?? '') !== (string) $fields[$field]) {
                        throw new GalleryAdoptionException('Gallery adoption existing durable row drifted.');
                    }
                }
                if ((string) ($existing->state ?? '') !== Contract::IMAGE_STATE_DONE
                    || (int) ($existing->attempts ?? 0) !== 0) {
                    $updates[] = ['id' => (int) $existing->id, 'fields' => [
                        'state' => Contract::IMAGE_STATE_DONE,
                        'attempts' => 0,
                        'filename' => $fields['filename'],
                        'image_id' => $fields['image_id'],
                    ]];
                }
            }
            if ((string) ($mapRow->image_state ?? '') !== Contract::IMAGE_STATE_DONE) {
                $maps[] = ['id' => (int) $mapRow->id];
            }
        }

        return ['adds' => $adds, 'updates' => $updates, 'maps' => $maps];
    }

    /**
     * @param mixed $entity Okay Entity with noLimit()->find()
     * @param array<int,int|string> $values
     * @param array<string,mixed> $fixed
     * @return array<int,object>
     */
    private function findChunked($entity, string $field, array $values, array $fixed = []): array
    {
        $rows = [];
        foreach (array_chunk($values, 1000) as $chunk) {
            $found = $entity->noLimit()->find($fixed + [$field => $chunk]);
            if (!is_array($found)) {
                throw new GalleryAdoptionException('Gallery adoption inventory query failed.');
            }
            array_push($rows, ...$found);
        }

        return $rows;
    }

    private function galleryRoot(): string
    {
        return $this->fileProbe->root();
    }

    /** @param array<string,mixed> $row */
    private function assertFile(string $root, array $row): void
    {
        $measured = $this->fileProbe->measure($root, (string) $row['filename'], (int) $row['size']);
        if (!hash_equals((string) $row['sha256'], $measured['sha256'])) {
            throw new GalleryAdoptionException('Gallery adoption legacy file fingerprint drifted.');
        }
    }
}
