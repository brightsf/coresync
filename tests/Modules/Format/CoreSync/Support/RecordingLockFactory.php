<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/** Фабрика ядра с записью имён: имя замка задачи считает ядро, тест его только наблюдает. */
class RecordingLockFactory extends LockFactory
{
    /** @var string[] */
    public $resources = [];

    /** @var LockInterface[] */
    public $locks = [];

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): LockInterface
    {
        $lock = parent::createLock($resource, $ttl, $autoRelease);
        $this->resources[] = $resource;
        $this->locks[] = $lock;

        return $lock;
    }
}
