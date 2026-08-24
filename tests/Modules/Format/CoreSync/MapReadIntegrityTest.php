<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Entity\Entity;
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
}
