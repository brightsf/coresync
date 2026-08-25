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
