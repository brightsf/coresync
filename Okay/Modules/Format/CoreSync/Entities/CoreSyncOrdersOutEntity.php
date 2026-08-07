<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;

/**
 * Журнал доставки заказов/заявок ядру (FEAT-ORD-M, спека §5). Одна строка на выданную сущность:
 * `entity` (order|request) + `local_id` (id в `__orders`/`__callbacks`), `delivered_at` — момент
 * первой выдачи в pull, `acked_at` — момент ack ядра. Курсор pull'а живёт НЕ здесь (он = max id
 * выданной пачки, монотонный маркер модуля): таблица нужна двум путям —
 *  1. ack: пометить `acked_at` выданным `id <= cursor` и перевести заказы в «принят в обработку»;
 *  2. пинок: понять, есть ли невыданное (delivered_at IS NULL) — ускоритель, не транспорт.
 *
 * «Приспособить `__format__coresync_map`» ЗАПРЕЩЕНО (его уникальность entity_type+external_id и
 * reset-пути заточены под каталог) — это отдельная таблица (Init::ORDERS_OUT_TABLE).
 */
class CoreSyncOrdersOutEntity extends Entity
{
    protected static $fields = [
        'id',
        'entity',
        'local_id',
        'delivered_at',
        'acked_at',
    ];

    protected static $table = '__format__coresync_orders_out';
    protected static $tableAlias = 'cso';
    protected static $defaultOrderFields = [
        'id ASC',
    ];

    /** Таблица заказов ядра Okay (флип статуса на ack). */
    private const ORDERS_TABLE = '__orders';
    /** Таблица обратных звонков Okay (processed=1 на ack). */
    private const CALLBACKS_TABLE = '__callbacks';

    /**
     * Идемпотентно зафиксировать выдачу пачки: строки без записи в журнале получают `delivered_at`
     * (первая выдача), уже выданные — не трогаются (повторный pull того же диапазона не сдвигает
     * `delivered_at`). Атомарность не требуется: журнал доставки — append-only факт «выдано», не
     * состояние; повторная попытка безопасна.
     *
     * @param string    $entity Contract::OUT_ENTITY_ORDER|OUT_ENTITY_REQUEST
     * @param array<int> $localIds
     */
    public function recordDelivered(string $entity, array $localIds): void
    {
        $ids = $this->intIds($localIds);
        if ($ids === []) {
            return;
        }

        $existing = $this->deliveredIdsIn($entity, $ids);
        $now = date('Y-m-d H:i:s');
        foreach ($ids as $id) {
            if (isset($existing[$id])) {
                continue;
            }
            $insert = $this->queryFactory->newInsert();
            $insert->into(self::getTable())->cols([
                'entity'       => $entity,
                'local_id'     => $id,
                'delivered_at' => $now,
            ]);
            $this->db->query($insert);
        }
    }

