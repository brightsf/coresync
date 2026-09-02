<?php

namespace Okay\Modules\Format\CoreSync\Core\Ops;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Psr\Log\LoggerInterface;

/** Shared implementation of the destructive reset ceremonies exposed by admin and CLI. */
class ResetCeremony
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var Settings */
    private $settings;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(EntityFactory $entityFactory, Settings $settings, ?LoggerInterface $logger = null)
    {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function rebind(): void
    {
        /** @var CoreSyncMapEntity $mapEntity */
        $mapEntity = $this->entityFactory->get(CoreSyncMapEntity::class);
        $mapEntity->resetForRebind();
        $this->info('CoreSync operation: rebind completed');
    }

    public function reapply(): void
    {
        /** @var CoreSyncMapEntity $mapEntity */
        $mapEntity = $this->entityFactory->get(CoreSyncMapEntity::class);
        $mapEntity->resetForReapply();

        /** @var CoreSyncImagesEntity $imagesEntity */
        $imagesEntity = $this->entityFactory->get(CoreSyncImagesEntity::class);
        $imagesEntity->resetStatesToPending();

        /** @var CoreSyncCategoryImagesEntity $categoryImagesEntity */
        $categoryImagesEntity = $this->entityFactory->get(CoreSyncCategoryImagesEntity::class);
        $categoryImagesEntity->resetStatesToPending();

        $this->settings->set(Contract::SETTINGS_FORCE_REAPPLY_KEY, 1);
        $this->info('CoreSync operation: reapply completed');
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }
}
