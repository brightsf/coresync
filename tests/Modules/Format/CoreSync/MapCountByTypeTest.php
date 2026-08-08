<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

/**
 * B. «Объём владения невидим» (брифа контекст): панель обязана показать, сколько товаров/вариантов/
 * категорий/брендов под управлением обмена — COUNT по __format__coresync_map с группировкой по типу,
 * один запрос (не N+1 по Contract::ENTITY_TYPES).
 *
 * БД не нужна: тот же приём, что MapResetForReapplyTest — сущность без конструктора, фейковые
 * queryFactory (отдаёт настоящий Aura Select — SQL честный) и db-рекордер, отдающий канонические
 * grouped-строки через свой results().
 */
class MapCountByTypeTest extends TestCase
{
    /**
     * @param array<string, int> $groupedRows entity_type => count, как их вернула бы БД
     * @return array{0:CoreSyncMapEntity, 1:object}
     */
    private function makeEntity(array $groupedRows): array
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
        };

        $entity = (new \ReflectionClass(CoreSyncMapEntity::class))->newInstanceWithoutConstructor();
        foreach (['queryFactory' => $queryFactory, 'db' => $recorder] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return [$entity, $recorder];
    }

    /**
     * KILL-ПРОБА: закомментировать `array_fill_keys(Contract::ENTITY_TYPES, 0)` (или итоговый merge) в
     * countByType() → отсутствующие в выборке типы (напр. redirect) пропадут из результата вместо 0 →
     * тест красный на assertSame с полным ключевым набором.
     */
    public function testCountByTypeZeroFillsMissingTypesAndKeepsPresentOnes(): void
    {
        [$entity] = $this->makeEntity(['product' => 3, 'variant' => 5]);

        $counts = $entity->countByType();

        $expected = array_fill_keys(Contract::ENTITY_TYPES, 0);
        $expected['product'] = 3;
        $expected['variant'] = 5;

        $this->assertSame($expected, $counts, 'все типы карты присутствуют в ответе, даже с нулём');
    }

    /** Фильтр обязан быть замкнутым allow-list Contract::ENTITY_TYPES (bind_marker — служебная метка, не сущность). */
    public function testQueryFiltersByAllowListedEntityTypes(): void
    {
        [$entity, $recorder] = $this->makeEntity([]);

        $entity->countByType();

        $this->assertCount(1, $recorder->queries);
        $statement = $recorder->queries[0]->getStatement();
        $binds = $recorder->queries[0]->getBindValues();

        $this->assertStringContainsString('GROUP BY', $statement, 'запрос группируется по entity_type — один запрос, не N+1');
        $this->assertStringContainsString('entity_type', $statement);

        $bound = [];
        array_walk_recursive($binds, static function ($value) use (&$bound): void {
            $bound[] = (string) $value;
        });
        $this->assertNotContains(Contract::ENTITY_BIND_MARKER, $bound, 'служебная метка bind не считается сущностью владения');
        foreach (Contract::ENTITY_TYPES as $type) {
            $this->assertContains($type, $bound);
        }
    }
}