    /**
     * Строки журнала, готовые к ack: выданные (`delivered_at IS NOT NULL`), ещё не подтверждённые
     * (`acked_at IS NULL`), с `local_id <= cursor`. Возвращает id (уже провалидированные int).
     *
     * @param string $entity
     * @param int    $cursor
     * @return array<int>
     */
    public function deliveredUnackedUpTo(string $entity, int $cursor): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['local_id'])
            ->from(self::getTable())
            ->where('entity = :entity')->bindValue('entity', $entity)
            ->where('delivered_at IS NOT NULL')
            ->where('acked_at IS NULL')
            ->where('local_id <= :cursor')->bindValue('cursor', $cursor);

        $this->db->query($select);
        $ids = [];
        foreach ((array) $this->db->results() as $row) {
            $ids[] = (int) (is_object($row) ? $row->local_id : $row);
        }

        return $ids;
    }

    /**
     * ACK заказов (спека §5, kill-проба §9.2): пометить `acked_at` выданным заказам `id <= cursor` И
     * перевести ИХ ЖЕ в статус-маркер «принят в обработку» — но ТОЛЬКО те, что ещё в дефолтном
     * «новом» статусе (`status_id = $defaultNewStatusId`); чужой менеджерский статус / `paid` / `closed`
     * не трогаем. Транзакционно ({@see self::runInTransaction}, graceful на непатченном ядре):
     * пометка и флип едут атомарно. Повторный ack того же курсора — no-op (пустой список к обработке).
     *
     * @param int $cursor            монотонный маркер (max id последней выданной пачки заказов)
     * @param int $markerStatusId    id строки-маркера в `__orders_status` (создаёт OrdersStatusMarker)
     * @param int $defaultNewStatusId id дефолтного «нового» статуса (первый по position, как в
     *                                OrdersEntity::add) — только его флипаем
     * @return array<int> заказы, попавшие под ack (для лога)
     * @throws CoreSyncException шаг транзакции не выполнился (rollback уже сделан)
     */
    public function ackOrders(int $cursor, int $markerStatusId, int $defaultNewStatusId): array
    {
        $ids = $this->deliveredUnackedUpTo(Contract::OUT_ENTITY_ORDER, $cursor);
        if ($ids === []) {
            return [];
        }

        $markAcked = $this->queryFactory->newUpdate();
        $markAcked->table(self::getTable())
            ->cols(['acked_at' => date('Y-m-d H:i:s')])
            ->where('entity = :entity')->bindValue('entity', Contract::OUT_ENTITY_ORDER)
            ->where('acked_at IS NULL')
            ->where('local_id IN (:ids)')->bindValue('ids', $ids);

        // Флип только «новых» заказов — идемпотентно и без затирания чужого статуса (спека §5).
        $flipStatus = $this->queryFactory->newUpdate();
        $flipStatus->table(self::ORDERS_TABLE)
            ->cols(['status_id' => $markerStatusId])
            ->where('id IN (:ids)')->bindValue('ids', $ids)
            ->where('status_id = :new')->bindValue('new', $defaultNewStatusId);

        $this->runInTransaction([$markAcked, $flipStatus], 'ackOrders: не удалось подтвердить пачку (rollback выполнен)');

        return $ids;
    }

    /**
     * ACK заявок: пометить `acked_at` выданным заявкам `id <= cursor` И проставить `processed=1` в
     * `__callbacks` тем же id. Транзакционно; повторный ack — no-op.
     *
     * @return array<int>
     * @throws CoreSyncException
     */
    public function ackRequests(int $cursor): array
    {
        $ids = $this->deliveredUnackedUpTo(Contract::OUT_ENTITY_REQUEST, $cursor);
        if ($ids === []) {
            return [];
        }

        $markAcked = $this->queryFactory->newUpdate();
        $markAcked->table(self::getTable())
            ->cols(['acked_at' => date('Y-m-d H:i:s')])
            ->where('entity = :entity')->bindValue('entity', Contract::OUT_ENTITY_REQUEST)
            ->where('acked_at IS NULL')
            ->where('local_id IN (:ids)')->bindValue('ids', $ids);

        $markProcessed = $this->queryFactory->newUpdate();
        $markProcessed->table(self::CALLBACKS_TABLE)
            ->cols(['processed' => 1])
            ->where('id IN (:ids)')->bindValue('ids', $ids);

        $this->runInTransaction([$markAcked, $markProcessed], 'ackRequests: не удалось подтвердить пачку (rollback выполнен)');

        return $ids;
    }

    /**
     * Уже выданные id из набора (для идемпотентной recordDelivered).
     *
     * @param array<int> $ids
     * @return array<int, true>
     */
    private function deliveredIdsIn(string $entity, array $ids): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['local_id'])
            ->from(self::getTable())
            ->where('entity = :entity')->bindValue('entity', $entity)
            ->where('local_id IN (:ids)')->bindValue('ids', $ids);

        $this->db->query($select);
        $map = [];
        foreach ((array) $this->db->results() as $row) {
            $map[(int) (is_object($row) ? $row->local_id : $row)] = true;
        }

        return $map;
    }

    /**
     * @param array<mixed> $localIds
     * @return array<int>
     */
    private function intIds(array $localIds): array
    {
        $ids = [];
        foreach ($localIds as $id) {
            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
            } elseif (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Исполнить упорядоченный список запросов в одной транзакции ядра (копия инварианта
     * CoreSyncMapEntity::runInTransaction — db приватна каждой сущности). query() ядра при ошибке SQL
     * возвращает false и НЕ бросает → проверяем каждый шаг: первый false → rollBack + CoreSyncException.
     * Guard непатченного ядра (b0fc15d): нет tx-API → тот же порядок запросов без транзакции.
     *
     * @param array<int, \Aura\SqlQuery\QueryInterface> $queries
     * @throws CoreSyncException
     */
    private function runInTransaction(array $queries, string $failMessage): void
    {
        if (!method_exists($this->db, 'beginTransaction')) {
            $this->runWithoutTransaction($queries, $failMessage);

            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($queries as $query) {
                if ($this->db->query($query) === false) {
                    throw new CoreSyncException($failMessage);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Fallback без tx-API (непатченное ядро): те же запросы в том же порядке, та же per-step проверка.
     *
     * @param array<int, \Aura\SqlQuery\QueryInterface> $queries
     * @throws CoreSyncException
     */
    private function runWithoutTransaction(array $queries, string $failMessage): void
    {
        foreach ($queries as $query) {
            if ($this->db->query($query) === false) {
                throw new CoreSyncException($failMessage);
            }
        }
    }
}
