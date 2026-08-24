<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Entity\Entity;
use Okay\Core\QueryFactory\AbstractQuery;
use Okay\Core\QueryFactory\Select;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\MapGateway;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

class MapReadIntegrityTest extends TestCase
{
    public function testGatewayRoutesEveryMapReadThroughCheckedEntityApi(): void
    {
        $map = new class {
            /** @var int */
            public $oneReads = 0;
            /** @var int */
            public $manyReads = 0;
            /** @var int */
            public $uncheckedReads = 0;

            public function findOneChecked(array $filter)
            {
                $this->oneReads++;

                return (object) [
                    'id' => 1,
                    'entity_type' => (string) ($filter['entity_type'] ?? Contract::ENTITY_PRODUCT),
                    'external_id' => (string) ($filter['external_id'] ?? 'external'),
                    'local_id' => 10,
                    'applied_hash' => 'hash',
                    'image_state' => null,
                ];
            }

            public function findChecked(array $filter): array
            {
                $this->manyReads++;

                return [(object) [
                    'id' => 1,
                    'entity_type' => (string) ($filter['entity_type'] ?? Contract::ENTITY_PRODUCT),
                    'external_id' => 'external',
                    'local_id' => 10,
                    'applied_hash' => 'hash',
                    'image_state' => null,
                ]];
            }

            public function findOne(array $filter)
            {
                $this->uncheckedReads++;

                return (object) [
                    'id' => 1,
                    'entity_type' => (string) ($filter['entity_type'] ?? Contract::ENTITY_PRODUCT),
                    'external_id' => (string) ($filter['external_id'] ?? 'external'),
                    'local_id' => 10,
                    'applied_hash' => 'hash',
                    'image_state' => null,
                ];
            }

            public function find(array $filter = []): array
            {
                $this->uncheckedReads++;

                return [(object) [
                    'id' => 1,
                    'entity_type' => (string) ($filter['entity_type'] ?? Contract::ENTITY_PRODUCT),
                    'external_id' => 'external',
                    'local_id' => 10,
                    'applied_hash' => 'hash',
                    'image_state' => null,
                ]];
            }
        };
        $gateway = new MapGateway($map);

        $this->assertNotNull($gateway->find(Contract::ENTITY_PRODUCT, 'product-1'));
        $this->assertSame(1, $gateway->count(Contract::ENTITY_PRODUCT));
        $this->assertCount(1, $gateway->findByLocalId(Contract::ENTITY_PRODUCT, 10));
        $this->assertSame(10, $gateway->localId(Contract::ENTITY_BRAND, 'brand-1'));
        $this->assertSame(['external' => 10], $gateway->allLocalIds(Contract::ENTITY_VARIANT));
        $this->assertSame(2, $map->oneReads);
        $this->assertSame(3, $map->manyReads);
        $this->assertSame(0, $map->uncheckedReads);
    }

    public function testBindCompletedMarkerReadIsCachedForOneGatewayRun(): void
    {
        $map = new class {
            /** @var int */
            public $reads = 0;

            public function findOneChecked(array $filter)
            {
                $this->reads++;

                return (object) [
                    'entity_type' => (string) $filter['entity_type'],
                    'external_id' => (string) $filter['external_id'],
                    'applied_hash' => Contract::BIND_MARKER_ACTIVE,
                ];
            }
        };
        $gateway = new MapGateway($map);

        $this->assertTrue($gateway->isBindCompleted());
        $this->assertTrue($gateway->isBindCompleted());
        $this->assertTrue($gateway->isBindCompleted());
        $this->assertSame(1, $map->reads, 'completed marker must not add one query per applied product');
    }

