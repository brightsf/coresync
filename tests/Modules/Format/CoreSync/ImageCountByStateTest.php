<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use PHPUnit\Framework\TestCase;

/**
 * B. «Состояние картинок невидимо» (брифа контекст): каталог может стоять applied при половине
 * картинок в failed, а UI не показывает ни одной цифры. countByState() — COUNT по state, один
 * запрос, зеркально для товарных (__format__coresync_images) и категорийных
 * (__format__coresync_category_images) картинок — тот же приём, что MapCountByTypeTest.
 */
class ImageCountByStateTest extends TestCase
{
    /**
     * @param class-string $entityClass
     * @param array<string, int> $groupedRows state => count
     * @return array{0:object, 1:object}
     */
    private function makeEntity(string $entityClass, array $groupedRows): array
    {
        $recorder = new class($groupedRows) {
            /** @var list<object> */
            public $queries = [];
            /** @var array<string, int> */
            private $groupedRows;

            public function __construct(array $groupedRows)
            {
                $this->groupedRows = $groupedRows;
            }

            /** @param mixed $query */
            public function query($query, $debug = false)
            {
                $this->queries[] = $query;

                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return $this->groupedRows;
            }

            /** Одиночный COUNT (countRetryableFailed): сумма подготовленных строк выборки. */
            public function result($field = null)
            {
                return array_sum($this->groupedRows);
            }
        };

        $queryFactory = new class {
            public function newSelect()
            {
                return (new AuraQueryFactory('mysql'))->newSelect();
            }

            public function newUpdate()
            {
                return (new AuraQueryFactory('mysql'))->newUpdate();
            }
        };

        $entity = (new \ReflectionClass($entityClass))->newInstanceWithoutConstructor();
        foreach (['queryFactory' => $queryFactory, 'db' => $recorder] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return [$entity, $recorder];
    }

    /**
     * KILL-ПРОБА (товарные картинки): закомментировать zero-fill в countByState() → 'failed' (0 в
     * выборке) пропадёт из результата → тест красный.
     */
    public function testProductImagesCountByStateZeroFillsMissingStates(): void
    {
        [$entity] = $this->makeEntity(CoreSyncImagesEntity::class, ['done' => 10, 'pending' => 2]);

        $counts = $entity->countByState();

        $this->assertSame(['pending' => 2, 'done' => 10, 'failed' => 0], $counts);
    }

    /**
     * KILL-ПРОБА (категорийные картинки): та же мутация на CoreSyncCategoryImagesEntity — независимая
     * реализация, отдельная проба.
     */
    public function testCategoryImagesCountByStateZeroFillsMissingStates(): void
    {
        [$entity] = $this->makeEntity(CoreSyncCategoryImagesEntity::class, ['failed' => 3]);

        $counts = $entity->countByState();

        $this->assertSame(['pending' => 0, 'done' => 0, 'failed' => 3], $counts);
    }

    public function testProductImagesQueryGroupsByState(): void
    {
        [$entity, $recorder] = $this->makeEntity(CoreSyncImagesEntity::class, []);

        $entity->countByState();

        $this->assertCount(1, $recorder->queries, 'один запрос, не по одному COUNT на состояние');
        $statement = $recorder->queries[0]->getStatement();
        $this->assertStringContainsString('GROUP BY', $statement);
        $this->assertStringContainsString('state', $statement);
    }

    /**
     * Предикат добора хвоста: ОДИН COUNT по (state=failed AND attempts < кап), а не выборка строк.
     * KILL-ПРОБА: убрать условие по attempts -> исчезнет bind max_attempts -> тест красный.
     *
     * @dataProvider imageEntityClasses
     * @param class-string $entityClass
     */
    public function testCountRetryableFailedCountsFailedRowsUnderTheAttemptsCap(string $entityClass): void
    {
        [$entity, $recorder] = $this->makeEntity($entityClass, ['failed' => 2]);

        $count = $entity->countRetryableFailed(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS);

        $this->assertSame(2, $count);
        $this->assertCount(1, $recorder->queries, 'один COUNT-запрос, не выборка строк');
        $query = $recorder->queries[0];
        $statement = $query->getStatement();
        $binds = $query->getBindValues();
        $this->assertStringContainsString('COUNT(*)', $statement);
        $this->assertStringContainsString('attempts < :max_attempts', $statement);
        $this->assertSame(Contract::IMAGE_STATE_FAILED, $binds['state'] ?? null);
        $this->assertSame(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS, $binds['max_attempts'] ?? null);
    }

    /**
     * Нулевой/отрицательный кап не должен превращаться в «посчитать всё»: запроса нет вовсе.
     *
     * @dataProvider imageEntityClasses
     * @param class-string $entityClass
     */
    public function testCountRetryableFailedWithoutAllowedAttemptsCountsNothing(string $entityClass): void
    {
        [$entity, $recorder] = $this->makeEntity($entityClass, ['failed' => 9]);

        $this->assertSame(0, $entity->countRetryableFailed(0));
        $this->assertSame([], $recorder->queries);
    }

    /**
     * Сброс попыток без полного перепринятия: attempts=0 и error_code=null ТОЛЬКО у failed-строк,
     * state не трогается (иначе это уже reapply).
     * KILL-ПРОБА: убрать `where state = failed` -> bind state исчезнет -> тест красный.
     *
     * @dataProvider imageEntityClasses
     * @param class-string $entityClass
     */
    public function testResetFailedAttemptsClearsAttemptsAndErrorCodeWithoutTouchingState(string $entityClass): void
    {
        [$entity, $recorder] = $this->makeEntity($entityClass, []);

        $entity->resetFailedAttempts();

        $this->assertCount(1, $recorder->queries);
        $query = $recorder->queries[0];
        $statement = $query->getStatement();
        $binds = $query->getBindValues();
        $this->assertStringContainsString('attempts', $statement);
        $this->assertStringContainsString('error_code', $statement);
        $this->assertStringNotContainsString('state =  :state,', $statement);
        $this->assertStringContainsString('WHERE', $statement);
        $this->assertSame(0, $binds['attempts'] ?? null);
        $this->assertNull($binds['error_code']);
        $this->assertArrayHasKey('error_code', $binds);
        $this->assertSame(Contract::IMAGE_STATE_FAILED, $binds['state'] ?? null);
        $this->assertNotContains(Contract::IMAGE_STATE_PENDING, $binds, 'state строки не переводится в pending');
    }

    /** @return array<string, array{0:class-string}> */
    public function imageEntityClasses(): array
    {
        return [
            'product images' => [CoreSyncImagesEntity::class],
            'category images' => [CoreSyncCategoryImagesEntity::class],
        ];
    }

    /** KILL-ПРОБА: снять очистку error_code из resetStatesToPending() -> тест красный. */
    public function testProductImagesResetClearsErrorCodeWithRetryState(): void
    {
        [$entity, $recorder] = $this->makeEntity(CoreSyncImagesEntity::class, []);

        $entity->resetStatesToPending();

        $this->assertCount(1, $recorder->queries);
        $query = $recorder->queries[0];
        $statement = $query->getStatement();
        $binds = $query->getBindValues();
        $this->assertStringContainsString('error_code', $statement);
        $this->assertArrayHasKey('error_code', $binds);
        $this->assertNull($binds['error_code']);
        $this->assertContains(Contract::IMAGE_STATE_PENDING, $binds);
        $this->assertContains(0, $binds);
    }
}
