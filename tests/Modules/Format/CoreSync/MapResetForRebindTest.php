<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

/**
 * Церемония «Связать заново» (D-SAT-BIND-REBIND-CEREMONY) на уровне данных. resetForRebind обязан:
 *   1) снести строки владения ТОЛЬКО товаров/вариантов (обнулить их count → shouldBind сработает);
 *   2) погасить ОБЕ метки bind (in_progress + completed);
 *   3) НЕ трогать владение категорий/брендов/свойств/редиректов (живёт независимо от связывания).
 *
 * БД не нужна: сущность поднимается без конструктора, в неё инжектятся фейковый queryFactory
 * (отдаёт НАСТОЯЩИЙ Aura Delete/Update — SQL честный, не наш пересказ) и db-рекордер (как в
 * MapResetForReapplyTest).
 */
class MapResetForRebindTest extends TestCase
{
    /**
     * @return array{0:CoreSyncMapEntity, 1:object}
     */
    private function makeEntity(): array
    {
        $recorder = new class {
            /** @var list<object> */
            public $queries = [];

            /**
             * @param mixed $query
             */
            public function query($query, $debug = false)
            {
                $this->queries[] = $query;

                return true;
            }

            // reset-путь теперь оборачивается в транзакцию ядра (D-OKAY-DB-NO-TX) — фейк db растёт
            // под новый контракт (no-op); ассерты этого теста не меняются.
            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            public function rollBack(): bool
            {
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
        foreach (['queryFactory' => $queryFactory, 'db' => $recorder] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return [$entity, $recorder];
    }

    /**
     * @param list<object> $queries
     * @return object|null первая query, чей SQL содержит подстроку
     */
    private function queryContaining(array $queries, string $needle)
    {
        foreach ($queries as $query) {
            if (strpos($query->getStatement(), $needle) !== false) {
                return $query;
            }
        }

        return null;
    }

    /**
     * @return list<string> все bound-значения (рекурсивно, как строки)
     */
    private function flatBinds(object $query): array
    {
        $flat = [];
        $binds = $query->getBindValues();
        array_walk_recursive($binds, static function ($value) use (&$flat): void {
            $flat[] = (string) $value;
        });

        return $flat;
    }

    public function testResetForRebindDeletesOnlyProductVariantOwnership(): void
    {
        [$entity, $recorder] = $this->makeEntity();

        $entity->resetForRebind();

        $delete = $this->queryContaining($recorder->queries, 'DELETE');
        $this->assertNotNull($delete, 'rebind сносит строки владения (DELETE)');
        $this->assertStringContainsString('entity_type', $delete->getStatement(), 'снос ограничен entity_type');

        $binds = $this->flatBinds($delete);
        $this->assertContains(Contract::ENTITY_PRODUCT, $binds, 'товары сносятся');
        $this->assertContains(Contract::ENTITY_VARIANT, $binds, 'варианты сносятся');
        // Владение словарей/редиректов и служебные метки под снос НЕ попадают.
        foreach ([Contract::ENTITY_CATEGORY, Contract::ENTITY_BRAND, Contract::ENTITY_FEATURE, Contract::ENTITY_REDIRECT, Contract::ENTITY_BIND_MARKER] as $kept) {
            $this->assertNotContains($kept, $binds, 'rebind не сносит владение/метку: ' . $kept);
        }
    }

    public function testResetForRebindClearsBothBindMarkers(): void
    {
        [$entity, $recorder] = $this->makeEntity();

        $entity->resetForRebind();

        // Метки гасятся одним UPDATE applied_hash=NULL WHERE entity_type = bind_marker (пара
        // in_progress+completed делит entity_type, поэтому один запрос снимает обе).
        $update = $this->queryContaining($recorder->queries, 'applied_hash');
        $this->assertNotNull($update, 'rebind гасит метки bind (UPDATE applied_hash)');
        $this->assertStringContainsString('NULL', strtoupper($update->getStatement()), 'applied_hash → NULL');
        $this->assertContains(Contract::ENTITY_BIND_MARKER, $this->flatBinds($update), 'фильтр по bind_marker');
    }
}
