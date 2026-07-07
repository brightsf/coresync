<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Гейт карты владения sync'а (__format__coresync_map) — сердце идемпотентности applier'а.
 * По (entity_type, external_id) решает skip/update/create, ведёт applied_hash и обратное
 * разрешение external_id → local_id (FK: бренд/родитель/категория товара). Границы владения:
 * applier трогает в витрине ТОЛЬКО записи, попавшие в эту карту.
 *
 * $mapEntity — CoreSyncMapEntity из EntityFactory (mixed, как принято в Okay).
 */
class MapGateway
{
    /** @var mixed CoreSyncMapEntity */
    private $map;

    /** @var array<string, array<string, int>> кэш external_id → local_id по типу (для FK-разрешения) */
    private $localIdCache = [];

    /**
     * @param mixed $mapEntity
     */
    public function __construct($mapEntity)
    {
        $this->map = $mapEntity;
    }

    /**
     * Строка карты для (type, external_id) или null.
     *
     * @return object|null
     */
    public function find(string $entityType, string $externalId)
    {
        $row = $this->map->findOne(['entity_type' => $entityType, 'external_id' => (string) $externalId]);

        return $row ?: null;
    }

    /**
     * @param object|null $row строка карты (из find)
     * @return string Contract::MAP_SKIP | MAP_UPDATE | MAP_CREATE
     */
    public function decide($row, string $hash): string
    {
        if ($row === null) {
            return Contract::MAP_CREATE;
        }

        return ((string) $row->applied_hash === $hash) ? Contract::MAP_SKIP : Contract::MAP_UPDATE;
    }

    public function recordCreate(string $entityType, string $externalId, int $localId, string $hash, ?string $imageState = null): void
    {
        $this->map->add([
            'entity_type'  => $entityType,
            'external_id'  => (string) $externalId,
            'local_id'     => $localId,
            'applied_hash' => $hash,
            'image_state'  => $imageState,
        ]);
        $this->localIdCache[$entityType][(string) $externalId] = $localId;
    }

    /**
     * @param object $row строка карты (из find)
     */
    public function recordUpdate($row, int $localId, string $hash, ?string $imageState = null): void
    {
        $this->map->update($row->id, [
            'local_id'     => $localId,
            'applied_hash' => $hash,
            'image_state'  => $imageState,
        ]);
        $this->localIdCache[(string) $row->entity_type][(string) $row->external_id] = $localId;
    }

    /**
     * Разрешение external_id → local_id (для FK). null — сущность не применена (нет в карте).
     */
    public function localId(string $entityType, string $externalId): ?int
    {
        if (isset($this->localIdCache[$entityType][(string) $externalId])) {
            return $this->localIdCache[$entityType][(string) $externalId];
        }
        $row = $this->find($entityType, (string) $externalId);
        if ($row === null || $row->local_id === null) {
            return null;
        }
        $localId = (int) $row->local_id;
        $this->localIdCache[$entityType][(string) $externalId] = $localId;

        return $localId;
    }

    /**
     * Все external_id → local_id данного типа (для absent-фазы: карта минус снапшот).
     *
     * @return array<string, int>
     */
    public function allLocalIds(string $entityType): array
    {
        $out = [];
        foreach ($this->map->find(['entity_type' => $entityType]) as $row) {
            if ($row->local_id !== null) {
                $out[(string) $row->external_id] = (int) $row->local_id;
            }
        }

        return $out;
    }
}
