<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Modules\EntityField;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

/**
 * Схема durable identity категорий/брендов: fresh install, runtime-регистрация и upgrade уже
 * установленного CoreSync обязаны описывать одну и ту же пару module-owned полей.
 */
class DictionaryIdentitySchemaTest extends TestCase
{
    public function testFreshInstallAndRuntimeRegisterBothModuleOwnedFields(): void
    {
        $init = new class extends Init {
            /** @var array<string, EntityField> */
            public $migrated = [];
            /** @var array<string, string> */
            public $registered = [];

            public function __construct()
            {
            }

            public function installDictionaryIdentityForTest(): void
            {
                $this->installDictionaryIdentityFields();
            }

            public function registerDictionaryIdentityForTest(): void
            {
                $this->registerDictionaryIdentityFields();
            }

            protected function dictionaryIdentitySchemaVersion(): ?string
            {
                return '1.5.7';
            }

            protected function migrateEntityField($entityClassName, EntityField $field)
            {
                $this->migrated[$entityClassName] = $field;
            }

            protected function registerEntityField($entityClassName, $fieldName, $isLang = false)
            {
                $this->registered[$entityClassName] = (string) $fieldName;
            }
        };

        $init->installDictionaryIdentityForTest();
        $init->registerDictionaryIdentityForTest();

        $this->assertSame([CategoriesEntity::class, BrandsEntity::class], array_keys($init->migrated));
        foreach ($init->migrated as $field) {
            $this->assertSame('coresync_external_id', $field->getName());
            $this->assertSame('varchar(64)', $field->getType());
            $this->assertTrue($field->isNullable());
            $this->assertNull($field->getDefault());
        }
        $this->assertSame([
            CategoriesEntity::class => 'coresync_external_id',
            BrandsEntity::class     => 'coresync_external_id',
        ], $init->registered);
    }

    /**
     * Окно апгрейда: после свапа файлов модуля (применённая версия схемы < 1.4.0) колонки ещё нет,
     * поэтому маркер словарей регистрировать нельзя - иначе ядро кладёт `coresync_external_id`
     * в SELECT категорий и каждый запрос падает 1054 Unknown column до догона схемы тиком.
     */
    public function testSchemaVersionBelowThresholdSkipsRegistration(): void
    {
        foreach (['1.2.0', '1.3.0', '1.3.9'] as $appliedVersion) {
            $init = $this->initWithSchemaVersion($appliedVersion);
            $init->registerDictionaryIdentityForTest();

            $this->assertSame(
                [],
                $init->registered,
                sprintf('Applied schema version "%s" is below 1.4.0: nothing may be registered.', $appliedVersion)
            );
        }
    }

    /** Порог включительный: ровно на 1.4.0 колонки уже приехали, регистрируем обе пары. */
    public function testThresholdSchemaVersionRegistersBothModuleOwnedFields(): void
    {
        $init = $this->initWithSchemaVersion('1.4.0');
        $init->registerDictionaryIdentityForTest();

        $this->assertSame([
            [CategoriesEntity::class, 'coresync_external_id'],
            [BrandsEntity::class, 'coresync_external_id'],
        ], $init->registered);
    }

    /** Здоровая витрина (схема давно догнана) ведёт себя ровно как до гейта. */
    public function testSchemaVersionAboveThresholdRegistersBothModuleOwnedFields(): void
    {
        $init = $this->initWithSchemaVersion('1.5.7');
        $init->registerDictionaryIdentityForTest();

        $this->assertSame([
            [CategoriesEntity::class, 'coresync_external_id'],
            [BrandsEntity::class, 'coresync_external_id'],
        ], $init->registered);
    }

    /** Fail-closed: применённая версия не прочиталась (строки модуля нет / поле битое) - не регистрируем. */
    public function testUnknownSchemaVersionFailsClosed(): void
    {
        $init = $this->initWithSchemaVersion(null);
        $init->registerDictionaryIdentityForTest();

        $this->assertSame([], $init->registered);
    }

    /**
     * Init с подменённым швом «применённая версия схемы»: в php74-сьюте любой
     * ServiceLocator::getService() бросает TypeError, поэтому реальная реализация шва
     * ({@see \Okay\Modules\Format\CoreSync\Core\Update\SchemaMarker::appliedVersion}) живёт
     * только в проде, а тесты подставляют версию через переопределение шва.
     */
    private function initWithSchemaVersion(?string $appliedVersion)
    {
        return new class($appliedVersion) extends Init {
            /** @var array<int, array{0:string,1:string}> */
            public $registered = [];
            /** @var string|null */
            private $appliedVersion;

            public function __construct(?string $appliedVersion)
            {
                $this->appliedVersion = $appliedVersion;
            }

            public function registerDictionaryIdentityForTest(): void
            {
                $this->registerDictionaryIdentityFields();
            }

            protected function dictionaryIdentitySchemaVersion(): ?string
            {
                return $this->appliedVersion;
            }

            protected function registerEntityField($entityClassName, $fieldName, $isLang = false)
            {
                $this->registered[] = [(string) $entityClassName, (string) $fieldName];
            }
        };
    }

    public function testUpgrade140AddsBothColumnsThroughFailClosedPrimitive(): void
    {
        $calls = [];
        $migration = new class($calls) extends SchemaMigration {
            /** @var array<int, array{0:string,1:string,2:string}> */
            private $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function addColumnIfMissing(string $table, string $column, string $columnDdl): void
            {
                $this->calls[] = [$table, $column, $columnDdl];
            }
        };
        $init = new class extends Init {
            public function __construct()
            {
            }

            public function upgradeDictionaryIdentityForTest(SchemaMigration $migration): void
            {
                $this->upgradeDictionaryIdentityFields($migration);
            }
        };

        $init->upgradeDictionaryIdentityForTest($migration);

        $this->assertSame([
            ['__categories', 'coresync_external_id', 'VARCHAR(64) NULL DEFAULT NULL'],
            ['__brands', 'coresync_external_id', 'VARCHAR(64) NULL DEFAULT NULL'],
        ], $calls);
    }

    public function testDictionaryMigrationRemainsDiscoverableIn150Module(): void
    {
        $module = json_decode((string) file_get_contents(
            dirname(__DIR__, 5) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        $version = (string) ($module['version'] ?? '');
        $this->assertTrue(
            version_compare($version, '1.5.0', '>='),
            sprintf('Expected module version >= 1.5.0, got "%s".', $version)
        );
        $this->assertTrue(method_exists(Init::class, 'update_1_4_0'));
    }
}
