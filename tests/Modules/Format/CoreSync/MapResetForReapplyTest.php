<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;

/**
 * «Полное перепринятие» (кнопка админки) обязано сбрасывать applied_hash ТОЛЬКО у строк-сущностей
 * карты. Служебные метки bind (entity_type=bind_marker) — состояние ФАЗЫ, а не применённое значение:
 * SET applied_hash = NULL по всей таблице гасит активную метку прерванного bind ⇒ следующий прогон
 * уходит в full по частичной карте ⇒ ДУБЛИ каталога (ровно то, от чего метка и стоит) либо теряется
 * отметка «bind выполнен» ⇒ возврат петли D-SAT-BIND-LOOP-NEVER-APPLIES.
 *
 * БД не нужна: сущность поднимается без конструктора (он лезет в ServiceLocator), в неё
 * инжектятся фейковый queryFactory (отдаёт НАСТОЯЩИЙ Aura Update — SQL честный, не наш пересказ)
 * и db-рекордер.
 */
class MapResetForReapplyTest extends TestCase
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
     */
    private function hashResetQuery(array $queries): object
    {
        foreach ($queries as $query) {
            if (strpos($query->getStatement(), 'applied_hash') !== false) {
                return $query;
            }
        }

        $this->fail('resetForReapply не сбросил applied_hash');
    }

    public function testResetForReapplyDoesNotTouchBindMarkers(): void
    {
        [$entity, $recorder] = $this->makeEntity();

        $entity->resetForReapply();

        $query = $this->hashResetQuery($recorder->queries);
        $statement = $query->getStatement();
        $binds = $query->getBindValues();

        $this->assertStringContainsString('WHERE', $statement, 'сброс applied_hash обязан быть ограничен фильтром');
        $this->assertStringContainsString('entity_type', $statement, 'фильтр — по entity_type (метки bind не сущности карты)');

        // Метка bind не попадает под сброс, сущности карты — попадают. Проверяем по значениям
        // плейсхолдеров: список типов — замкнутый allow-list Contract::ENTITY_TYPES.
        $bound = [];
        array_walk_recursive($binds, static function ($value) use (&$bound): void {
            $bound[] = (string) $value;
        });
        $this->assertNotContains(Contract::ENTITY_BIND_MARKER, $bound, 'метка bind не сбрасывается');
        $this->assertContains(Contract::ENTITY_PRODUCT, $bound, 'товары перепринимаются');
        $this->assertContains(Contract::ENTITY_VARIANT, $bound, 'варианты перепринимаются');
        foreach (Contract::ENTITY_TYPES as $type) {
            $this->assertContains($type, $bound, 'сущность карты перепринимается: ' . $type);
        }
    }
}
