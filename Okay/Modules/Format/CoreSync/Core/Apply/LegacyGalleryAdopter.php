<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
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
    /** @var Config */
    private $config;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(EntityFactory $entityFactory, Database $database, Config $config, LoggerInterface $logger)
    {
        $this->entityFactory = $entityFactory;
        $this->database = $database;
        $this->config = $config;
        $this->logger = $logger;
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

        /** @var CoreSyncImagesEntity $durable */
        $durable = $this->entityFactory->get(CoreSyncImagesEntity::class);
        /** @var CoreSyncMapEntity $map */
        $map = $this->entityFactory->get(CoreSyncMapEntity::class);
        try {
            foreach ($operations['adds'] as $fields) {
                $id = $durable->add($fields);
                if (!is_numeric($id) || (int) $id < 1) {
                    throw new GalleryAdoptionException('Gallery adoption durable insert failed.');
                }
            }
            foreach ($operations['updates'] as $operation) {
                if ($durable->update($operation['id'], $operation['fields']) === false) {
                    throw new GalleryAdoptionException('Gallery adoption durable update failed.');
                }
            }
            foreach ($operations['maps'] as $operation) {
                if ($map->update($operation['id'], ['image_state' => Contract::IMAGE_STATE_DONE]) === false) {
                    throw new GalleryAdoptionException('Gallery adoption coarse marker update failed.');
                }
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

    /**
     * @param array{sha256:string,header:array<string,mixed>,rows:array<int,array<string,mixed>>} $plan
     * @return array{adds:array<int,array<string,mixed>>,updates:array<int,array<string,mixed>>,maps:array<int,array<string,mixed>>}
     */
    private function operations(array $plan): array
    {
        if (!isset($plan['header'], $plan['rows'], $plan['sha256'])
            || !is_array($plan['header']) || !is_array($plan['rows'])
            || ($plan['header']['format'] ?? null) !== 'coresync-gallery-adoption/v1'
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
        $configured = (string) $this->config->root_dir . (string) $this->config->original_images_dir;
        $root = realpath($configured);
        if ($root === false || is_link($configured) || !is_dir($root) || !is_readable($root)) {
            throw new GalleryAdoptionException('Gallery adoption legacy originals root is unsafe or unavailable.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $row */
    private function assertFile(string $root, array $row): void
    {
        $filename = (string) $row['filename'];
        if ($filename === '' || basename($filename) !== $filename || strpos($filename, "\0") !== false) {
            throw new GalleryAdoptionException('Gallery adoption legacy filename is unsafe.');
        }
        $path = $root . DIRECTORY_SEPARATOR . $filename;
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new GalleryAdoptionException('Gallery adoption legacy file type is unsafe.');
        }
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_readable($real)) {
            throw new GalleryAdoptionException('Gallery adoption legacy file location is unsafe.');
        }
        if ((int) $stat['size'] !== (int) $row['size']) {
            throw new GalleryAdoptionException('Gallery adoption legacy file size drifted.');
        }
        $stream = @fopen($real, 'rb');
        if ($stream === false) {
            throw new GalleryAdoptionException('Gallery adoption legacy file cannot be opened safely.');
        }
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || (int) $opened['dev'] !== (int) $stat['dev']
                || (int) $opened['ino'] !== (int) $stat['ino'] || (int) $opened['size'] !== (int) $row['size']) {
                throw new GalleryAdoptionException('Gallery adoption legacy file changed while opening.');
            }
            $context = hash_init('sha256');
            $hashedBytes = hash_update_stream($context, $stream);
            $sha = hash_final($context);
        } finally {
            fclose($stream);
        }
        $after = @lstat($path);
        if (!is_int($hashedBytes) || $hashedBytes !== (int) $row['size']
            || !is_array($after) || is_link($path)
            || (int) $after['dev'] !== (int) $stat['dev'] || (int) $after['ino'] !== (int) $stat['ino']
            || (int) $after['size'] !== (int) $stat['size']
            || !hash_equals((string) $row['sha256'], $sha)) {
            throw new GalleryAdoptionException('Gallery adoption legacy file fingerprint drifted.');
        }
    }
}
