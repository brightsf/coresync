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

    /**
     * «Дать хвосту картинок ещё круг» без полного перепринятия: у failed-строк обеих очередей
     * attempts=0 и error_code=null, state НЕ меняется — строка снова retryable и уйдёт в ближайший
     * тик. Карта, force-флаг и уже скачанные (done) картинки не трогаются: полный apply на витрине
     * стоит десятки GB I/O (D-CORESYNC-ADOPT-FIRST-CONNECT-COST), а хвост чинится этим.
     */
    public function retryFailedImages(): void
    {
        /** @var CoreSyncImagesEntity $imagesEntity */
        $imagesEntity = $this->entityFactory->get(CoreSyncImagesEntity::class);
        $imagesEntity->resetFailedAttempts();

        /** @var CoreSyncCategoryImagesEntity $categoryImagesEntity */
        $categoryImagesEntity = $this->entityFactory->get(CoreSyncCategoryImagesEntity::class);
        $categoryImagesEntity->resetFailedAttempts();

        $this->info('CoreSync operation: retry-images completed');
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }
}
