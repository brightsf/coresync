<?php

namespace Tests\Modules\Format\CoreSync\Support;

/**
 * Заглушка CoreSyncJobsEntity: mappedBy/find в Okay final, поэтому ручной стаб (паттерн APIImport).
 */
final class JobsEntityStub
{
    /** @var list<array<string, mixed>> */
    public $addCalls = [];

    /** @var list<array{0: mixed, 1: array<string, mixed>}> */
    public $updateCalls = [];

    /** @var int|null */
    public $lastDownloadedVersion = null;

    /** @var object|null */
    public $resumable = null;

    /** @var object|null */
    public $latest = null;

    /** @var bool */
    public $cancelRequested = false;

    /** @var int */
    private $nextId = 100;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;

        return $this->nextId++;
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($ids, $object): void
    {
        $this->updateCalls[] = [$ids, (array) $object];
    }

    public function getLastDownloadedVersion(): ?int
    {
        return $this->lastDownloadedVersion;
    }

    public function findResumable(int $version)
    {
        return $this->resumable;
    }

    public function isCancelRequested($jobId): bool
    {
        return $this->cancelRequested;
    }

    public function requestCancel($jobId): void
    {
        $this->cancelRequested = true;
    }

    public function findLatest()
    {
        return $this->latest;
    }

    /**
     * @return array<string, mixed>|null последний add со статусом $status
     */
    public function lastAddWithStatus(string $status): ?array
    {
        for ($i = count($this->addCalls) - 1; $i >= 0; $i--) {
            if (($this->addCalls[$i]['status'] ?? null) === $status) {
                return $this->addCalls[$i];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null последний update со статусом $status
     */
    public function lastUpdateWithStatus(string $status): ?array
    {
        for ($i = count($this->updateCalls) - 1; $i >= 0; $i--) {
            if (($this->updateCalls[$i][1]['status'] ?? null) === $status) {
                return $this->updateCalls[$i][1];
            }
        }

        return null;
    }
}
