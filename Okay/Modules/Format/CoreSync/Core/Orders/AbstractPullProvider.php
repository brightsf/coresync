<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncOrdersOutEntity;
use Psr\Log\LoggerInterface;

/**
 * Общий цикл pull-выдачи заказов/заявок ядру (FEAT-ORD-M, спека §5). Зеркало ядрового
 * {@see \App\Channels\Satellite\Orders\AbstractSatellitePullService}, но со стороны сателлита:
 *   выборка по курсору (`id > cursor`, лимит ≤ 100) → сериализация по схеме → фиксация выдачи
 *   в журнале → конверт `{schema_version, <key>, cursor, has_more}`.
 *
 * Курсор — монотонный маркер модуля = max id выданной пачки (opaque-строка для ядра). Выборка
 * `id > cursor ORDER BY id ASC` детерминирована и НЕ мутирует источник ⇒ повторный pull того же
 * курсора (пока нет ack) отдаёт ТУ ЖЕ пачку (идемпотентность, acceptance §1). Ядро двигает свой
 * курсор только после ack; до ack `id <= cursor` переизвлекаются.
 *
 * DB-обращения вынесены в protected-швы (fetchRows/hasMoreBeyond/recordDelivered) для юнит-тестов
 * без живой БД (паттерн Describer::routePath).
 */
abstract class AbstractPullProvider
{
    /** @var Database */
    protected $db;
    /** @var QueryFactory */
    protected $queryFactory;
    /** @var EntityFactory */
    protected $entityFactory;
    /** @var LoggerInterface|null */
    protected $logger;

    public function __construct(
        Database $db,
        QueryFactory $queryFactory,
        EntityFactory $entityFactory,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->queryFactory = $queryFactory;
        $this->entityFactory = $entityFactory;
        $this->logger = $logger;
    }

    /** `order` | `request` — тип строки журнала доставки. */
    abstract protected function entityType(): string;

    /** Ключ массива записей в конверте (`orders` | `requests`). */
    abstract protected function responseKey(): string;

    /** Таблица-источник (`__orders` | `__callbacks`). */
    abstract protected function sourceTable(): string;

    /**
     * Сериализовать пачку сырых строк источника в записи схемы (order/request.schema.json).
     * Наследник добирает свой контекст (позиции/валюта/обратная карта).
     *
     * @param array<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    abstract protected function serializeBatch(array $rows): array;

    /**
     * Прогнать pull: конверт по схеме контракта.
     *
     * @param string|null $cursor монотонный маркер (max id прошлой пачки) или null — с начала
     * @param int         $limit  запрошенный ядром размер пачки (клампится ≤ ORDER_PULL_LIMIT_MAX)
     * @return array<string, mixed> {schema_version, <responseKey>, cursor, has_more}
     */
    public function pull(?string $cursor, int $limit): array
    {
        $cursorInt = ($cursor !== null && $cursor !== '' && ctype_digit($cursor)) ? (int) $cursor : 0;
        $limit = $this->clampLimit($limit);

        $rows = $this->fetchRows($cursorInt, $limit);
        $key = $this->responseKey();

        if ($rows === []) {
            // Ничего нового: курсор не двигаем, has_more честно false (зеркало ядрового recordSuccess).
            return [
                'schema_version' => Contract::SCHEMA_VERSION,
                $key             => [],
                'cursor'         => $cursor,
                'has_more'       => false,
            ];
        }

        $records = $this->serializeBatch($rows);
        $maxId = $this->maxId($rows);

        $this->recordDelivered($this->rowIds($rows));

        $result = [
            'schema_version' => Contract::SCHEMA_VERSION,
            $key             => $records,
            'cursor'         => (string) $maxId,
            'has_more'       => $this->hasMoreBeyond($maxId),
        ];

        if ($this->logger !== null) {
            $this->logger->info('CoreSync pull_' . $key . ': выдана пачка ' . count($records) . ' (cursor=' . $maxId . ')');
        }

        return $result;
    }

    /**
     * Сырая выборка пачки по курсору (шов для тестов). SELECT * FROM source WHERE id > cursor
     * ORDER BY id ASC LIMIT limit.
     *
     * @return array<int, object>
     */
    protected function fetchRows(int $cursor, int $limit): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['*'])
            ->from($this->sourceTable())
            ->where('id > :cursor')->bindValue('cursor', $cursor)
            ->orderBy(['id ASC'])
            ->limit($limit);

        $this->db->query($select);

        return array_values((array) $this->db->results());
    }

    /** Есть ли ещё записи за maxId (честный has_more; шов для тестов). */
    protected function hasMoreBeyond(int $maxId): bool
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['id'])
            ->from($this->sourceTable())
            ->where('id > :max')->bindValue('max', $maxId)
            ->limit(1);

        $this->db->query($select);

        return $this->db->results() !== [];
    }

    /**
     * Зафиксировать выдачу пачки в журнале доставки (шов для тестов).
     *
     * @param array<int> $localIds
     */
    protected function recordDelivered(array $localIds): void
    {
        /** @var CoreSyncOrdersOutEntity $out */
        $out = $this->entityFactory->get(CoreSyncOrdersOutEntity::class);
        $out->recordDelivered($this->entityType(), $localIds);
    }

    /**
     * @param array<int, object> $rows
     * @return array<int>
     */
    protected function rowIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * @param array<int, object> $rows
     */
    private function maxId(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }

        return min($limit, Contract::ORDER_PULL_LIMIT_MAX);
    }

    // --- общие хелперы сериализации (снимок как есть, спека §4) ---

    /**
     * Снимок суммы по схеме: {amount: decimal-строка, currency} ЛИБО null (currency обязателен —
     * additionalProperties:false у total). Нет валюты канала → total=null (частичный запрещён схемой).
     *
     * @return array{amount:string,currency:string}|null
     */
    protected function money($amount, ?string $currency): ?array
    {
        if ($currency === null || $currency === '' || !is_numeric($amount)) {
            return null;
        }

        return ['amount' => (string) $amount, 'currency' => $currency];
    }

    protected function stringOrNull($value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return (is_int($value) || is_float($value)) ? (string) $value : null;
    }

    /** ISO-8601 из datetime витрины ('Y-m-d H:i:s') либо null. */
    protected function isoOrNull($value): ?string
    {
        if (!is_string($value) || trim($value) === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : date('c', $ts);
    }
}
