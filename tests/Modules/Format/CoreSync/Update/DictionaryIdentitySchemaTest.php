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

        $this->assertSame('1.5.0', $module['version'] ?? null);
        $this->assertTrue(method_exists(Init::class, 'update_1_4_0'));
    }
}
