<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Okay\Core\Console\Command;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Ops\ResetCeremony;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Psr\Log\LoggerInterface;

abstract class CoreSyncCommand extends Command
{
    protected function startEnabledModules(): void
    {
        /** @var Modules $modules */
        $modules = $this->serviceLocator->getService(Modules::class);
        $modules->startEnabledModules();
    }

    protected function settings(): Settings
    {
        return $this->serviceLocator->getService(Settings::class);
    }

    protected function entityFactory(): EntityFactory
    {
        return $this->serviceLocator->getService(EntityFactory::class);
    }

    protected function jobs(): CoreSyncJobsEntity
    {
        return $this->entityFactory()->get(CoreSyncJobsEntity::class);
    }

    protected function resetCeremony(): ResetCeremony
    {
        return new ResetCeremony($this->entityFactory(), $this->settings(), $this->logger());
    }

    protected function logger(): ?LoggerInterface
    {
        if (!$this->serviceLocator->hasService(LoggerInterface::class)) {
            return null;
        }

        return $this->serviceLocator->getService(LoggerInterface::class);
    }

    protected function refuse(string $message): int
    {
        $this->warning($message);
        $this->output->writeln('<error>' . $message . '</error>');

        return self::FAILURE;
    }

    protected function operationError(string $operation, \Throwable $error): int
    {
        $message = 'CoreSync ' . $operation . ' failed: ' . $error->getMessage();
        $this->warning($message);
        $this->output->writeln('<error>' . $message . '</error>');

        return 2;
    }

    private function warning(string $message): void
    {
        $logger = $this->logger();
        if ($logger !== null) {
            $logger->warning($message);
        }
    }
}
