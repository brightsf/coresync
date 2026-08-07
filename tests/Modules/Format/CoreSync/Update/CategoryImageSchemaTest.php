<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

class CategoryImageSchemaTest extends TestCase
{
    public function testVersionAndMigrationAreDiscoverable(): void
    {
        $module = json_decode((string) file_get_contents(
            dirname(__DIR__, 5) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        $this->assertSame('1.5.0', $module['version'] ?? null);
        $this->assertArrayHasKey('1.5.0', SchemaMigrationCatalog::discover(new class extends Init {
            public function __construct() {}
        }));
    }

    public function testUpgradeCreatesOneRowPerCategoryDurableTableIdempotently(): void
    {
        $sql = [];
        $migration = new class($sql) extends SchemaMigration {
            /** @var string[] */
            private $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function execute(string $sql): void
            {
                $this->captured[] = $sql;
            }
        };
        $init = new class extends Init {
            public function __construct() {}

            public function upgradeForTest(SchemaMigration $migration): void
            {
                $this->upgradeCategoryImagesTable($migration);
            }
        };

        $init->upgradeForTest($migration);
        $init->upgradeForTest($migration);

        $this->assertCount(2, $sql);
        $this->assertSame($sql[0], $sql[1]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `__format__coresync_category_images`', $sql[0]);
        $this->assertStringContainsString('UNIQUE KEY `category_external_id`', $sql[0]);
        foreach (['source_instance', 'source_id', 'sha256', 'mime', 'bytes', 'state', 'attempts', 'filename', 'error_code'] as $field) {
            $this->assertStringContainsString('`' . $field . '`', $sql[0]);
        }
    }

    public function testRuntimeEntityExposesEveryDurableField(): void
    {
        $reflection = new \ReflectionClass(CoreSyncCategoryImagesEntity::class);
        $fields = $reflection->getProperty('fields');
        $fields->setAccessible(true);
        $table = $reflection->getProperty('table');
        $table->setAccessible(true);

        $this->assertSame('__format__coresync_category_images', $table->getValue());
        $this->assertSame([
            'id', 'category_external_id', 'category_local_id', 'source_instance', 'source_id',
            'url', 'sha256', 'mime', 'bytes', 'state', 'attempts', 'filename', 'error_code',
        ], $fields->getValue());
    }
}
