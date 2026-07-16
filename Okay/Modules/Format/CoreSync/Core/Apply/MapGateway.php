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
     * Bind-запись: «связано, но не применялось» — applied_hash=NULL (последующий full/price_stock
     * обновит связанное). Каталог при этом НЕ пишется (границы владения зафиксированы, значения — нет).
     *
     * ИДЕМПОТЕНТНО (RISK(v) M3, SAT-RT §0.1): при resume прерванного bind тот же external_id может
     * прийти повторно (crash посреди файла до чекпоинта → файл переобрабатывается). Уже связанная
     * запись → безопасный no-op (без повторного INSERT, иначе unique[entity_type,external_id] бросит
     * и/или родятся дубли строк карты). local_id обновляем, только если реально сместился (defensive).
     */
    public function recordBind(string $entityType, string $externalId, int $localId): void
    {
        $existing = $this->find($entityType, (string) $externalId);
        if ($existing !== null) {
            if ((int) $existing->local_id !== $localId) {
                $this->map->update($existing->id, ['local_id' => $localId]);
            }
            $this->localIdCache[$entityType][(string) $externalId] = $localId;

            return;
        }

        $this->map->add([
            'entity_type'  => $entityType,
            'external_id'  => (string) $externalId,
            'local_id'     => $localId,
            'applied_hash' => null,
            'image_state'  => null,
        ]);
        $this->localIdCache[$entityType][(string) $externalId] = $localId;
    }

    /**
     * Взвести sticky-метку «bind в процессе» (RISK(v) M3, §0.1) ДО первой записи карты. Держит
     * shouldBind() истинным через любой interrupt/resume, пока bind не завершён полностью.
     */
    public function markBindInProgress(): void
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_EXTERNAL_ID);
        if ($row === null) {
            $this->map->add([
                'entity_type'  => Contract::ENTITY_BIND_MARKER,
                'external_id'  => Contract::BIND_MARKER_EXTERNAL_ID,
                'local_id'     => null,
                'applied_hash' => Contract::BIND_MARKER_ACTIVE,
                'image_state'  => null,
            ]);

            return;
        }
        if ((string) $row->applied_hash !== Contract::BIND_MARKER_ACTIVE) {
            $this->map->update($row->id, ['applied_hash' => Contract::BIND_MARKER_ACTIVE]);
        }
    }

    /**
     * Снять метку «bind в процессе» — bind завершён полностью; следующий прогон применяет связанное
     * через full/price_stock (shouldBind() снова смотрит на реальную пустоту карты).
     */
    public function clearBindInProgress(): void
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_EXTERNAL_ID);
        if ($row !== null && (string) $row->applied_hash === Contract::BIND_MARKER_ACTIVE) {
            $this->map->update($row->id, ['applied_hash' => null]);
        }
    }

    public function isBindInProgress(): bool
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_EXTERNAL_ID);

        return $row !== null && (string) $row->applied_hash === Contract::BIND_MARKER_ACTIVE;
    }

    /**
     * Отметить «bind по этой витрине отработал» (D-SAT-BIND-LOOP-NEVER-APPLIES). Взводится ПОСЛЕ
     * полного прохода всех products-файлов, в том числе когда связано 0 (легальный исход: у витрины
     * нет ни одного нашего SKU) — именно этот случай раньше давал вечную петлю bind→bound→bind.
     * Идемпотентно: повторный bind (напр. после потери чекпоинта) не плодит строк.
     */
    public function markBindCompleted(): void
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_DONE_EXTERNAL_ID);
        if ($row === null) {
            $this->map->add([
                'entity_type'  => Contract::ENTITY_BIND_MARKER,
                'external_id'  => Contract::BIND_MARKER_DONE_EXTERNAL_ID,
                'local_id'     => null,
                'applied_hash' => Contract::BIND_MARKER_ACTIVE,
                'image_state'  => null,
            ]);

            return;
        }
        if ((string) $row->applied_hash !== Contract::BIND_MARKER_ACTIVE) {
            $this->map->update($row->id, ['applied_hash' => Contract::BIND_MARKER_ACTIVE]);
        }
    }

    public function isBindCompleted(): bool
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_DONE_EXTERNAL_ID);

        return $row !== null && (string) $row->applied_hash === Contract::BIND_MARKER_ACTIVE;
    }

    /**
     * Снять метку «bind по этой витрине отработал» (церемония «Связать заново»,
     * D-SAT-BIND-REBIND-CEREMONY). Симметрична clearBindInProgress: после сброса isBindCompleted()
     * снова ложна, и следующий прогон с пустой картой product/variant + непустым каталогом уходит в
     * BIND. Production-путь церемонии сносит владение и гасит метки одним SQL
     * (CoreSyncMapEntity::resetForRebind); этот примитив — точечный симметричный аналог
     * clearBindInProgress для работы поверх уже резолвнутой карты.
     */
    public function clearBindCompleted(): void
    {
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_DONE_EXTERNAL_ID);
        if ($row !== null && (string) $row->applied_hash === Contract::BIND_MARKER_ACTIVE) {
            $this->map->update($row->id, ['applied_hash' => null]);
        }
    }

    /**
     * Число строк карты данного типа.
     */
    public function count(string $entityType): int
    {
        return count($this->map->find(['entity_type' => $entityType]));
    }

    /**
     * Обновить applied_hash существующей строки (price_stock: точечный skip-инвариант по варианту).
     *
     * @param object $row
     */
    public function setHash($row, string $hash): void
    {
        $this->map->update($row->id, ['applied_hash' => $hash]);
    }

    /**
     * Обновить coarse-флаг image_state строки товара (для admin/reapply).
     */
    public function updateImageState(string $productExternalId, string $state): void
    {
        $row = $this->find(Contract::ENTITY_PRODUCT, $productExternalId);
        if ($row !== null) {
            $this->map->update($row->id, ['image_state' => $state]);
        }
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
