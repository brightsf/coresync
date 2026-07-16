<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Config;
use Psr\Log\LoggerInterface;

/**
 * Атомарная подмена каталога модуля [SECURITY-SENSITIVE], threat-model §3/§5.
 *
 * Живой сайт крутится под fastuser, который владеет и CoreSync/, и родительским Format/ ⇒ swap делается
 * `rename`'ом (атомарен по пути; обрезанного/полу-записанного модуля витрина не увидит НИКОГДА). Правило
 * места (память okay-module-dir-scan-500-trap + разведка Languages.php:269 глобит модульные пути
 * вида «Okay/Modules/{vendor}/{module}/Entities»): бэкап/стейджинг живут ВНЕ `Okay/Modules` — под
 * `files/coresync/updates/`, куда сканер модулей и boot-глоб не заглядывают. `.old`-каталог рядом с
 * модулем попал бы в этот глоб и мог уронить витрину.
 *
 * Инвариант отката: после ЛЮБОГО сбоя (провал переноса стейджинга ИЛИ провал само-проверки) исходный
 * модуль возвращается на место `rename`'ом обратно — смесь версий на диске не остаётся. Провал бросает
 * UpdateException уже ПОСЛЕ восстановления.
 */
class ModuleSwapper
{
    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(Config $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Заменить живой модуль содержимым $stagingModuleDir (каталог CoreSync/, распакованный экстрактором).
     * $expectedVersion — целевая версия релиза (сверяется и в санити стейджинга, и в само-проверке).
     *
     * @throws UpdateException санити/перенос/само-проверка провалены (после отката)
     */
    public function swap(string $stagingModuleDir, string $expectedVersion): void
    {
        $moduleDir = $this->moduleDir();

        // 1) Санити стейджинга ДО того, как тронули живой модуль (провал — витрина не задета вовсе).
        $this->assertStagingSane($stagingModuleDir, $expectedVersion);

        if (!is_dir($moduleDir)) {
            throw new UpdateException('Обновление: живой каталог модуля отсутствует — swap отменён: ' . $moduleDir);
        }

        $updatesRoot = $this->updatesRoot();
        if (!is_dir($updatesRoot) && !@mkdir($updatesRoot, 0755, true) && !is_dir($updatesRoot)) {
            throw new UpdateException('Обновление: не удалось создать каталог обновлений: ' . $updatesRoot);
        }
        $stamp = date('Ymd-His') . '-' . substr(uniqid('', true), -8);
        $backupDir = $updatesRoot . '/rollback-' . $stamp;

        // 2) Атомарный swap: два rename ВПЛОТНУЮ, без логики между — окно «модуля нет» = зазор между
        //    двумя syscalls (микросекунды), inode живого запроса при этом не рвётся.
        if (!$this->move($moduleDir, $backupDir)) {
            throw new UpdateException('Обновление: не удалось отвести текущий модуль в бэкап — swap отменён');
        }
        if (!$this->move($stagingModuleDir, $moduleDir)) {
            // Живой ещё не заменён — вернуть бэкап на место. Провал восстановления = двойной сбой:
            // модуля на живом пути НЕТ — фиксируем максимально громко (SEC-ревью: unchecked restore).
            $this->restoreOrScream($backupDir, $moduleDir);
            throw new UpdateException('Обновление: не удалось внести новую версию — откат к исходной');
        }

        // 3) Само-проверка: свапнутый модуль реально несёт целевую версию и ключевые файлы.
        try {
            $this->selfCheck($expectedVersion);
        } catch (\Throwable $e) {
            // Откат: увести неисправную новую версию, вернуть бэкап. Каждый шаг восстановления
            // проверяется: двойной сбой (откат не удался) — громче некуда, живой путь пуст/неисправен.
            $failedDir = $updatesRoot . '/failed-' . $stamp;
            if (!$this->move($moduleDir, $failedDir)) {
                $this->error('CoreSync update: КРИТИЧНО — неисправная новая версия не отводится с живого пути; вручную верните бэкап: ' . $backupDir);
                throw new UpdateException('Обновление: само-проверка провалена И неисправная версия осталась на живом пути; бэкап: ' . $backupDir);
            }
            $this->restoreOrScream($backupDir, $moduleDir);
            $this->error('CoreSync update: само-проверка после swap провалена, откат к исходной версии: ' . $e->getMessage());
            throw $e instanceof UpdateException ? $e : new UpdateException('Обновление: само-проверка провалена: ' . $e->getMessage());
        }

        // 4) Пост-swap гигиена: инвалидация скомпилированных Smarty-шаблонов модуля (иначе mtime-чек
        //    Smarty не пересоберёт: build-artifact нормализует mtime в epoch-0 → новый .tpl «старее»
        //    скомпилированного). Бэкап оставляем на диске для ручного отката оператором.
        $purged = $this->invalidateCompiledTemplates();
        $this->info(sprintf(
            'CoreSync update: swap выполнен → версия %s; скомпилированных шаблонов сброшено: %d; бэкап: %s',
            $expectedVersion,
            $purged,
            $backupDir
        ));
    }

    /**
     * Вернуть бэкап на живой путь; провал восстановления = двойной сбой (живой путь ПУСТ) —
     * громкая критическая запись + исключение с инструкцией ручного восстановления.
     *
     * @throws UpdateException
     */
    private function restoreOrScream(string $backupDir, string $moduleDir): void
    {
        if (!$this->move($backupDir, $moduleDir)) {
            $this->error('CoreSync update: КРИТИЧНО — восстановление из бэкапа провалено, модуль ОТСУТСТВУЕТ на живом пути; вручную верните: ' . $backupDir);
            throw new UpdateException('Обновление: двойной сбой — откат не удался, модуль отсутствует; вручную верните ' . $backupDir);
        }
    }

    /**
     * Санити распакованного стейджинга: каталог есть, module.json несёт ОЖИДАЕМУЮ версию, ключевые
     * файлы (Describer) на месте. Провал — не начинаем swap.
     *
     * @throws UpdateException
     */
    private function assertStagingSane(string $stagingModuleDir, string $expectedVersion): void
    {
        if (!is_dir($stagingModuleDir)) {
            throw new UpdateException('Обновление: каталог стейджинга не найден: ' . $stagingModuleDir);
        }
        $version = $this->readVersion($stagingModuleDir);
        if ($version !== $expectedVersion) {
            throw new UpdateException(sprintf(
                'Обновление: версия в стейджинге (%s) не совпала с целевой (%s) — swap отменён',
                $version === null ? 'нет' : $version,
                $expectedVersion
            ));
        }
        if (!is_file($stagingModuleDir . '/Core/Describer.php')) {
            throw new UpdateException('Обновление: в стейджинге нет Core/Describer.php — набор неполон, swap отменён');
        }
    }

    /**
     * Само-проверка живого модуля после swap (шов для тестируемости ветки отката).
     *
     * @throws UpdateException
     */
    protected function selfCheck(string $expectedVersion): void
    {
        $moduleDir = $this->moduleDir();
        $version = $this->readVersion($moduleDir);
        if ($version !== $expectedVersion) {
            throw new UpdateException(sprintf(
                'Обновление: после swap живая версия (%s) ≠ целевой (%s)',
                $version === null ? 'нет' : $version,
                $expectedVersion
            ));
        }
        if (!is_file($moduleDir . '/Core/Describer.php')) {
            throw new UpdateException('Обновление: после swap отсутствует Core/Describer.php');
        }
    }

    /**
     * Сбросить скомпилированные backend-шаблоны модуля (файлы `*coresync*` в backend/design/compiled/).
     * Точечно — не трогаем чужие шаблоны (в отличие от полного clearCompiled ядра).
     *
     * @return int сколько файлов удалено
     */
    private function invalidateCompiledTemplates(): int
    {
        $dir = $this->compiledDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach ((array) glob($dir . '/*coresync*') as $file) {
            if (is_file($file) && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /** Прочитать version из Init/module.json каталога модуля (null — нет/битый). */
    private function readVersion(string $moduleDir): ?string
    {
        $path = $moduleDir . '/Init/module.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : null;
    }

    /**
     * Перенос пути (шов для тестируемости веток отката: тест форсирует провал конкретного переноса).
     */
    protected function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    private function root(): string
    {
        return rtrim((string) $this->config->get('root_dir'), '/\\');
    }

    private function moduleDir(): string
    {
        return $this->root() . '/Okay/Modules/Format/CoreSync';
    }

    private function updatesRoot(): string
    {
        return $this->root() . '/files/coresync/updates';
    }

    private function compiledDir(): string
    {
        return $this->root() . '/backend/design/compiled';
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
