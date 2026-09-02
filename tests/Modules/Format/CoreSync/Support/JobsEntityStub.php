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

    /** @var int|null последняя применённая версия (M2 version-гейт) */
    public $lastAppliedVersion = null;

    /** @var object|null */
    public $resumable = null;

    /** @var object|null */
    public $latest = null;

    /** @var bool */
    public $cancelRequested = false;

    /** @var list<string> statuses whose update is accepted by the API but not persisted */
    public $ignoredUpdateStatuses = [];

    /** @var array<int, array<string, mixed>> */
    private $rows = [];

    /** @var int */
    private $nextId = 100;

    /**
     * @param array<string, mixed> $object
     */
    public function add($object)
    {
        $object = (array) $object;
        $this->addCalls[] = $object;
        $id = $this->nextId++;
        $this->rows[$id] = $object + ['id' => $id];

        return $id;
    }

    /**
     * @param array<string, mixed> $object
     */
    public function update($ids, $object): void
    {
        $fields = (array) $object;
        $this->updateCalls[] = [$ids, $fields];
        if (isset($fields['status']) && in_array($fields['status'], $this->ignoredUpdateStatuses, true)) {
            return;
        }
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if (isset($this->rows[$id])) {
                $this->rows[$id] = array_merge($this->rows[$id], $fields);
            }
        }
    }

    public function get($id)
    {
        $id = (int) $id;

        return isset($this->rows[$id]) ? (object) $this->rows[$id] : null;
    }

    public function getLastDownloadedVersion(): ?int
    {
        return $this->lastDownloadedVersion;
    }

    public function getLastAppliedVersion(): ?int
    {
        return $this->lastAppliedVersion;
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
