<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;

/**
 * In-memory чекпоинт-хранилище для тестов downloader'а (resume/докачка без БД).
 */
final class InMemoryCheckpointStore implements FileCheckpointStore
{
    /** @var array<string, string> */
    private $statuses = [];

    public function getStatus(string $name): ?string
    {
        return $this->statuses[$name] ?? null;
    }

    public function setStatus(string $name, string $status): void
    {
        $this->statuses[$name] = $status;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->statuses;
    }
}
