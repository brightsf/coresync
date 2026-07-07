<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Чекпоинт файлов набора: строка на файл манифеста. Питает resume/докачку
 * (pending/failed перекачиваются, verified скипается).
 */
class CoreSyncJobFilesEntity extends Entity
{
    protected static $fields = [
        'id',
        'job_id',
        'name',
        'sha256_expected',
        'bytes',
        'status',
    ];

    protected static $table = '__format__coresync_job_files';
    protected static $tableAlias = 'csjf';
    protected static $defaultOrderFields = [
        'id ASC',
    ];

    /**
     * Засев строк файлов для job'а из манифеста (status=pending). Идемпотентность —
     * ответственность вызывающего (resume переиспользует существующий job).
     *
     * @param array<int, array<string, mixed>> $files
     */
    public function seedFiles($jobId, array $files): void
    {
        foreach ($files as $file) {
            $this->add([
                'job_id'          => $jobId,
                'name'            => (string) ($file['name'] ?? ''),
                'sha256_expected' => strtolower((string) ($file['sha256'] ?? '')),
                'bytes'           => (int) ($file['bytes'] ?? 0),
                'status'          => Contract::FILE_PENDING,
            ]);
        }
    }

    public function getFileStatus($jobId, string $name): ?string
    {
        $row = $this->findOne(['job_id' => $jobId, 'name' => $name]);
        if (empty($row)) {
            return null;
        }

        return (string) $row->status;
    }

    public function setFileStatus($jobId, string $name, string $status): void
    {
        $row = $this->findOne(['job_id' => $jobId, 'name' => $name]);
        if (empty($row)) {
            return;
        }
        $this->update($row->id, ['status' => $status]);
    }

    public function countVerified($jobId): int
    {
        return (int) $this->count(['job_id' => $jobId, 'status' => Contract::FILE_VERIFIED]);
    }
}
