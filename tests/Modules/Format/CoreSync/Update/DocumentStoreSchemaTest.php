<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncDocumentFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncProductDocumentsEntity;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

/** Своё хранилище документов 1.9.0: файлы по sha256 и документы товара, идемпотентный DDL. */
class DocumentStoreSchemaTest extends TestCase
{
    public function testVersionAndMigrationAreDiscoverable(): void
    {
        $module = json_decode((string) file_get_contents(
            dirname(__DIR__, 5) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        $this->assertTrue(version_compare((string) ($module['version'] ?? ''), '1.9.0', '>='));
        $this->assertArrayHasKey('1.9.0', SchemaMigrationCatalog::discover(new class extends Init {
            public function __construct() {}
        }));
    }

    public function testUpgradeCreatesBothTablesIdempotently(): void
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
                $this->upgradeDocumentStoreTables($migration);
            }
        };

        $init->upgradeForTest($migration);
        $init->upgradeForTest($migration);

        $this->assertCount(4, $sql);
        $this->assertSame(array_slice($sql, 0, 2), array_slice($sql, 2, 2));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `__format__coresync_document_files`', $sql[0]);
        $this->assertStringContainsString('UNIQUE KEY `sha256` (`sha256`)', $sql[0]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `__format__coresync_product_documents`', $sql[1]);
        $this->assertStringContainsString(
            'UNIQUE KEY `product_document_external` (`product_external_id`, `document_external_id`)',
            $sql[1]
        );
        foreach (['product_local_id', 'title', 'position', 'language', 'document_type', 'sha256'] as $field) {
            $this->assertStringContainsString('`' . $field . '`', $sql[1]);
        }
    }

    public function testEntitiesExposeTheTables(): void
    {
        $this->assertSame('__format__coresync_document_files', CoreSyncDocumentFilesEntity::getTable());
        $this->assertSame('__format__coresync_product_documents', CoreSyncProductDocumentsEntity::getTable());
        $this->assertSame(
            ['id', 'sha256', 'bytes', 'path'],
            $this->fields(CoreSyncDocumentFilesEntity::class)
        );
        $this->assertSame(
            ['id', 'product_external_id', 'product_local_id', 'document_external_id', 'title', 'position',
                'language', 'document_type', 'sha256'],
            $this->fields(CoreSyncProductDocumentsEntity::class)
        );
    }

    /** @return list<string> */
    private function fields(string $class): array
    {
        $property = (new \ReflectionClass($class))->getProperty('fields');
        $property->setAccessible(true);

        return $property->getValue();
    }
}
