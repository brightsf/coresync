<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Symfony\Component\Console\Input\InputOption;

class ReapplyCommand extends CoreSyncCommand
{
    protected static $defaultName = 'coresync:reapply';
    protected static $defaultDescription = 'Reset CoreSync state so the current snapshot is applied again';

    protected function configure(): void
    {
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm the destructive reset');
    }

    protected function handle()
    {
        if (!$this->input->getOption('yes')) {
            return $this->refuse('Refused: pass --yes to confirm coresync:reapply; no state was changed.');
        }

        try {
            if ($this->jobs()->hasActiveRun()) {
                return $this->refuse('Refused: CoreSync has an active run; no state was changed.');
            }

            $this->startEnabledModules();
            $this->resetCeremony()->reapply();
            $this->output->writeln('CoreSync reapply completed.');
        } catch (\Throwable $error) {
            return $this->operationError('reapply', $error);
        }

        return self::SUCCESS;
    }
}
