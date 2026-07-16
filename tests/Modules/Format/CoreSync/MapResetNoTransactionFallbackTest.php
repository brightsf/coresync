<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

/**
 * Guard непатченного ядра (FIX-раунд). Транзакционное API добавлено В ЯДРО Okay\Core\Database
 * отдельным коммитом, но артефакт самообновления пакует ТОЛЬКО каталог CoreSync/ — правка ядра до
 * сателлита не доезжает. На витрине с непатченным ядром db НЕ имеет beginTransaction()/commit()/
 * rollBack(); безусловный вызов = PHP fatal «Call to undefined method» на «Связать заново»/reapply.
 *
 * runInTransaction проверяет method_exists($this->db, 'beginTransaction') и на ядре без tx-API
 * исполняет те же запросы в ТОМ ЖЕ порядке без транзакции (пред-harden семантика): markers-first
 * порядок в resetForRebind сохранён (defense-in-depth), per-step query()===false → тот же громкий
 * CoreSyncException, отката физически нет.
 *
 * БД не нужна: db-фейк намеренно БЕЗ tx-методов (только query()) — отсутствие beginTransaction() и
 * есть тестируемое условие. queryFactory отдаёт настоящий Aura Update/Delete (честный SQL).
 */
class MapResetNoTransactionFallbackTest extends TestCase
{
    /**
     * @param int|null $failOnQuery номер query (1-based), на котором вернуть false; null — все true
     * @return array{0:CoreSyncMapEntity, 1:object}
     */
    private function makeEntity(?int $failOnQuery = null): array
    {
        // Фейк непатченного ядра: НЕТ beginTransaction/commit/rollBack. method_exists() по нему = false.
        $db = new class($failOnQuery) {
            /** @var list<string> SQL успешно исполненных query в порядке вызова */
            public $statements = [];
            /** @var int */
            private $queryNo = 0;
            /** @var int|null */
            private $failOnQuery;

            public function __construct(?int $failOnQuery)
            {
                $this->failOnQuery = $failOnQuery;
            }

            /** @param mixed $query */
            public function query($query, $debug = false): bool
            {
                $this->queryNo++;
                if ($this->failOnQuery !== null && $this->queryNo === $this->failOnQuery) {
                    return false;
                }
                $this->statements[] = $query->getStatement();

                return true;
            }
        };

        $queryFactory = new class {
            public function newUpdate()
            {
                return (new AuraQueryFactory('mysql'))->newUpdate();
            }

            public function newDelete()
            {
                return (new AuraQueryFactory('mysql'))->newDelete();
            }
        };

        $entity = (new \ReflectionClass(CoreSyncMapEntity::class))->newInstanceWithoutConstructor();
        foreach (['queryFactory' => $queryFactory, 'db' => $db] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return [$entity, $db];
    }

    public function testFakeDbHasNoTransactionApi(): void
    {
        // Предпосылка теста: db-фейк действительно моделирует непатченное ядро (иначе тест уходит в
        // tx-ветку и ничего про fallback не доказывает).
        [, $db] = $this->makeEntity();
        $this->assertFalse(method_exists($db, 'beginTransaction'), 'фейк моделирует ядро БЕЗ tx-API');
    }

    public function testReapplyRunsWithoutTransactionOnUnpatchedCore(): void
    {
        [$entity, $db] = $this->makeEntity();

        $entity->resetForReapply();

        // Оба запроса исполнены, в порядке передачи; транзакцию открыть было нечем — и не пытались
        // (иначе был бы fatal на beginTransaction ещё до сюда).
        $this->assertCount(2, $db->statements, 'reapply = два запроса без транзакции');
    }

    public function testRebindRunsWithoutTransactionPreservingMarkersFirstOrder(): void
    {
        [$entity, $db] = $this->makeEntity();

        $entity->resetForRebind();

        // markers-first СОХРАНЁН и в fallback-ветке: UPDATE applied_hash (гашение меток) идёт ДО DELETE
        // владения. Это kill-проба порядка: свап [clearMarkers, deleteEntities] в resetForRebind
        // (или в runWithoutTransaction) красит именно эти два ассерта.
        $this->assertCount(2, $db->statements, 'rebind = два запроса без транзакции');
        $this->assertStringContainsString('applied_hash', $db->statements[0], 'сперва гашение меток (UPDATE)');
        $this->assertStringContainsString('DELETE', strtoupper($db->statements[1]), 'потом снос владения (DELETE)');
    }

    public function testReapplyThrowsLoudlyWhenSecondQueryFailsWithoutTransaction(): void
    {
        [$entity, $db] = $this->makeEntity(2); // второй query → false

        $thrown = false;
        try {
            $entity->resetForReapply();
        } catch (CoreSyncException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'false посреди → тот же громкий CoreSyncException и без транзакции');
        // Первый запрос успел исполниться (отката физически нет — пред-harden модель), второй провалил.
        $this->assertCount(1, $db->statements, 'первый запрос прошёл, второй провалился — отката нет');
    }

    public function testRebindThrowsLoudlyWhenFirstQueryFailsWithoutTransaction(): void
    {
        [$entity, $db] = $this->makeEntity(1); // первый query (гашение меток) → false

        $thrown = false;
        try {
            $entity->resetForRebind();
        } catch (CoreSyncException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'false на первом шаге → CoreSyncException и без транзакции');
        $this->assertCount(0, $db->statements, 'DELETE владения не дошёл (провал на гашении меток)');
    }
}
