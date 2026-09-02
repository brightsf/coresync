<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Modules\EntityField;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Init\Init;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/Okay/Core/config/constants.php';

/**
 * Guard повторной установки. Кнопка «Удалить» в админке Okay стирает только СТРОКУ модуля из
 * `__modules`, таблицы остаются; следующий «Установить» снова гонит Init::install(), а фреймворковый
 * migrateCustomTable шлёт голый `CREATE TABLE` без IF NOT EXISTS. Ядро глотает шесть ошибок
 * «1050 Table already exists»: оператор видит «установилось», лог красный.
 *
 * Замок: install() создаёт только ОТСУТСТВУЮЩИЕ таблицы, а на свежей витрине состав и порядок
 * вызовов остаются байт-в-байт прежними (списки полей сняты с canon 2c6b3d9 ДО правки).
 */
class InstallTableGuardTest extends TestCase
{
    /** Порядок таблиц и наборы полей, снятые с canon `2c6b3d9` до правки install(). */
    private const FRESH_INSTALL_TABLES = [
        Init::MAP_TABLE => [
            'id', 'entity_type', 'external_id', 'local_id', 'applied_hash', 'image_state',
        ],
        Init::JOBS_TABLE => [
            'id', 'status', 'snapshot_version', 'phase', 'files_total', 'files_done', 'bytes_done',
            'error_message', 'cancel_requested', 'started_at', 'finished_at', 'created', 'updated',
        ],
        Init::JOB_FILES_TABLE => [
            'id', 'job_id', 'name', 'sha256_expected', 'bytes', 'status',
        ],
        Init::IMAGES_TABLE => [
            'id', 'product_external_id', 'product_local_id', 'url', 'url_hash', 'sort', 'state',
            'attempts', 'filename', 'image_id', 'content_sha256', 'error_code',
        ],
        Init::CATEGORY_IMAGES_TABLE => [
            'id', 'category_external_id', 'category_local_id', 'source_instance', 'source_id', 'url',
            'sha256', 'mime', 'bytes', 'state', 'attempts', 'filename', 'error_code',
        ],
        Init::ORDERS_OUT_TABLE => [
            'id', 'entity', 'local_id', 'delivered_at', 'acked_at',
        ],
    ];

    /**
     * Init с записывающим migrateCustomTable — примитив install-таблиц зовётся напрямую, минуя
     * ServiceLocator (та же форма, что в DictionaryIdentitySchemaTest).
     *
     * @param array<int, array{0:string,1:array<int,string>}> $migrated out: [таблица, имена полей]
     */
    private function recordingInit(array &$migrated): Init
    {
        return new class($migrated) extends Init {
            /** @var array<int, array{0:string,1:array<int,string>}> */
            private $migrated;

            public function __construct(array &$migrated)
            {
                $this->migrated = &$migrated;
            }

            public function installTablesForTest(SchemaMigration $migration): void
            {
                $this->installTables($migration);
            }

            protected function migrateCustomTable($tableName, array $fields)
            {
                $this->migrated[] = [
                    (string) $tableName,
                    array_map(static function (EntityField $field): string {
                        return (string) $field->getName();
                    }, $fields),
                ];
            }
        };
    }

    /**
     * Стаб SchemaMigration: tableExists отвечает по заданному набору уже существующих таблиц и
     * пишет опрошенные имена.
     *
     * @param array<int, string> $existing
     * @param array<int, string> $asked    out: имена, по которым спрашивали
     */
    private function migrationStub(array $existing, array &$asked): SchemaMigration
    {
        return new class($existing, $asked) extends SchemaMigration {
            /** @var array<int, string> */
            private $existing;
            /** @var array<int, string> */
            private $asked;

            public function __construct(array $existing, array &$asked)
            {
                $this->existing = $existing;
                $this->asked = &$asked;
            }

            public function tableExists(string $table): bool
            {
                $this->asked[] = $table;

                return in_array($table, $this->existing, true);
            }
        };
    }

