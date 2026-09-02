<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\SqlQuery;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed примитив DDL. Ядро ГЛОТАЕТ Database::query()→false (провал ALTER невидим), поэтому
 * примитив зовёт query() напрямую и его false → бросок ⇒ SchemaUpgrader/ручная кнопка не поднимут
 * маркер. Идемпотентность addColumnIfMissing: колонка есть → ALTER не шлётся.
 */
class SchemaMigrationTest extends TestCase
{
    /**
     * QueryFactory-мок, отдающий настоящий (дешёвый) SqlQuery — так примитив реально формирует
     * statement, который мы затем читаем в query()-колбэке.
     */
    private function queryFactoryMock(): QueryFactory
    {
        $qf = $this->createMock(QueryFactory::class);
        $qf->method('newSqlQuery')->willReturnCallback(static function (): SqlQuery {
            return new SqlQuery();
        });

        return $qf;
    }

    /**
     * Database-мок: query() записывает выполненный statement и возвращает $queryResult (или из
     * последовательности), results() отдаёт $rows.
     *
     * @param bool|array<int,bool> $queryResult один результат или очередь по вызовам query()
     * @param array<int,object>    $rows        строки для results() (SHOW COLUMNS)
     * @param array<int,string>    $executed    out: выполненные statement'ы
     */
    private function databaseMock($queryResult, array $rows, array &$executed): Database
    {
        $db = $this->createMock(Database::class);
        $seq = is_array($queryResult) ? $queryResult : null;
        $db->method('query')->willReturnCallback(static function ($sql) use ($queryResult, $seq, &$executed): bool {
            $executed[] = (string) $sql->getStatement();
            if ($seq !== null) {
                return (bool) array_shift($seq);
            }

            return (bool) $queryResult;
        });
        $db->method('results')->willReturn($rows);

        return $db;
    }

    private function col(string $field): object
    {
        return (object) ['Field' => $field];
    }

    /** Строка выдачи `SHOW TABLES LIKE …`: имя колонки зависит от БД и паттерна, значение — имя таблицы. */
    private function tbl(string $name): object
    {
        return (object) ['Tables_in_shop (pattern)' => $name];
    }

