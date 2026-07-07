<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;

/**
 * Прод-реализация FileCheckpointStore поверх таблицы job_files, привязанная к конкретному job'у.
 * $entity намеренно без строгого типа — приходит из EntityFactory::get() (mixed), как и в остальном Okay.
 */
class JobFilesCheckpointStore implements FileCheckpointStore
{
    /** @var CoreSyncJobFilesEntity */
    private $entity;

    /** @var int|string */
    private $jobId;

    /**
     * @param CoreSyncJobFilesEntity $entity
     * @param int|string             $jobId
     */
    public function __construct($entity, $jobId)
    {
        $this->entity = $entity;
        $this->jobId = $jobId;
    }

    public function getStatus(string $name): ?string
    {
        return $this->entity->getFileStatus($this->jobId, $name);
    }

    public function setStatus(string $name, string $status): void
    {
        $this->entity->setFileStatus($this->jobId, $name, $status);
    }
}
