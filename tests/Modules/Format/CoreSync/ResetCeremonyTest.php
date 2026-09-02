<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Ops\ResetCeremony;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResetCeremonyTest extends TestCase
{
    public function testRebindDelegatesToTheMarkersFirstOwnershipReset(): void
    {
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->once())->method('resetForRebind');

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->once())->method('get')
            ->with(CoreSyncMapEntity::class)
            ->willReturn($map);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('CoreSync operation: rebind completed');

        $ceremony = new ResetCeremony($factory, $this->createMock(Settings::class), $logger);

        $ceremony->rebind();
    }

    public function testReapplyResetsMapAndBothImageKindsBeforeSettingForceFlag(): void
    {
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->once())->method('resetForReapply');
        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->expects($this->once())->method('resetStatesToPending');
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->expects($this->once())->method('resetStatesToPending');

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->exactly(3))->method('get')
            ->withConsecutive(
                [CoreSyncMapEntity::class],
                [CoreSyncImagesEntity::class],
                [CoreSyncCategoryImagesEntity::class]
            )
            ->willReturnOnConsecutiveCalls($map, $images, $categoryImages);

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('set')
            ->with(Contract::SETTINGS_FORCE_REAPPLY_KEY, 1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('CoreSync operation: reapply completed');

        $ceremony = new ResetCeremony($factory, $settings, $logger);

        $ceremony->reapply();
    }
}
