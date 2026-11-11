<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Aura\Sql\ExtendedPdo;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Serialize lifecycle writes across runtimes connected to the same catalog database. */
final class FeatureLifecycleLock
{
    private $pdo;
    private $name;

    public function __construct(ExtendedPdo $pdo)
    {
        $this->pdo = $pdo;
    }

    public function acquire(): void
    {
        if ($this->name !== null) {
            throw new ManifestException('v5 feature lifecycle lock is already held by this runner');
        }
        $database = $this->pdo->fetchValue('SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            throw new ManifestException('v5 feature lifecycle database identity unavailable');
        }
        $name = hash('sha256', 'coresync-feature-lifecycle|' . $database);
        $result = $this->pdo->fetchValue('SELECT GET_LOCK(:scope, 0)', ['scope'=>$name]);
        if ((string) $result !== '1') {
            throw new ManifestException('v5 feature lifecycle catalog is locked by another runner');
        }
        $this->name = $name;
    }

    public function release(): void
    {
        if ($this->name === null) {
            return;
        }
        $name = $this->name;
        $this->name = null;
        $result = $this->pdo->fetchValue('SELECT RELEASE_LOCK(:scope)', ['scope'=>$name]);
        if ((string) $result !== '1') {
            throw new ManifestException('v5 feature lifecycle catalog lock release failed');
        }
    }
}
