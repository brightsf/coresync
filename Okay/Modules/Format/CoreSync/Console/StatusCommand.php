<?php

namespace Okay\Modules\Format\CoreSync\Console;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;

class StatusCommand extends CoreSyncCommand
{
    protected static $defaultName = 'coresync:status';
    protected static $defaultDescription = 'Show CoreSync module, job, ownership and image state';

    protected function handle()
    {
        try {
            $this->startEnabledModules();
            $factory = $this->entityFactory();

            /** @var CoreSyncMapEntity $mapEntity */
            $mapEntity = $factory->get(CoreSyncMapEntity::class);
            /** @var CoreSyncImagesEntity $imagesEntity */
            $imagesEntity = $factory->get(CoreSyncImagesEntity::class);
            /** @var CoreSyncCategoryImagesEntity $categoryImagesEntity */
            $categoryImagesEntity = $factory->get(CoreSyncCategoryImagesEntity::class);

            $productImages = $imagesEntity->countByState();
            $categoryImages = $categoryImagesEntity->countByState();
            $images = [];
            foreach (Contract::IMAGE_STATES as $state) {
                $images[$state] = ($productImages[$state] ?? 0) + ($categoryImages[$state] ?? 0);
            }

            $payload = [
                'enabled' => Contract::isEnabled($this->settings()->get(Contract::SETTINGS_KEY)),
                'job' => $this->jobPayload($this->jobs()->findLatest()),
                'ownership' => $mapEntity->countByType(),
                'images' => $images,
            ];
            $this->output->writeln((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $error) {
            return $this->operationError('status', $error);
        }

        return self::SUCCESS;
    }

    /**
     * @param object|null $job
     * @return array<string,mixed>|null
     */
    private function jobPayload($job): ?array
    {
        if (empty($job)) {
            return null;
        }

        return [
            'id' => (int) $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'snapshot_version' => $job->snapshot_version !== null ? (int) $job->snapshot_version : null,
            'files_total' => (int) $job->files_total,
            'files_done' => (int) $job->files_done,
            'cancel_requested' => (int) $job->cancel_requested,
            'error_message' => $job->error_message,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
        ];
    }
}
