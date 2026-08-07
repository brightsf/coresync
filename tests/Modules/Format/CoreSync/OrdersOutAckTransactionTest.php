<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncOrdersOutEntity;
use PHPUnit\Framework\TestCase;

/**
 * ACK-транзакция журнала доставки (acceptance §2): пометка `acked_at` и флип статуса заказа едут
 * атомарно (begin → ack-update → flip-update → commit); провал шага → rollBack + CoreSyncException,
 * commit не достигается. Повторный ack без невыданных строк — no-op (транзакция не открывается).
 * recordDelivered идемпотентна (уже выданные не переинсертит).
 *
 * БД не нужна: fake-db логирует операции и SQL, отдаёт заготовленные результаты SELECT'ов.
 */
class OrdersOutAckTransactionTest extends TestCase
{
    /**
     * @param array<int, array<int, object>> $resultsQueue результаты по порядку SELECT-вызовов
     * @param int|null                       $failOnQuery  номер query (1-based) → false; null — все true
     * @return array{0:CoreSyncOrdersOutEntity,1:object}
     */
    private function makeEntity(array $resultsQueue, ?int $failOnQuery = null): array
    {
        $db = new class($resultsQueue, $failOnQuery) {
            /** @var list<string> */
            public $log = [];
            /** @var list<string> */
            public $statements = [];
            /** @var list<array<string, mixed>> bind-значения успешно исполненных query (параллельно statements) */
            public $binds = [];
            /** @var array<int, array<int, object>> */
            private $resultsQueue;
            /** @var array<int, object> */
            private $lastResults = [];
            /** @var int */
            private $queryNo = 0;
            /** @var int|null */
            private $failOnQuery;

            public function __construct(array $resultsQueue, ?int $failOnQuery)
            {
                $this->resultsQueue = array_values($resultsQueue);
                $this->failOnQuery = $failOnQuery;
            }

            public function beginTransaction(): bool
            {
                $this->log[] = 'begin';

                return true;
            }

            /** @param mixed $query */
            public function query($query, $debug = false): bool
            {
                $this->queryNo++;
                $this->lastResults = array_shift($this->resultsQueue) ?? [];
                if ($this->failOnQuery !== null && $this->queryNo === $this->failOnQuery) {
                    $this->log[] = 'query:false';

                    return false;
                }
                $this->log[] = 'query';
                $this->statements[] = $query->getStatement();
                $this->binds[] = (array) $query->getBindValues();

                return true;
            }

            /** @return array<int, object> */
            public function results($field = null, $mapped = null): array
            {
                return $this->lastResults;
            }

            public function commit(): bool
            {
                $this->log[] = 'commit';

                return true;
            }

            public function rollBack(): bool
            {
                $this->log[] = 'rollBack';

                return true;
            }
        };

        $queryFactory = new class {
            public function newSelect()
            {
                return (new AuraQueryFactory('mysql'))->newSelect();
            }

            public function newInsert()
            {
                return (new AuraQueryFactory('mysql'))->newInsert();
            }

            public function newUpdate()
            {
                return (new AuraQueryFactory('mysql'))->newUpdate();
            }
        };

        $entity = (new \ReflectionClass(CoreSyncOrdersOutEntity::class))->newInstanceWithoutConstructor();
        foreach (['queryFactory' => $queryFactory, 'db' => $db] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return [$entity, $db];
    }

    private function rows(int ...$ids): array
    {
        return array_map(fn (int $id) => (object) ['local_id' => $id], $ids);
    }

    /**
     * Разрезать UPDATE на SET- и WHERE-части (fail при отсутствии WHERE): раздельные ассерты не дают
     * SET-части удовлетворить проверку guard-условия (тавтология вердикта F1).
     *
     * @return array{0:string, 1:string} [set, where]
     */
    private function splitSetWhere(string $statement): array
    {
        $parts = preg_split('/\bWHERE\b/i', $statement, 2);
        $this->assertCount(2, $parts, 'UPDATE обязан иметь WHERE-часть: ' . $statement);

        return [$parts[0], $parts[1]];
    }

    public function testAckOrdersCommitsAckThenFlipInOneTransaction(): void
    {
        // SELECT deliveredUnacked → [5,6]; далее транзакция.
        [$entity, $db] = $this->makeEntity([$this->rows(5, 6)]);

        $acked = $entity->ackOrders(6, 99 /* marker */, 1 /* defaultNew */);

        $this->assertSame([5, 6], $acked);
        $this->assertSame('begin', $db->log[1], 'после SELECT открыта транзакция');
        $this->assertSame('commit', end($db->log), 'commit после обоих UPDATE');
        $this->assertNotContains('rollBack', $db->log);

        // statements: [0]=SELECT deliveredUnacked, [1]=ack-update, [2]=flip-update.
        // Порядок внутри транзакции: сперва пометка acked (orders_out), затем флип статуса (__orders).
        $this->assertCount(3, $db->statements);
        $this->assertStringContainsString('acked_at', $db->statements[1]);

        // Замок инварианта «флипаем ТОЛЬКО новые» — SET- и WHERE-части раздельно (REJECT-fix F1:
        // один contains по всему SQL удовлетворялся SET-частью и не ловил снос WHERE-guard'а).
        [$flipSet, $flipWhere] = $this->splitSetWhere($db->statements[2]);
        $this->assertStringContainsString('status_id', $flipSet, 'SET: флип статуса заказа');
        $this->assertStringContainsString('status_id', $flipWhere, 'WHERE: guard «только новые» обязателен');
    }

    /**
     * KILL-ПРОБА-инвариант (вердикт F1, спека §5): ack флипает статус ТОЛЬКО заказам в дефолтном
     * «новом» статусе. Заказ, чей статус менеджер витрины уже изменил (доставлен-но-неподтверждён),
     * под ack получает `acked_at` (журнал), но его `status_id` НЕ затирается — это гарантирует
     * WHERE-guard `status_id = :new` flip-запроса. Мутация «удалить ->where('status_id = :new')»
     * обязана красить этот тест: без guard'а UPDATE зацепит ВСЕ выданные id независимо от статуса.
     */
    public function testAckOrdersFlipsOnlyDefaultNewStatus(): void
    {
        [$entity, $db] = $this->makeEntity([$this->rows(5, 6)]);

        $entity->ackOrders(6, 99 /* marker */, 1 /* defaultNew */);

        // (1) Пометка acked_at идёт ВСЕМ выданным без условия по статусу заказа (журнал — факт ack).
        $this->assertStringNotContainsString('status_id', $db->statements[1], 'acked_at не зависит от статуса заказа');

        // (2) Флип ограничен «новыми»: WHERE-часть держит И адресацию по id, И guard по статусу.
        [, $flipWhere] = $this->splitSetWhere($db->statements[2]);
        $this->assertMatchesRegularExpression(
            '/status_id\s*=\s*:new/',
            $flipWhere,
            'WHERE flip-запроса обязан ограничивать статус guard-условием status_id = :new'
        );
        $this->assertStringContainsString('id IN', $flipWhere, 'адресация по выданным id сохраняется');

        // (3) Guard забинжен ИМЕННО дефолтным «новым» статусом (не маркером): не-новый заказ
        //     (status_id ≠ 1) под этот UPDATE структурно не попадает — его статус цел.
        $flipBinds = $db->binds[2];
        $this->assertSame(1, $flipBinds['new'] ?? null, 'guard бинжен дефолтным «новым» статусом');
        $this->assertNotSame(99, $flipBinds['new'] ?? null, 'guard — это НЕ маркер-статус');
    }

    public function testAckOrdersNoDeliveredIsNoop(): void
    {
        [$entity, $db] = $this->makeEntity([$this->rows()]); // SELECT → пусто

        $acked = $entity->ackOrders(6, 99, 1);

        $this->assertSame([], $acked, 'повторный ack без невыданных — no-op');
        $this->assertNotContains('begin', $db->log, 'транзакция не открывается');
        $this->assertNotContains('commit', $db->log);
    }

    public function testAckOrdersRollsBackAndThrowsWhenFlipFails(): void
    {
        // query#1 = SELECT, #2 = ack-update, #3 = flip-update → false на #3.
        [$entity, $db] = $this->makeEntity([$this->rows(5, 6)], 3);

        $thrown = false;
        try {
            $entity->ackOrders(6, 99, 1);
        } catch (CoreSyncException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'провал шага транзакции → CoreSyncException');
        $this->assertContains('rollBack', $db->log);
        $this->assertNotContains('commit', $db->log);
    }

    public function testAckRequestsCommitsAckThenProcessed(): void
    {
        [$entity, $db] = $this->makeEntity([$this->rows(7)]);

        $acked = $entity->ackRequests(7);

        $this->assertSame([7], $acked);
        $this->assertSame('commit', end($db->log));
        // statements: [0]=SELECT, [1]=ack-update, [2]=processed-update.
        $this->assertCount(3, $db->statements);
        $this->assertStringContainsString('acked_at', $db->statements[1]);
        $this->assertStringContainsString('processed', $db->statements[2], 'заявки → processed=1');
    }

    public function testRecordDeliveredSkipsAlreadyDelivered(): void
    {
        // deliveredIdsIn SELECT → [5] уже выдан; вставляем только 6.
        [$entity, $db] = $this->makeEntity([$this->rows(5)]);

        $entity->recordDelivered('order', [5, 6]);

        // statements: [0]=SELECT deliveredIdsIn (5 уже есть), [1]=INSERT только для 6.
        $this->assertCount(2, $db->statements, 'один INSERT — только для невыданного (5 пропущен)');
        $this->assertStringContainsStringIgnoringCase('INSERT', $db->statements[1]);
    }
}
