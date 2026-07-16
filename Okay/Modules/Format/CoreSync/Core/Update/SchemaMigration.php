<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Database;
use Okay\Core\QueryFactory;

/**
 * Fail-closed примитив DDL для методов update_X_Y_Z (Scope C). Единственный правильный способ
 * писать схемные шаги модуля: он и проверяет каждый шаг против Database::query()→false, и держит
 * шаг идемпотентным.
 *
 * ⚠ ЗАЧЕМ отдельный примитив, а не core-хелперы: {@see \Okay\Core\Modules\EntityMigrator::migrateField}
 * и {@see \Okay\Core\QueryFactory\SqlQuery::execute} ПРОГЛАТЫВАЮТ результат Database::query() (ядро
 * ловит SQL-исключение, логирует, возвращает false; вызыватели bool не проверяют) ⇒ провалившийся
 * ALTER сегодня НЕВИДИМ. Здесь Database::query() зовётся напрямую и его `=== false` → бросок, поэтому
 * {@see SchemaUpgrader} (и цикл Installer::update ручной кнопки) увидят провал и НЕ поднимут маркер.
 *
 * Идемпотентность (Scope A): addColumnIfMissing проверяет наличие колонки ДО ALTER — повторный
 * прогон применённой версии = безопасный no-op.
 */
class SchemaMigration
{
    /** @var Database */
    private $db;
    /** @var QueryFactory */
    private $queryFactory;

    public function __construct(Database $db, QueryFactory $queryFactory)
    {
        $this->db = $db;
        $this->queryFactory = $queryFactory;
    }

    /**
     * Выполнить DDL-выражение fail-closed: Database::query() === false → бросок.
     *
     * @throws UpdateException
     */
    public function execute(string $statement): void
    {
        $sql = $this->queryFactory->newSqlQuery();
        $sql->setStatement($statement);
        if ($this->db->query($sql) === false) {
            throw new UpdateException('CoreSync schema: DDL провалено (Database::query→false): ' . $statement);
        }
    }

    /**
     * Есть ли колонка $column в таблице $table (для идемпотентности). Чтение колонок тоже fail-closed.
     *
     * @throws UpdateException
     */
    public function columnExists(string $table, string $column): bool
    {
        $sql = $this->queryFactory->newSqlQuery();
        $sql->setStatement('SHOW COLUMNS FROM `' . $table . '`');
        if ($this->db->query($sql) === false) {
            throw new UpdateException('CoreSync schema: не удалось прочитать колонки таблицы ' . $table);
        }
        foreach ((array) $this->db->results() as $col) {
            if (is_object($col) && isset($col->Field) && $col->Field === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Идемпотентно добавить колонку: если её ещё нет — `ALTER TABLE … ADD COLUMN` (fail-closed).
     * $columnDdl — тип и ограничения, напр. "varchar(64) NULL DEFAULT NULL".
     *
     * @throws UpdateException
     */
    public function addColumnIfMissing(string $table, string $column, string $columnDdl): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        $this->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $columnDdl);
    }
}
