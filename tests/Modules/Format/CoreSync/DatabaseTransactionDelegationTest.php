<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Database;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Аддитивные транзакционные методы ядра (D-OKAY-DB-NO-TX): beginTransaction/commit/rollBack/
 * inTransaction — тонкие делегаты в private $pdo (Aura\Sql\ExtendedPdo штатно умеет). БД не нужна:
 * Database поднимается без конструктора (он коннектится), в private $pdo инжектится рекордер;
 * проверяется факт делегирования и проброс возвращаемого значения. Существующие методы/сигнатуры
 * не трогаются — это доказывает CoreSync-суита в целом (baseline 229/892) плюс git diff.
 */
class DatabaseTransactionDelegationTest extends TestCase
{
    /**
     * @return array{0:Database, 1:object}
     */
    private function makeDatabase(bool $pdoReturn = true, bool $inTx = false): array
    {
        $pdo = new class($pdoReturn, $inTx) {
            /** @var list<string> */
            public $calls = [];
            /** @var bool */
            private $ret;
            /** @var bool */
            private $inTx;

            public function __construct(bool $ret, bool $inTx)
            {
                $this->ret = $ret;
                $this->inTx = $inTx;
            }

            public function beginTransaction(): bool
            {
                $this->calls[] = 'beginTransaction';

                return $this->ret;
            }

            public function commit(): bool
            {
                $this->calls[] = 'commit';

                return $this->ret;
            }

            public function rollBack(): bool
            {
                $this->calls[] = 'rollBack';

                return $this->ret;
            }

            public function inTransaction(): bool
            {
                $this->calls[] = 'inTransaction';

                return $this->inTx;
            }

            /** Database::__destruct() зовёт disconnect() — no-op, БД в юните нет. */
            public function disconnect(): void
            {
            }
        };

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionProperty(Database::class, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return [$db, $pdo];
    }

    public function testBeginTransactionDelegatesAndPropagatesResult(): void
    {
        [$db, $pdo] = $this->makeDatabase(true);

        $this->assertTrue($db->beginTransaction());
        $this->assertSame(['beginTransaction'], $pdo->calls);
    }

    public function testCommitDelegatesAndPropagatesResult(): void
    {
        [$db, $pdo] = $this->makeDatabase(true);

        $this->assertTrue($db->commit());
        $this->assertSame(['commit'], $pdo->calls);
    }

    public function testRollBackDelegatesAndPropagatesResult(): void
    {
        [$db, $pdo] = $this->makeDatabase(false);

        $this->assertFalse($db->rollBack(), 'проброс false из pdo->rollBack()');
        $this->assertSame(['rollBack'], $pdo->calls);
    }

    public function testInTransactionDelegatesState(): void
    {
        [$dbFalse, $pdoFalse] = $this->makeDatabase(true, false);
        $this->assertFalse($dbFalse->inTransaction());
        $this->assertSame(['inTransaction'], $pdoFalse->calls);

        [$dbTrue] = $this->makeDatabase(true, true);
        $this->assertTrue($dbTrue->inTransaction());
    }
}
