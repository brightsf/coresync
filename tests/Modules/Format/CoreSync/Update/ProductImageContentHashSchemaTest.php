<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Modules\EntityField;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

/**
 * Колонка content-хеша картинки товара обязана ехать ОДНИМ механизмом схемы модуля: fresh install
 * (migrateCustomTable), догон уже установленной витрины (update_X_Y_Z через fail-closed
 * SchemaMigration) и runtime-проекция сущности обязаны описывать одно и то же поле.
 */
class ProductImageContentHashSchemaTest extends TestCase
{
    public function testVersionAndMigrationAreDiscoverable(): void
    {
        $module = json_decode((string) file_get_contents(
            dirname(__DIR__, 5) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        $version = (string) ($module['version'] ?? '');
        $this->assertTrue(
            version_compare($version, '1.5.5', '>='),
            sprintf('Expected module version >= 1.5.5, got "%s".', $version)
        );
        // Без обнаруженной миграции колонка НЕ доедет до установленных витрин: SchemaUpgrader гонит
        // ровно то, что видит рефлексией по update_X_Y_Z (SchemaMigrationCatalog::discover).
        $this->assertArrayHasKey('1.5.5', SchemaMigrationCatalog::discover(new class extends Init {
            public function __construct() {}
        }));
    }

    public function testUpgradeAddsContentHashColumnThroughFailClosedIdempotentPrimitive(): void
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

            public function upgradeContentHashForTest(SchemaMigration $migration): void
            {
                $this->upgradeProductImagesContentHash($migration);
            }
        };

        $init->upgradeContentHashForTest($migration);
        $init->upgradeContentHashForTest($migration);

        $this->assertSame([
            ['__format__coresync_images', 'content_sha256', 'VARCHAR(64) NULL DEFAULT NULL'],
            ['__format__coresync_images', 'content_sha256', 'VARCHAR(64) NULL DEFAULT NULL'],
        ], $calls, 'повтор применённой версии обязан быть безопасным no-op на уровне примитива');
    }

    public function testFreshInstallDeclaresTheSameNullableContentHashColumn(): void
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

            // Всё остальное в install() требует живого ядра/БД — глушим ровно те шаги, что не про схему.
            protected function setBackendMainController($controllerName) {}

            protected function installDictionaryIdentityFields(): void {}
        };

        try {
            $init->installForTest();
        } catch (\Throwable $e) {
            // VariantMapBackfill в хвосте install() требует ServiceLocator; таблицы уже объявлены.
        }

        $this->assertArrayHasKey('__format__coresync_images', $init->customTables);
        $names = [];
        $contentHash = null;
        foreach ($init->customTables['__format__coresync_images'] as $field) {
            $names[] = $field->getName();
            if ($field->getName() === 'content_sha256') {
                $contentHash = $field;
            }
        }
        $this->assertContains('content_sha256', $names, 'свежая установка обязана заводить колонку сразу');
        $this->assertNotNull($contentHash);
        $this->assertSame('varchar(64)', $contentHash->getType());
        $this->assertTrue($contentHash->isNullable(), 'отсутствие хеша — законное состояние (ядро не поручилось)');
        $this->assertNull($contentHash->getDefault());
    }

    public function testRuntimeEntityExposesContentHashField(): void
    {
        $reflection = new \ReflectionClass(CoreSyncImagesEntity::class);
        $fields = $reflection->getProperty('fields');
        $fields->setAccessible(true);

        $this->assertSame([
            'id', 'product_external_id', 'product_local_id', 'url', 'url_hash', 'sort',
            'state', 'attempts', 'filename', 'image_id', 'content_sha256',
        ], $fields->getValue(), 'колонки нет в $fields → find() её не выберет и усыновление ослепнет');
    }

    public function testContentHashContractAcceptsOnlyLowercaseSixtyFourHex(): void
    {
        $this->assertSame('sha256', Contract::IMAGE_CONTENT_SHA256_KEY);
        $this->assertSame('content_sha256', Contract::IMAGE_CONTENT_SHA256_FIELD);
        $this->assertTrue(Contract::isValidContentSha256(str_repeat('a', 64)));
        $this->assertTrue(Contract::isValidContentSha256(hash('sha256', 'bytes')));
        $this->assertFalse(Contract::isValidContentSha256(str_repeat('A', 64)), 'верхний регистр вне контракта');
        $this->assertFalse(Contract::isValidContentSha256(str_repeat('a', 63)));
        $this->assertFalse(Contract::isValidContentSha256(str_repeat('a', 65)));
        $this->assertFalse(Contract::isValidContentSha256(''), 'пусто = «усыновлять нельзя, качать»');
        $this->assertFalse(Contract::isValidContentSha256(null));
        $this->assertFalse(Contract::isValidContentSha256(0));
    }
}