    /** Config-мок витрины: db_prefix = ok_ (как в config/config.php стенда). */
    private function configMock(string $prefix = 'ok_'): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function ($name) use ($prefix) {
            return $name === 'db_prefix' ? $prefix : null;
        });

        return $config;
    }

    public function testExecuteThrowsWhenQueryFails(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(false, [], $executed), $this->queryFactoryMock());

        $this->expectException(UpdateException::class);
        $migration->execute('ALTER TABLE `t` ADD COLUMN `x` int NULL');
    }

    public function testExecuteSucceedsWhenQueryOk(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(true, [], $executed), $this->queryFactoryMock());

        $migration->execute('ALTER TABLE `t` ADD COLUMN `x` int NULL');
        $this->assertSame(['ALTER TABLE `t` ADD COLUMN `x` int NULL'], $executed, 'DDL выполнен ровно один раз');
    }

    public function testColumnExistsTrueWhenPresent(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(true, [$this->col('id'), $this->col('foo')], $executed), $this->queryFactoryMock());

        $this->assertTrue($migration->columnExists('t', 'foo'));
    }

    public function testColumnExistsFalseWhenAbsent(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(true, [$this->col('id'), $this->col('bar')], $executed), $this->queryFactoryMock());

        $this->assertFalse($migration->columnExists('t', 'foo'));
    }

    public function testColumnReadFailureThrows(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(false, [], $executed), $this->queryFactoryMock());

        $this->expectException(UpdateException::class);
        $migration->columnExists('t', 'foo');
    }

    public function testAddColumnIfMissingSkipsWhenPresent(): void
    {
        $executed = [];
        // Колонка уже есть → только SHOW COLUMNS, ALTER не шлётся (идемпотентность).
        $migration = new SchemaMigration($this->databaseMock(true, [$this->col('foo')], $executed), $this->queryFactoryMock());

        $migration->addColumnIfMissing('t', 'foo', 'int NULL');
        $this->assertCount(1, $executed, 'только чтение колонок, без ALTER');
        $this->assertStringContainsString('SHOW COLUMNS', $executed[0]);
    }

    public function testAddColumnIfMissingAltersWhenAbsent(): void
    {
        $executed = [];
        // Колонки нет → SHOW COLUMNS (true), затем ALTER (true).
        $migration = new SchemaMigration($this->databaseMock([true, true], [$this->col('id')], $executed), $this->queryFactoryMock());

        $migration->addColumnIfMissing('t', 'foo', 'varchar(64) NULL DEFAULT NULL');
        $this->assertCount(2, $executed, 'чтение колонок + ALTER');
        $this->assertStringContainsString('SHOW COLUMNS', $executed[0]);
        $this->assertStringContainsString('ADD COLUMN `foo`', $executed[1]);
    }

    /**
     * A1. Database::tablePrefix() подставляет префикс регэкспом, который НЕ срабатывает внутри кавычек
     * (паттерн требует не-кавычку перед `__`), поэтому имя в LIKE обязано быть уже префиксным.
     * `_` в LIKE — одиночный wildcard, значит экранируется.
     */
    public function testTableExistsQueriesPrefixedAndEscapedName(): void
    {
        $executed = [];
        $migration = new SchemaMigration(
            $this->databaseMock(true, [$this->tbl('ok_format__coresync_map')], $executed),
            $this->queryFactoryMock(),
            $this->configMock('ok_')
        );

        $migration->tableExists('__format__coresync_map');

        $this->assertSame(
            ["SHOW TABLES LIKE 'ok\\_format\\_\\_coresync\\_map'"],
            $executed,
            'ровно один SHOW TABLES с префиксным именем и экранированными подчёркиваниями'
        );
    }

    /** A2. Точное имя в выдаче → true. */
    public function testTableExistsTrueOnExactName(): void
    {
        $executed = [];
        $migration = new SchemaMigration(
            $this->databaseMock(true, [$this->tbl('ok_format__coresync_map')], $executed),
            $this->queryFactoryMock(),
            $this->configMock('ok_')
        );

        $this->assertTrue($migration->tableExists('__format__coresync_map'));
    }

    /** A2. Пустая выдача → false. */
    public function testTableExistsFalseOnEmptyResult(): void
    {
        $executed = [];
        $migration = new SchemaMigration(
            $this->databaseMock(true, [], $executed),
            $this->queryFactoryMock(),
            $this->configMock('ok_')
        );

        $this->assertFalse($migration->tableExists('__format__coresync_map'));
    }

    /**
     * A2. Похожее, но другое имя → false. Замок против «непусто ⇒ есть»: выдача непустая, таблицы нет.
     */
    public function testTableExistsFalseOnSimilarButDifferentName(): void
    {
        $executed = [];
        $migration = new SchemaMigration(
            $this->databaseMock(true, [$this->tbl('ok_format__coresync_mapx')], $executed),
            $this->queryFactoryMock(),
            $this->configMock('ok_')
        );

        $this->assertFalse($migration->tableExists('__format__coresync_map'));
    }

    /** A3. Чтение провалилось → fail-closed бросок, как у columnExists. */
    public function testTableExistsThrowsWhenQueryFails(): void
    {
        $executed = [];
        $migration = new SchemaMigration(
            $this->databaseMock(false, [], $executed),
            $this->queryFactoryMock(),
            $this->configMock('ok_')
        );

        $this->expectException(UpdateException::class);
        $migration->tableExists('__format__coresync_map');
    }

    /** Соседний контракт: конструкция без Config остаётся валидной (существующие вызовы не ломаются). */
    public function testTwoArgumentConstructionStillWorks(): void
    {
        $executed = [];
        $migration = new SchemaMigration($this->databaseMock(true, [], $executed), $this->queryFactoryMock());

        $migration->execute('ALTER TABLE `t` ADD COLUMN `x` int NULL');
        $this->assertSame(['ALTER TABLE `t` ADD COLUMN `x` int NULL'], $executed);
    }
}
