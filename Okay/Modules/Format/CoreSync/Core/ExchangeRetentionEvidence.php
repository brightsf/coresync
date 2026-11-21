<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\EntityFactory;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;

/**
 * Read-only native Okay evidence. Failure or an oversized history means "unknown", never delete.
 * Модуль ProductDocuments не читается: PDF старого пути в staging ротация держит без доказательств.
 */
class ExchangeRetentionEvidence
{
    private const MAX_FILES = 128;
    /** Пакет store несёт все PDF копий; после переноса в хранилище их строки job - не staging. */
    private const MAX_STORE_FILES = 16384;
    /** @var EntityFactory */ private $entities;
    /** @var ProductDocumentStore|null */ private $documentStore;

    public function __construct(EntityFactory $entities, ?ProductDocumentStore $documentStore = null)
    {
        $this->entities = $entities;
        $this->documentStore = $documentStore;
    }

    /** @return int[] */
    public function currentAppliedVersions(): array
    {
        return $this->entities->get(CoreSyncJobsEntity::class)->retentionAppliedVersions();
    }

    /** @param int[] $versions @return array<int,string> */
    public function statusSummary(array $versions): array
    {
        return $this->entities->get(CoreSyncJobsEntity::class)->retentionStatusSummary($versions);
    }

    /** @return array<string,mixed>|null */
    public function facts(int $version): ?array
    {
        /** @var CoreSyncJobsEntity $jobs */
        $jobs = $this->entities->get(CoreSyncJobsEntity::class);
        if ((int) $jobs->count(['snapshot_version' => $version]) !== 1) { return null; }
        $job = $jobs->findOne(['snapshot_version' => $version]);
        if (!$job || empty($job->id) || empty($job->finished_at)) { return null; }
        /** @var CoreSyncJobFilesEntity $jobFiles */
        $jobFiles = $this->entities->get(CoreSyncJobFilesEntity::class);
        $count = (int) $jobFiles->count(['job_id' => $job->id]);
        // Больше 128 строк допускается только при своём хранилище: сколько из них реально в staging,
        // решает ExchangeRetentionManager по статусам (документы store со статусом applied staging
        // не держат). Таблицы хранилища здесь не читаются: до update_1_9_0 их может не быть.
        $limit = $this->documentStore !== null ? self::MAX_STORE_FILES : self::MAX_FILES;
        if ($count < 1 || $count > $limit || $count !== (int) $job->files_total) { return null; }
        $rows = $jobFiles->find(['job_id' => $job->id, 'limit' => $limit + 1]);
        if (!is_array($rows) || count($rows) !== $count) { return null; }
        $files = [];
        foreach ($rows as $row) {
            $files[] = [
                'name' => (string) $row->name, 'sha256' => (string) $row->sha256_expected,
                'bytes' => (int) $row->bytes, 'status' => (string) $row->status,
            ];
        }
        return [
            'status' => (string) $job->status, 'phase' => (string) $job->phase,
            'job_id' => (int) $job->id, 'finished_at' => (string) $job->finished_at,
            'error' => $job->error_message === null ? null : (string) $job->error_message,
            'files' => $files,
        ];
    }
}
