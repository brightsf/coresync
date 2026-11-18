<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;

final class PreviewFileCheckpoints implements FileCheckpointStore
{
    private $statuses = [];

    public function getStatus(string $name): ?string
    {
        return $this->statuses[$name] ?? null;
    }

    public function setStatus(string $name, string $status): void
    {
        $this->statuses[$name] = $status;
    }
}
