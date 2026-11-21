<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;

/**
 * Фоновый прогон по пинку ядра (`run_trigger = core`): тот же SyncRunner::run(), что гоняет крон.
 * Запускается {@see \Okay\Modules\Format\CoreSync\Core\BackgroundRun} отдельным процессом, вывод
 * уходит в files/coresync/logs/background-run.log. Аргументов нет: команда не принимает данных извне.
 */
class RunCommand extends CoreSyncCommand
{
    protected static $defaultName = 'coresync:run';
    protected static $defaultDescription = 'Run one CoreSync exchange (the same run as the cron task)';

    protected function handle()
    {
        try {
            $this->startEnabledModules();
            // Бутстрап позади: замок прогона проверен (взят и сразу отпущен, дальше его берёт run()),
            // маркер запуска снимается. С этого момента от второго процесса защищает сам замок, а
            // процесс, запущенный в микроокно до run(), проиграет замок и оставит pending ниже.
            $lock = $this->lockHelper();
            if ($lock->acquire()) {
                $lock->release();
            }
            $this->backgroundRun()->clearClaim();

            $outcome = $this->syncRunner()->run();
            if ($outcome === SyncRunner::OUTCOME_LOCK_BUSY) {
                // Процесс проиграл гонку другому прогону. Пинок не теряем: держатель замка добирает
                // отметку pending в конце своего прогона (перечитывая её из БД), иначе её возьмёт
                // следующий пинок. Кэш настроек перечитывается, чтобы set() не сравнил с устаревшим.
                $settings = $this->settings();
                $settings->initSettings();
                $settings->set(Contract::SETTINGS_PING_PENDING_KEY, 1);
            }
            $this->output->writeln(date('Y-m-d H:i:s') . ' CoreSync run: ' . $outcome);
        } catch (\Throwable $error) {
            return $this->operationError('run', $error);
        }

        return $outcome === SyncRunner::OUTCOME_LOCK_UNAVAILABLE ? self::FAILURE : self::SUCCESS;
    }

    protected function syncRunner(): SyncRunner
    {
        return $this->serviceLocator->getService(SyncRunner::class);
    }

    protected function lockHelper(): LockHelper
    {
        return $this->serviceLocator->getService(LockHelper::class);
    }

    protected function backgroundRun(): BackgroundRun
    {
        return $this->serviceLocator->getService(BackgroundRun::class);
    }
}
