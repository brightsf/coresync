<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Entity\Entity;
use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;

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

    /** @var bool|null completed-маркер, прочитанный один раз на lifetime gateway (= один apply) */
    private $bindCompletedCache;

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
        $filter = ['entity_type' => $entityType, 'external_id' => (string) $externalId];
        $row = method_exists($this->map, 'findOneChecked')
            ? $this->map->findOneChecked($filter)
            : $this->map->findOne($filter);

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

    public function recordCreateChecked(
        string $entityType,
        string $externalId,
        int $localId,
        string $hash,
        ?string $imageState = null
    ): void {
        $result = $this->checkedEntityWrite(
            $this->map,
            function () use ($entityType, $externalId, $localId, $hash, $imageState) {
                return $this->map->add([
                    'entity_type'  => $entityType,
                    'external_id'  => (string) $externalId,
                    'local_id'     => $localId,
                    'applied_hash' => $hash,
                    'image_state'  => $imageState,
                ]);
            },
            'map create'
        );
        if ($result === false || (int) $result < 1) {
            throw new CoreSyncException('CoreSync apply: map create failed');
        }
        $saved = $this->find($entityType, (string) $externalId);
        if ($saved === null
            || (int) ($saved->local_id ?? 0) !== $localId
            || (string) ($saved->applied_hash ?? '') !== $hash) {
            throw new CoreSyncException('CoreSync apply: map create readback mismatch');
        }
        $this->localIdCache[$entityType][(string) $externalId] = $localId;
    }

    /**
     * Create the durable product checkpoint before the product row exists.
     *
     * @return object exact pending map row
     */
    public function reserveProduct(string $externalId): object
    {
        $existing = $this->find(Contract::ENTITY_PRODUCT, $externalId);
        if ($existing !== null) {
            $this->assertProductCheckpoint($existing, $externalId, null, null);

            return $existing;
        }

        $id = $this->checkedEntityWrite($this->map, function () use ($externalId) {
            return $this->map->add([
                'entity_type' => Contract::ENTITY_PRODUCT,
                'external_id' => $externalId,
                'local_id' => null,
                'applied_hash' => null,
                'image_state' => null,
            ]);
        }, 'product map reserve');
        if ($id === false || (int) $id < 1) {
            throw new CoreSyncException('CoreSync apply: не удалось зарезервировать product map');
        }

        $reserved = $this->find(Contract::ENTITY_PRODUCT, $externalId);
        if ($reserved === null) {
            throw new CoreSyncException('CoreSync apply: reservation product map не читается после записи');
        }
        $this->assertProductCheckpoint($reserved, $externalId, null, null);

        return $reserved;
    }

    /** @param object $reservation @return object attached pending map row */
    public function attachProduct($reservation, string $externalId, int $localId, ?string $imageState): object
    {
        if ($localId < 1) {
            throw new CoreSyncException('CoreSync apply: product reservation получил некорректный local_id');
        }
        $result = $this->checkedEntityWrite($this->map, function () use ($reservation, $localId, $imageState) {
            return $this->map->update($reservation->id, [
                'local_id' => $localId,
                'applied_hash' => null,
                'image_state' => $imageState,
            ]);
        }, 'product map attach');
        if ($result === false) {
            throw new CoreSyncException('CoreSync apply: не удалось прикрепить product reservation');
        }

        $attached = $this->find(Contract::ENTITY_PRODUCT, $externalId);
        if ($attached === null) {
            throw new CoreSyncException('CoreSync apply: product reservation не читается после attach');
        }
        $this->assertProductCheckpoint($attached, $externalId, $localId, null);
        $this->localIdCache[Contract::ENTITY_PRODUCT][$externalId] = $localId;

        return $attached;
    }

    /** @param object $checkpoint @return object finalized map row */
    public function finalizeProduct(
        $checkpoint,
        string $externalId,
        int $localId,
        string $hash,
        ?string $imageState
    ): object {
        $result = $this->checkedEntityWrite(
            $this->map,
            function () use ($checkpoint, $localId, $hash, $imageState) {
                return $this->map->update($checkpoint->id, [
                    'local_id' => $localId,
                    'applied_hash' => $hash,
                    'image_state' => $imageState,
                ]);
            },
            'product map finalize'
        );
        if ($result === false) {
            throw new CoreSyncException('CoreSync apply: не удалось финализировать product map');
        }

        $final = $this->find(Contract::ENTITY_PRODUCT, $externalId);
        if ($final === null) {
            throw new CoreSyncException('CoreSync apply: product map не читается после finalize');
        }
        $this->assertProductCheckpoint($final, $externalId, $localId, $hash);
        $this->localIdCache[Contract::ENTITY_PRODUCT][$externalId] = $localId;

        return $final;
    }

    /** @param mixed $entity */
    public function addEntityChecked($entity, array $fields, string $label): int
    {
        $localId = $this->checkedEntityWrite($entity, static function () use ($entity, $fields) {
            return $entity->add($fields);
        }, $label . ' add');
        if ($localId === false || (int) $localId < 1) {
            throw new CoreSyncException('CoreSync apply: add failed for ' . $label);
        }
        $localId = (int) $localId;
        $this->assertEntityFields($entity, $localId, $fields, $label);

        return $localId;
    }

    /** @param mixed $entity */
    public function updateEntityChecked($entity, int $localId, array $fields, string $label): void
    {
        $result = $this->checkedEntityWrite($entity, static function () use ($entity, $localId, $fields) {
            return $entity->update($localId, $fields);
        }, $label . ' update');
        if ($result === false) {
            throw new CoreSyncException('CoreSync apply: update failed for ' . $label);
        }
        $this->assertEntityFields($entity, $localId, $fields, $label);
    }

    /** @param mixed $entity */
    private function assertEntityFields($entity, int $localId, array $expected, string $label): void
    {
        $saved = $this->findEntityOne($entity, ['id' => $localId]);
        if ($saved === false) {
            throw new CoreSyncException('CoreSync apply: entity missing after write for ' . $label);
        }
        foreach ($expected as $field => $value) {
            if (!property_exists($saved, $field)
                || ($value === null ? $saved->$field !== null : (string) $saved->$field !== (string) $value)) {
                throw new CoreSyncException('CoreSync apply: write readback mismatch for ' . $label . '.' . $field);
            }
        }
    }

    /**
     * Okay CRUD and Languages both swallow Database::query()===false. During an owned write,
     * temporarily replace both database references with a proxy that turns that exact signal into
     * an exception. Stubs keep their normal in-memory path.
     *
     * @param mixed $entity
     * @return mixed
     */
    private function checkedEntityWrite($entity, callable $write, string $label)
    {
        if (!$entity instanceof Entity) {
            return $write();
        }

        $entityDbProperty = new \ReflectionProperty(Entity::class, 'db');
        $entityDbProperty->setAccessible(true);
        $entityDatabase = $entityDbProperty->getValue($entity);
        $entityGuardInstalled = false;
        $languageDbProperty = null;
        $languageDatabase = null;
        $languageGuardInstalled = false;
        $languages = null;

        try {
            try {
                $entityDbProperty->setValue($entity, $this->failClosedDatabase($entityDatabase, $label));
                $entityGuardInstalled = true;

                $languageProperty = new \ReflectionProperty(Entity::class, 'lang');
                $languageProperty->setAccessible(true);
                $languages = $languageProperty->getValue($entity);
                if ($languages instanceof Languages) {
                    $languageDbProperty = new \ReflectionProperty(Languages::class, 'db');
                    $languageDbProperty->setAccessible(true);
                    $languageDatabase = $languageDbProperty->getValue($languages);
                    $languageDbProperty->setValue(
                        $languages,
                        $this->failClosedDatabase($languageDatabase, $label . ' language')
                    );
                    $languageGuardInstalled = true;
                }
            } catch (\Throwable $e) {
                throw new CoreSyncException('CoreSync apply: cannot guard database write for ' . $label, 0, $e);
            }

            try {
                return $write();
            } catch (CoreSyncException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new CoreSyncException('CoreSync apply: write failed for ' . $label, 0, $e);
            }
        } finally {
            if ($languageGuardInstalled) {
                $languageDbProperty->setValue($languages, $languageDatabase);
            }
            if ($entityGuardInstalled) {
                $entityDbProperty->setValue($entity, $entityDatabase);
            }
        }
    }

    /** @param mixed $database @return object */
    private function failClosedDatabase($database, string $label): object
    {
        return new class($database, $label) {
            /** @var mixed */
            private $database;
            /** @var string */
            private $label;

            /** @param mixed $database */
            public function __construct($database, string $label)
            {
                $this->database = $database;
                $this->label = $label;
            }

            /** @param mixed $query @return mixed */
            public function query($query, $debug = false)
            {
                $result = $this->database->query($query, $debug);
                if ($result === false) {
                    throw new CoreSyncException('CoreSync apply: database query failed for ' . $this->label);
                }

                return $result;
            }

            /** @param array<int, mixed> $arguments @return mixed */
            public function __call(string $name, array $arguments)
            {
                return call_user_func_array([$this->database, $name], $arguments);
            }
        };
    }

    /** @param object $row */
    private function assertProductCheckpoint(
        $row,
        string $externalId,
        ?int $localId,
        ?string $appliedHash
    ): void {
        $savedLocalId = $row->local_id ?? null;
        $savedHash = $row->applied_hash ?? null;
        if ((string) ($row->entity_type ?? '') !== Contract::ENTITY_PRODUCT
            || (string) ($row->external_id ?? '') !== $externalId
            || ($localId === null ? $savedLocalId !== null : (int) $savedLocalId !== $localId)
            || ($appliedHash === null ? $savedHash !== null : (string) $savedHash !== $appliedHash)) {
            throw new CoreSyncException('CoreSync apply: product map checkpoint readback mismatch');
        }
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
            $this->bindCompletedCache = true;

            return;
        }
        if ((string) $row->applied_hash !== Contract::BIND_MARKER_ACTIVE) {
            $this->map->update($row->id, ['applied_hash' => Contract::BIND_MARKER_ACTIVE]);
        }
        $this->bindCompletedCache = true;
    }

    public function isBindCompleted(): bool
    {
        if ($this->bindCompletedCache !== null) {
            return $this->bindCompletedCache;
        }
        $row = $this->find(Contract::ENTITY_BIND_MARKER, Contract::BIND_MARKER_DONE_EXTERNAL_ID);

        $this->bindCompletedCache = $row !== null
            && (string) $row->applied_hash === Contract::BIND_MARKER_ACTIVE;

        return $this->bindCompletedCache;
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
        $this->bindCompletedCache = false;
    }

    /**
     * Число строк карты данного типа.
     */
    public function count(string $entityType): int
    {
        return count($this->findRows(['entity_type' => $entityType]));
    }

    /**
     * Строки карты данного типа, уже указывающие на local_id. Нужны dictionary-bind/apply, чтобы
     * один локальный category/brand нельзя было тихо связать с двумя external_id.
     *
     * @return array<int, object>
     */
    public function findByLocalId(string $entityType, int $localId): array
    {
        return $this->findRows(['entity_type' => $entityType, 'local_id' => $localId]);
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

    public function recordUpdateChecked(
        $row,
        int $localId,
        string $hash,
        ?string $imageState = null
    ): void {
        $result = $this->checkedEntityWrite(
            $this->map,
            function () use ($row, $localId, $hash, $imageState) {
                return $this->map->update($row->id, [
                    'local_id'     => $localId,
                    'applied_hash' => $hash,
                    'image_state'  => $imageState,
                ]);
            },
            'map update'
        );
        if ($result === false) {
            throw new CoreSyncException('CoreSync apply: map update failed');
        }
        $saved = $this->find((string) $row->entity_type, (string) $row->external_id);
        if ($saved === null
            || (int) ($saved->local_id ?? 0) !== $localId
            || (string) ($saved->applied_hash ?? '') !== $hash) {
            throw new CoreSyncException('CoreSync apply: map update readback mismatch');
        }
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
        foreach ($this->findRows(['entity_type' => $entityType]) as $row) {
            if ($row->local_id !== null) {
                $out[(string) $row->external_id] = (int) $row->local_id;
            }
        }

        return $out;
    }

    /**
     * Checked post-write read для Applier::urlMatches(). В production CoreSyncMapEntity выполняет
     * Select целевой Entity через Database с проверкой query()===false; тестовые in-memory Entity
     * остаются на штатном findOne(), потому что базы у них нет по построению.
     *
     * @param mixed $entity Okay Entity или тестовый стаб
     * @return object|false
     */
    public function findEntityOne($entity, array $filter)
    {
        if (method_exists($this->map, 'findEntityOneChecked')) {
            return $this->map->findEntityOneChecked($entity, $filter);
        }

        return $entity->findOne($filter);
    }

    /**
     * @param mixed $entity Okay Entity или тестовый стаб
     * @return array<int, object>
     */
    public function findEntityRows($entity, array $filter): array
    {
        if (method_exists($this->map, 'findEntityChecked')) {
            return $this->map->findEntityChecked($entity, $filter);
        }

        return $entity->find($filter);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, object>
     */
    private function findRows(array $filter): array
    {
        if (method_exists($this->map, 'findChecked')) {
            return $this->map->findChecked($filter);
        }

        return $this->map->find($filter);
    }
}