    public function testQueryFalseThrowsDatabaseUnavailableBeforeReadingStaleResult(): void
    {
        $this->assertTrue(
            method_exists(CoreSyncMapEntity::class, 'findOneChecked'),
            'CoreSyncMapEntity needs a checked read entrypoint'
        );

        $select = (new AuraQueryFactory('mysql'))->newSelect();
        $select->cols(['csm.*'])->from('__format__coresync_map AS csm');
        $entity = $this->getMockBuilder(CoreSyncMapEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSelect'])
            ->getMock();
        $entity->method('getSelect')->willReturn($select);
        $db = new class {
            /** @var bool */
            public $resultsCalled = false;

            public function query($query, $debug = false): bool
            {
                return false;
            }

            public function results($field = null, $mapped = null): array
            {
                $this->resultsCalled = true;

                return [(object) ['id' => 999]];
            }
        };
        $property = new \ReflectionProperty(Entity::class, 'db');
        $property->setAccessible(true);
        $property->setValue($entity, $db);

        try {
            $entity->findOneChecked([
                'entity_type' => Contract::ENTITY_PRODUCT,
                'external_id' => 'product-1',
            ]);
            $this->fail('query()===false must abort the map read');
        } catch (CoreSyncException $e) {
            $this->assertStringContainsString('БД недоступна', $e->getMessage());
        }
        $this->assertFalse($db->resultsCalled, 'stale/empty result cannot be read after query failure');
    }

    public function testCheckedVariantReadKeepsTheNativeFindQueryShape(): void
    {
        $db = $this->failingEntityDatabase();
        $variants = $this->entityWithQueryInfrastructure(VariantsEntity::class, $db);
        $map = $this->mapEntityWithDatabase($db);

        try {
            $map->findEntityChecked($variants, ['product_id' => 100]);
            $this->fail('query()===false from the native VariantsEntity::find() must abort the read');
        } catch (CoreSyncException $e) {
            $this->assertStringContainsString('БД недоступна', $e->getMessage());
        }

        $this->assertMatchesRegularExpression(
            '/JOIN\s+`?__currencies`?\s+AS\s+`?c`?/i',
            (string) end($db->statements),
            'checked reads must keep the currency JOIN owned by VariantsEntity::find()'
        );
        $this->assertFalse($db->resultsCalled, 'query failure must stop before stale entity results');
        $this->assertSame($db, $this->entityDatabase($variants), 'checked read must restore entity DB');
    }

    public function testCheckedVariantReadReturnsTheNativeFindRowsOnHealthyDatabase(): void
    {
        $rows = [(object) [
            'id' => 7,
            'product_id' => 100,
            'sku' => 'variant-7',
            'compare_price' => 12.0,
            'stock' => 3,
            'units' => 'pcs',
        ]];
        $db = $this->healthyEntityDatabase($rows);
        $nativeVariants = $this->entityWithQueryInfrastructure(VariantsEntity::class, $db);
        $checkedVariants = $this->entityWithQueryInfrastructure(VariantsEntity::class, $db);
        $map = $this->mapEntityWithDatabase($db);

        $expected = $nativeVariants->find(['product_id' => 100]);
        $actual = $map->findEntityChecked($checkedVariants, ['product_id' => 100]);

        $this->assertSame($expected, $actual, 'checked find must return the native entity rows');
        $this->assertSame($rows, $actual, 'checked find must not discard healthy database rows');
        $this->assertSame($db, $this->entityDatabase($checkedVariants), 'checked read must restore entity DB');
    }

    public function testCheckedCategoryReadKeepsTheNativeFindOneQueryShape(): void
    {
        $db = $this->failingEntityDatabase();
        $categories = $this->entityWithQueryInfrastructure(CategoriesEntity::class, $db);
        $map = $this->mapEntityWithDatabase($db);

        try {
            $map->findEntityOneChecked($categories, ['id' => 10]);
            $this->fail('query()===false from the native CategoriesEntity::findOne() must abort the read');
        } catch (CoreSyncException $e) {
            $this->assertStringContainsString('БД недоступна', $e->getMessage());
        }

        $this->assertMatchesRegularExpression(
            '/JOIN\s+`?__router_cache`?\s+AS\s+`?r`?/i',
            (string) end($db->statements),
            'checked reads must keep the router-cache JOIN owned by CategoriesEntity::findOne()'
        );
        $this->assertFalse($db->resultsCalled, 'query failure must stop before stale category results');
        $this->assertSame($db, $this->entityDatabase($categories), 'checked read must restore entity DB');
    }

    public function testCheckedCategoryReadReturnsTheNativeFindOneRowOnHealthyDatabase(): void
    {
        $category = (object) [
            'id' => 10,
            'parent_id' => 0,
            'url' => 'category-10',
        ];
        $db = $this->healthyEntityDatabase([$category]);
        $nativeCategories = $this->entityWithQueryInfrastructure(CategoriesEntity::class, $db);
        $checkedCategories = $this->entityWithQueryInfrastructure(CategoriesEntity::class, $db);
        $map = $this->mapEntityWithDatabase($db);

        $expected = $nativeCategories->findOne(['id' => 10]);
        $actual = $map->findEntityOneChecked($checkedCategories, ['id' => 10]);

        $this->assertSame($expected, $actual, 'checked findOne must return the native entity row');
        $this->assertSame($category, $actual, 'checked findOne must not discard a healthy database row');
        $this->assertSame($db, $this->entityDatabase($checkedCategories), 'checked read must restore entity DB');
    }

    public function testUrlPostCheckUsesTheSameCheckedDatabaseRead(): void
    {
        $this->assertTrue(
            method_exists(MapGateway::class, 'findEntityOne'),
            'url post-check needs the checked gateway entrypoint'
        );
        $map = new class {
            public function findEntityOneChecked($entity, array $filter)
            {
                throw new CoreSyncException('CoreSync apply: БД недоступна при проверке сохранённой сущности');
            }
        };
        $gateway = new MapGateway($map);
        $applier = (new \ReflectionClass(Applier::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(Applier::class, 'map');
        $property->setAccessible(true);
        $property->setValue($applier, $gateway);
        $urlMatches = new \ReflectionMethod(Applier::class, 'urlMatches');
        $urlMatches->setAccessible(true);

        $this->expectException(CoreSyncException::class);
        $this->expectExceptionMessage('БД недоступна');
        $urlMatches->invoke($applier, new \stdClass(), 10, 'expected-slug');
    }

    /** @return object{statements:array<int,string>,resultsCalled:bool} */
    private function failingEntityDatabase(): object
    {
        return new class {
            /** @var array<int, string> */
            public $statements = [];
            /** @var bool */
            public $resultsCalled = false;

            public function query($query, $debug = false): bool
            {
                $this->statements[] = (string) $query->getStatement();

                return false;
            }

            public function results($field = null, $mapped = null): array
            {
                $this->resultsCalled = true;

                return [(object) ['id' => 999]];
            }
        };
    }

    /** @param array<int, object> $rows */
    private function healthyEntityDatabase(array $rows): object
    {
        return new class($rows) {
            /** @var array<int, object> */
            private $rows;
            /** @var array<int, string> */
            public $statements = [];

            /** @param array<int, object> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function query($query, $debug = false): bool
            {
                $this->statements[] = (string) $query->getStatement();

                return true;
            }

            public function results($field = null, $mapped = null): array
            {
                if ($field === 'category_id') {
                    return [];
                }

                return $this->rows;
            }
        };
    }

    /** @return Entity */
    private function entityWithQueryInfrastructure(string $entityClass, object $db): Entity
    {
        $newSelect = function () use ($db): Select {
            $select = (new \ReflectionClass(Select::class))->newInstanceWithoutConstructor();
            $queryObject = new \ReflectionProperty(Select::class, 'queryObject');
            $queryObject->setAccessible(true);
            $queryObject->setValue($select, (new AuraQueryFactory('mysql'))->newSelect());
            $queryDb = new \ReflectionProperty(AbstractQuery::class, 'db');
            $queryDb->setAccessible(true);
            $queryDb->setValue($select, $db);
            $executed = new \ReflectionProperty(AbstractQuery::class, 'executed');
            $executed->setAccessible(true);
            $executed->setValue($select, false);

            return $select;
        };
        $queryFactory = new class($newSelect) {
            /** @var callable */
            private $newSelect;

            public function __construct(callable $newSelect)
            {
                $this->newSelect = $newSelect;
            }

            public function newSelect(): Select
            {
                return call_user_func($this->newSelect);
            }
        };
        $lang = new class {
            public function getQuery($tableAlias, $langTable, $langObject): array
            {
                return [];
            }

            public function getLangAlias($tableAlias, array $params = []): string
            {
                return 'l';
            }
        };
        $modulesFilters = new class {
            public function hasFilter($entityClass, $filterName): bool
            {
                return false;
            }
        };
        $entity = (new \ReflectionClass($entityClass))->newInstanceWithoutConstructor();
        $this->setEntityProperty($entity, 'db', $db);
        $this->setEntityProperty($entity, 'queryFactory', $queryFactory);
        $this->setEntityProperty($entity, 'lang', $lang);
        $this->setEntityProperty($entity, 'modulesFilters', $modulesFilters);
        $this->setEntityProperty($entity, 'select', $queryFactory->newSelect());

        return $entity;
    }

    private function mapEntityWithDatabase(object $db): CoreSyncMapEntity
    {
        $map = (new \ReflectionClass(CoreSyncMapEntity::class))->newInstanceWithoutConstructor();
        $this->setEntityProperty($map, 'db', $db);

        return $map;
    }

    /** @param object $entity */
    private function setEntityProperty($entity, string $name, object $value): void
    {
        $property = new \ReflectionProperty(Entity::class, $name);
        $property->setAccessible(true);
        $property->setValue($entity, $value);
    }

    /** @param object $entity */
    private function entityDatabase($entity): object
    {
        $property = new \ReflectionProperty(Entity::class, 'db');
        $property->setAccessible(true);

        return $property->getValue($entity);
    }
}
