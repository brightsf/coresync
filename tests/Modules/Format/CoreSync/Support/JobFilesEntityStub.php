<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Заглушка CoreSyncJobFilesEntity для интеграционных тестов SyncRunner.
 */
final class JobFilesEntityStub
{
    /** @var list<array<int, array<string, mixed>>> */
    public $seedCalls = [];

    /** @var array<string, string> name => status */
    public $statuses = [];

    /**
     * @param array<int, array<string, mixed>> $files
     */
    public function seedFiles($jobId, array $files): void
    {
        $this->seedCalls[] = $files;
        foreach ($files as $file) {
            $this->statuses[(string) ($file['name'] ?? '')] = Contract::FILE_PENDING;
        }
    }

    public function getFileStatus($jobId, string $name): ?string
    {
        return $this->statuses[$name] ?? null;
    }

    public function setFileStatus($jobId, string $name, string $status): void
    {
        $this->statuses[$name] = $status;
    }

    public function countVerified($jobId): int
    {
        $n = 0;
        foreach ($this->statuses as $status) {
            if ($status === Contract::FILE_VERIFIED) {
                $n++;
            }
        }

        return $n;
    }
}
