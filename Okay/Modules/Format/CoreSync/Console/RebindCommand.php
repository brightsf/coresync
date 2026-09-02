<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Symfony\Component\Console\Input\InputOption;

class RebindCommand extends CoreSyncCommand
{
    protected static $defaultName = 'coresync:rebind';
    protected static $defaultDescription = 'Reset CoreSync product and variant ownership for a new bind';

    protected function configure(): void
    {
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm the destructive reset');
    }

    protected function handle()
    {
        if (!$this->input->getOption('yes')) {
            return $this->refuse('Refused: pass --yes to confirm coresync:rebind; no state was changed.');
        }

        try {
            if ($this->jobs()->hasActiveRun()) {
                return $this->refuse('Refused: CoreSync has an active run; no state was changed.');
            }
            if (!Contract::isEnabled($this->settings()->get(Contract::SETTINGS_KEY))) {
                return $this->refuse(
                    'Модуль выключен в настройках — связывание не сброшено. '
                    . 'Включите «Модуль включён» и сохраните настройки.'
                );
            }

            $this->startEnabledModules();
            $this->resetCeremony()->rebind();
            $this->output->writeln('CoreSync rebind completed.');
        } catch (\Throwable $error) {
            return $this->operationError('rebind', $error);
        }

        return self::SUCCESS;
    }
}
