<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Symfony\Component\Console\Input\InputOption;

/**
 * Сбросить попытки скачивания у failed-картинок, чтобы ближайший тик снова взял хвост.
 *
 * Не «перепринять»: state строк не меняется, карта и done-картинки не трогаются — только
 * attempts=0 и error_code=null у failed-строк обеих очередей. Предусловия дословно как у
 * coresync:reapply: обязательный --yes и отказ при активном прогоне.
 */
class RetryImagesCommand extends CoreSyncCommand
{
    protected static $defaultName = 'coresync:retry-images';
    protected static $defaultDescription = 'Reset failed image attempts so the next tick retries the tail';

    protected function configure(): void
    {
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm the attempts reset');
    }

    protected function handle()
    {
        if (!$this->input->getOption('yes')) {
            return $this->refuse('Refused: pass --yes to confirm coresync:retry-images; no state was changed.');
        }

        try {
            if ($this->jobs()->hasActiveRun()) {
                return $this->refuse('Refused: CoreSync has an active run; no state was changed.');
            }

            $this->startEnabledModules();
            $this->output->writeln('CoreSync retry-images before: ' . $this->describeQueues());
            $this->resetCeremony()->retryFailedImages();
            $this->output->writeln('CoreSync retry-images after: ' . $this->describeQueues());
            $this->output->writeln('CoreSync retry-images completed.');
        } catch (\Throwable $error) {
            return $this->operationError('retry-images', $error);
        }

        return self::SUCCESS;
    }

    /** Счётчики обеих очередей: сброс виден оператором как рост retryable за счёт exhausted. */
    private function describeQueues(): string
    {
        $factory = $this->entityFactory();

        return (string) json_encode([
            'product' => $this->queueCounts($factory->get(CoreSyncImagesEntity::class)),
            'category' => $this->queueCounts($factory->get(CoreSyncCategoryImagesEntity::class)),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param CoreSyncImagesEntity|CoreSyncCategoryImagesEntity $entity
     * @return array<string, int>
     */
    private function queueCounts($entity): array
    {
        $counts = $entity->countByState();
        $retryable = (int) $entity->countRetryableFailed(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS);
        $failed = (int) ($counts[Contract::IMAGE_STATE_FAILED] ?? 0);

        return [
            'pending' => (int) ($counts[Contract::IMAGE_STATE_PENDING] ?? 0),
            'done' => (int) ($counts[Contract::IMAGE_STATE_DONE] ?? 0),
            'failed' => $failed,
            'failed_retryable' => $retryable,
            'exhausted' => max(0, $failed - $retryable),
        ];
    }
}