    /** B1. Все шесть таблиц на месте (повторная установка) → ни одного CREATE TABLE. */
    public function testRepeatedInstallCreatesNothingWhenAllTablesExist(): void
    {
        $migrated = [];
        $asked = [];
        $init = $this->recordingInit($migrated);

        $init->installTablesForTest($this->migrationStub(array_keys(self::FRESH_INSTALL_TABLES), $asked));

        $this->assertSame([], $migrated, 'существующие таблицы не пересоздаются');
        $this->assertSame(
            array_keys(self::FRESH_INSTALL_TABLES),
            $asked,
            'каждая из шести таблиц проверена на существование'
        );
    }

    /** B2. Свежая витрина → прежние шесть вызовов в прежнем порядке с прежними наборами полей. */
    public function testFreshInstallKeepsTableOrderAndFieldSets(): void
    {
        $migrated = [];
        $asked = [];
        $init = $this->recordingInit($migrated);

        $init->installTablesForTest($this->migrationStub([], $asked));

        $expected = [];
        foreach (self::FRESH_INSTALL_TABLES as $table => $fields) {
            $expected[] = [$table, $fields];
        }

        $this->assertSame($expected, $migrated, 'состав, порядок таблиц и имена полей не изменились');
    }

    /** B3. Смешанное состояние (MAP и IMAGES уже есть) → создаются ровно четыре отсутствующие. */
    public function testMixedStateCreatesOnlyMissingTables(): void
    {
        $migrated = [];
        $asked = [];
        $init = $this->recordingInit($migrated);

        $init->installTablesForTest($this->migrationStub([Init::MAP_TABLE, Init::IMAGES_TABLE], $asked));

        $this->assertSame([
            Init::JOBS_TABLE,
            Init::JOB_FILES_TABLE,
            Init::CATEGORY_IMAGES_TABLE,
            Init::ORDERS_OUT_TABLE,
        ], array_column($migrated, 0), 'созданы ровно отсутствующие, в прежнем относительном порядке');

        foreach ($migrated as [$table, $fields]) {
            $this->assertSame(
                self::FRESH_INSTALL_TABLES[$table],
                $fields,
                'наборы полей у досоздаваемых таблиц те же, что на свежей установке'
            );
        }
    }

    /**
     * Соседний контракт install(): вынос таблиц в примитив не уносит с собой остальные шаги
     * установки — контроллер админки, identity-поля словарей и backfill карты вариантов.
     */
    public function testInstallStillPerformsNonTableSteps(): void
    {
        $method = new \ReflectionMethod(Init::class, 'install');
        $source = implode('', array_slice(
            (array) file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("setBackendMainController('CoreSyncAdmin')", $source);
        $this->assertStringContainsString('installDictionaryIdentityFields()', $source);
        $this->assertStringContainsString('VariantMapBackfill', $source);
        $this->assertStringContainsString('installTables(', $source);
    }

    /**
     * Порядок в install(): сброс durable-исхода схемы идёт ПЕРВЫМ шагом — до создания таблиц и до
     * остальных шагов установки. Иначе упавшая посреди переустановка ТОЙ ЖЕ версии осталась бы с
     * исходом ПРОШЛОЙ установки (`upgraded to=target`), и exact-target self-heal, который её и
     * лечит, был бы подавлен молча и навсегда (D-CORESYNC-SCHEMA-OUTCOME-SURVIVES-REINSTALL).
     */
    public function testInstallResetsSchemaOutcomeBeforeAnyOtherStep(): void
    {
        $method = new \ReflectionMethod(Init::class, 'install');
        $source = implode('', array_slice(
            (array) file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $reset = strpos($source, 'resetSchemaOutcome(');
        $this->assertNotFalse($reset, 'install() сбрасывает durable-исход схемы прошлой установки');

        foreach (['installTables(', "setBackendMainController('CoreSyncAdmin')", 'installDictionaryIdentityFields()', 'VariantMapBackfill'] as $laterStep) {
            $this->assertLessThan(
                (int) strpos($source, $laterStep),
                $reset,
                'сброс исхода идёт ДО шага ' . $laterStep . ': install() может упасть на любом из них'
            );
        }
    }
}
