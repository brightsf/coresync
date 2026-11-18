<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use InvalidArgumentException;
use LogicException;
use Okay\Core\EntityFactory;

final class PreviewEntityFactory extends EntityFactory
{
    /** @var array<string, PreviewRows> */
    private $entities;

    /** @param array<string, PreviewRows> $entities */
    public function __construct(array $entities)
    {
        foreach ($entities as $rows) {
            if (!$rows instanceof PreviewRows) {
                throw new InvalidArgumentException('Offline CoreSync requires in-memory entities');
            }
        }
        $this->entities = $entities;
    }

    public function get($class): PreviewRows
    {
        if (!isset($this->entities[$class])) {
            throw new LogicException("No offline adapter for {$class}");
        }

        return $this->entities[$class];
    }

    public function baselineDigest(): string
    {
        $digests = [];
        foreach ($this->entities as $class => $rows) {
            $digests[$class] = $rows->baselineDigest();
        }
        ksort($digests, SORT_STRING);

        return hash('sha256', serialize($digests));
    }

    public function changes(): array
    {
        $changes = [];
        foreach ($this->entities as $class => $rows) {
            if ($rows->changes() !== []) {
                $changes[$class] = $rows->changes();
            }
        }

        return $changes;
    }
}
