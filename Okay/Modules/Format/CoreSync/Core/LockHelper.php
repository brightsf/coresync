<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Flock-lock прогона ПОВЕРХ scheduler-overlap: защищает AJAX-путь «Запустить сейчас»,
 * который scheduler'ом не охраняется. Второй запуск при живом lock → false (не ошибка).
 */
class LockHelper
{
    const RESOURCE = 'format_coresync_sync_runner';

    /** @var LockInterface */
    private $lock;

    public function __construct()
    {
        $factory = new LockFactory(new FlockStore());
        $this->lock = $factory->createLock(self::RESOURCE, 3600);
    }

    public function acquire(): bool
    {
        return $this->lock->acquire();
    }

    public function release(): void
    {
        if ($this->lock->isAcquired()) {
            $this->lock->release();
        }
    }
}
