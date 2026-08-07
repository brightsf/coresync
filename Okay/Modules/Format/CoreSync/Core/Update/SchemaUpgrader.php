<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Settings;
use Psr\Log\LoggerInterface;

/**
 * Догоняющий апгрейд схемы таблиц модуля (закрывает D-SAT-UPDATE-SCHEMA-MIGRATE).
 *
 * Зовётся на СТАРТЕ тика ({@see \Okay\Modules\Format\CoreSync\Core\SyncRunner::run}, под lock, за
 * стоп-краном) — в свежем процессе, где загружен актуальный код модуля. Сверяет целевую версию
 * (module.json, {@see SchemaMigrationCatalog::targetVersion}) с применённой (modules.version,
 * {@see SchemaMarker::appliedVersion}): отстаёт → гонит недостающие update_X_Y_Z ПО ПОРЯДКУ;
 * равна target → повторяет только exact-target idempotent migration, чтобы долечить частичный fresh
 * install (Installer сохраняет target version до завершения Init::install()). Маркер поднимает
 * ТОЛЬКО при успехе ВСЕХ миграций отстающего релиза и не переписывает при exact-target self-heal.
 *
 * Fail-closed (Scope C): каждая миграция обязана бросить при провале DDL (Database::query()→false;
 * ядро глотает эту ошибку, поэтому есть {@see SchemaMigration}). Любой бросок → маркер НЕ поднят,
 * исход громко в durable-статус оператора (Settings `coresync_schema_status`, по образцу
 * `coresync_update_status` апдейтера) + лог; следующий тик повторит (идемпотентность миграций
 * делает повтор безопасным).
 *
 * ⚠ Мина same-process (Scope D): НИКОГДА не зовётся после swap в ТОМ ЖЕ процессе (иначе гоняли бы
 * СТАРЫЙ загруженный код и/или подняли бы маркер без реального прогона). Точка вызова — начало тика;
 * swap ({@see Updater}) — конец тика; догон делает СЛЕДУЮЩИЙ (свежий) процесс.
 */
class SchemaUpgrader
{
    /** Durable-исход апгрейда схемы (виден оператору; форма {status,from,to,at,error}). */
    public const SETTINGS_SCHEMA_STATUS_KEY = 'coresync_schema_status';

    /** @var SchemaMarker */
    private $marker;
    /** @var SchemaMigrationCatalog */
    private $catalog;
    /** @var Settings */
    private $settings;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        SchemaMarker $marker,
        SchemaMigrationCatalog $catalog,
        Settings $settings,
        ?LoggerInterface $logger = null
    ) {
        $this->marker = $marker;
        $this->catalog = $catalog;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Догнать схему до целевой версии.
     *
     * @return bool true — схема актуальна (после апгрейда ИЛИ no-op); false — миграция провалилась
     *              (маркер не поднят, исход в статусе, повтор следующим тиком). Зовущий тик по false
     *              не трогает витрину/код до следующего прохода.
     */
    public function upgrade(): bool
    {
        $target = $this->catalog->targetVersion();
        if ($target === null) {
            // Не читается целевая версия — не наша ошибка данных; тик не блокируем, маркер не трогаем.
            $this->error('CoreSync schema: не читается целевая версия (module.json) — апгрейд схемы пропущен');

            return true;
        }

        $applied = $this->marker->appliedVersion();
        if ($applied === null) {
            $this->error('CoreSync schema: не читается применённая версия (modules.version) — апгрейд схемы пропущен');

            return true;
        }

        if (version_compare($target, $applied, '<')) {
            // Откат назад не поддерживаем и applied marker не понижаем.
            return true;
        }

        $exactTargetRetry = version_compare($target, $applied, '==');
        $pending = $exactTargetRetry
            ? $this->exactTargetMigration($target)
            : $this->pending($applied, $target);
        if ($exactTargetRetry && empty($pending)) {
            // applied == target без схемного метода — обычный релиз без DDL, остаётся no-op.
            return true;
        }

        foreach ($pending as $version => $migration) {
            try {
                $migration();
                $this->info('CoreSync schema: миграция ' . $version . ' применена');
            } catch (\Throwable $e) {
                $this->recordOutcome('failed', $applied, $target, $version . ': ' . $e->getMessage());
                $this->error('CoreSync schema: миграция ' . $version . ' провалена — маркер НЕ поднят, повтор следующим тиком: ' . $e->getMessage());

                return false;
            }
        }

        // Exact-target migration лечит install(), где modules.version уже target: лишний bump не нужен.
        // При реальном отставании маркер поднимаем ТОЛЬКО после успеха ВСЕХ недостающих миграций.
        if (!$exactTargetRetry && !$this->marker->markApplied($target)) {
            $this->recordOutcome('failed', $applied, $target, 'не удалось зафиксировать версию схемы в modules.version');
            $this->error('CoreSync schema: миграции применены, но маркер не зафиксирован — повтор следующим тиком');

            return false;
        }

        $this->recordOutcome('upgraded', $applied, $target, null);
        $this->info('CoreSync schema: схема догнана ' . $applied . ' → ' . $target . ' (миграций применено: ' . count($pending) . ')');

        return true;
    }

    /**
     * Идемпотентная миграция ровно target-версии для self-heal частичного fresh install.
     * Старые/будущие методы намеренно не запускаются при уже сохранённом target marker.
     *
     * @return array<string, callable():void>
     */
    private function exactTargetMigration(string $target): array
    {
        foreach ($this->catalog->migrations() as $version => $migration) {
            if (version_compare((string) $version, $target, '==')) {
                return [(string) $version => $migration];
            }
        }

        return [];
    }

    /**
     * Недостающие миграции: версия в полуинтервале (applied, target], по возрастанию версии.
     * Сортировка здесь (а не только в каталоге) — часть логики порядка: провал порядка красит тест.
     *
     * @return array<string, callable():void>
     */
    private function pending(string $applied, string $target): array
    {
        $pending = [];
        foreach ($this->catalog->migrations() as $version => $migration) {
            if (version_compare((string) $version, $applied, '>') && version_compare((string) $version, $target, '<=')) {
                $pending[(string) $version] = $migration;
            }
        }
        uksort($pending, 'version_compare');

        return $pending;
    }

    private function recordOutcome(string $status, string $from, string $to, ?string $error): void
    {
        $this->settings->set(self::SETTINGS_SCHEMA_STATUS_KEY, [
            'status' => $status,
            'from'   => $from,
            'to'     => $to,
            'at'     => date('Y-m-d H:i:s'),
            'error'  => $error,
        ]);
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }

    private function error(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
