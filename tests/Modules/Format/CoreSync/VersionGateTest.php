<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\VersionGate;
use PHPUnit\Framework\TestCase;

class VersionGateTest extends TestCase
{
    public function testFirstEverSnapshotRuns(): void
    {
        $this->assertSame(VersionGate::ACTION_RUN, VersionGate::decide(1, null));
    }

    public function testEqualVersionIsNoop(): void
    {
        $this->assertSame(VersionGate::ACTION_NOOP, VersionGate::decide(7, 7));
    }

    public function testOlderVersionIsIgnored(): void
    {
        $this->assertSame(VersionGate::ACTION_IGNORE, VersionGate::decide(6, 7));
    }

    public function testNewerVersionRuns(): void
    {
        $this->assertSame(VersionGate::ACTION_RUN, VersionGate::decide(8, 7));
    }
}
