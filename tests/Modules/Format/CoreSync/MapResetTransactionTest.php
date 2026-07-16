<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

/**
 * Атомарность reset-путей (D-OKAY-DB-NO-TX): resetForReapply/resetForRebind обёрнуты в транзакцию
 * ядра. query() при ошибке SQL возвращает false и НЕ бросает, поэтому каждый шаг проверяется; false
 * посреди → rollBack + громкий провал (CoreSyncException), commit НЕ достигается. Успешный путь:
 * begin → все запросы → commit. markers-first порядок в resetForRebind СОХРАНЁН внутри транзакции
 * (defense-in-depth; отдельный замок — BindTest::testRebindCrashBetweenQueriesDoesNotDuplicateCatalog).
 *
 * БД не нужна: сущность без конструктора, db-фейк записывает упорядоченный лог операций
 * (begin/query/commit/rollBack) и умеет вернуть false на заданном по счёту query.
 */
class MapResetTransactionTest extends TestCase
{
    /**
     * @param int|null $failOnQuery номер query (1-based), на котором вернуть false; null — все true
     * @return array{0:CoreSyncMapEntity, 1:object}
     */
    private function makeEntity(?int $failOnQuery = null): array
    {
        $db = new class($failOnQuery) {
            /** @var list<string> упорядоченный лог операций (begin/query/commit/rollBack) */
            public $log = [];
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

            public function beginTransaction(): bool
            {
                $this->log[] = 'begin';

                return true;
            }

            /** @param mixed $query */
            public function query($query, $debug = false): bool
            {
                $this->queryNo++;
                if ($this->failOnQuery !== null && $this->queryNo === $this->failOnQuery) {
                    $this->log[] = 'query:false';

                    return false;
                }
                $this->log[] = 'query';
                $this->statements[] = $query->getStatement();

                return true;
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

    public function testReapplyCommitsAfterAllQueries(): void
    {
        [$entity, $db] = $this->makeEntity();

        $entity->resetForReapply();

        $this->assertSame('begin', $db->log[0], 'транзакция открыта до запросов');
        $this->assertSame('commit', end($db->log), 'commit после всех запросов');
        $this->assertNotContains('rollBack', $db->log, 'на успешном пути отката нет');
        $this->assertSame(2, count($db->statements), 'reapply = два запроса под транзакцией');
    }

    public function testReapplyRollsBackAndThrowsWhenSecondQueryFails(): void
    {
        [$entity, $db] = $this->makeEntity(2); // второй query → false

        $thrown = false;
        try {
            $entity->resetForReapply();
        } catch (CoreSyncException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'false посреди → громкий провал (CoreSyncException)');
        $this->assertContains('rollBack', $db->log, 'откат при провале шага');
        $this->assertNotContains('commit', $db->log, 'commit не достигается при провале');
    }

    public function testRebindCommitsWithMarkersFirstOrder(): void
    {
        [$entity, $db] = $this->makeEntity();

        $entity->resetForRebind();

        $this->assertSame('begin', $db->log[0], 'транзакция открыта до запросов');
        $this->assertSame('commit', end($db->log), 'commit после всех запросов');
        $this->assertNotContains('rollBack', $db->log);

        // markers-first СОХРАНЁН внутри транзакции: UPDATE applied_hash (гашение меток) идёт до DELETE.
        $this->assertCount(2, $db->statements);
        $this->assertStringContainsString('applied_hash', $db->statements[0], 'сперва гашение меток (UPDATE)');
        $this->assertStringContainsString('DELETE', strtoupper($db->statements[1]), 'потом снос владения (DELETE)');
    }

    public function testRebindRollsBackAndThrowsWhenFirstQueryFails(): void
    {
        [$entity, $db] = $this->makeEntity(1); // первый query (гашение меток) → false

        $thrown = false;
        try {
            $entity->resetForRebind();
        } catch (CoreSyncException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'false на первом шаге → CoreSyncException');
        $this->assertContains('rollBack', $db->log, 'откат при провале первого шага');
        $this->assertNotContains('commit', $db->log);
        $this->assertCount(0, $db->statements, 'ни один запрос не исполнился успешно (DELETE не дошёл)');
    }
}
