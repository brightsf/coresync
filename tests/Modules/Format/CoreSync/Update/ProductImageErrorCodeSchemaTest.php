<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Modules\EntityField;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

class ProductImageErrorCodeSchemaTest extends TestCase
{
    public function testCurrentVersionHasDiscoverableExactTargetMigration(): void
    {
        $module = json_decode((string) file_get_contents(
            dirname(__DIR__, 5) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        self::assertSame('1.5.7', (string) ($module['version'] ?? ''));
        $catalog = SchemaMigrationCatalog::discover(new class extends Init {
            public function __construct() {}
        });
        self::assertArrayHasKey('1.5.6', $catalog, 'applied==target installations need the idempotent exact-target self-heal');
        self::assertArrayHasKey('1.5.7', $catalog, 'storefronts installed from tag okay-v1.5.6 (cut before update_1_5_6 reached canon) get the error_code column via the first shipped version');
    }

    public function testUpgradeAddsNullableColumnWithoutRecreatingTheExistingTable(): void
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
            public function __construct() {}

            public function upgradeErrorCodeForTest(SchemaMigration $migration): void
            {
                $this->upgradeProductImagesErrorCode($migration);
            }
        };

        self::assertTrue(
            method_exists(Init::class, 'upgradeProductImagesErrorCode'),
            'Init needs an idempotent product-image error_code upgrade primitive'
        );
        $init->upgradeErrorCodeForTest($migration);
        $init->upgradeErrorCodeForTest($migration);

        self::assertSame([
            ['__format__coresync_images', 'error_code', 'VARCHAR(64) NULL DEFAULT NULL'],
            ['__format__coresync_images', 'error_code', 'VARCHAR(64) NULL DEFAULT NULL'],
        ], $calls, 'upgrade is an additive idempotent ALTER, so existing rows are not recreated');
    }

    public function testFreshInstallDeclaresNullableErrorCodeColumn(): void
    {
        $init = new class extends Init {
            /** @var array<string, array<int, EntityField>> */
            public $customTables = [];

            public function __construct() {}

            public function installForTest(): void
            {
                $this->install();
            }

            protected function migrateCustomTable($tableName, array $fields = [])
            {
                $this->customTables[(string) $tableName] = $fields;
            }

            protected function setBackendMainController($controllerName) {}

            protected function installDictionaryIdentityFields(): void {}

            // The repeat-install guard asks the live DB whether a table is already there; not a schema
            // step, so it is stubbed like the others — every table reads as missing (fresh storefront).
            protected function installSchemaMigration(): SchemaMigration
            {
                return new class extends SchemaMigration {
                    public function __construct() {}

                    public function tableExists(string $table): bool
                    {
                        return false;
                    }
                };
            }
        };

        try {
            $init->installForTest();
        } catch (\Throwable $e) {
            // VariantMapBackfill requires the live ServiceLocator after table declarations.
        }

        self::assertArrayHasKey('__format__coresync_images', $init->customTables);
        $errorCode = null;
        foreach ($init->customTables['__format__coresync_images'] as $field) {
            if ($field->getName() === 'error_code') {
                $errorCode = $field;
                break;
            }
        }
        self::assertNotNull($errorCode, 'fresh install must create the diagnostic column immediately');
        self::assertSame('varchar(64)', $errorCode->getType());
        self::assertTrue($errorCode->isNullable());
        self::assertNull($errorCode->getDefault());
    }

    public function testRuntimeEntityReadsErrorCodeField(): void
    {
        $reflection = new \ReflectionClass(CoreSyncImagesEntity::class);
        $fields = $reflection->getProperty('fields');
        $fields->setAccessible(true);

        self::assertContains('error_code', $fields->getValue(), 'column outside $fields is never selected by find()');
    }
}
