<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Modules\Format\CoreSync\Core\Orders\AckService;
use Okay\Modules\Format\CoreSync\Core\Orders\OrdersStatusMarker;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncOrdersOutEntity;
use PHPUnit\Framework\TestCase;

/**
 * Оркестрация ack (acceptance §2): резолв статус-маркера + дефолтного «нового» статуса, делегирование
 * в журнал, ответ `{ok:true}`. Пустой/битый курсор — идемпотентный no-op (журнал не трогаем).
 */
class AckServiceTest extends TestCase
{
    private function service(CoreSyncOrdersOutEntity $out, OrdersStatusMarker $marker): AckService
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturn($out);

        return new AckService($factory, $marker);
    }

    public function testAckOrdersResolvesMarkerAndDelegates(): void
    {
        $out = $this->createMock(CoreSyncOrdersOutEntity::class);
        $out->expects($this->once())->method('ackOrders')->with(6, 99, 1)->willReturn([6]);

        $marker = $this->createMock(OrdersStatusMarker::class);
        $marker->expects($this->once())->method('ensureAcceptedStatusId')->willReturn(99);
        $marker->expects($this->once())->method('defaultNewStatusId')->willReturn(1);

        $res = $this->service($out, $marker)->ackOrders('6');

        $this->assertSame(['ok' => true], $res);
    }

    public function testAckOrdersWithNullCursorIsNoop(): void
    {
        $out = $this->createMock(CoreSyncOrdersOutEntity::class);
        $out->expects($this->never())->method('ackOrders');

        $marker = $this->createMock(OrdersStatusMarker::class);
        $marker->expects($this->never())->method('ensureAcceptedStatusId');

        $this->assertSame(['ok' => true], $this->service($out, $marker)->ackOrders(null));
    }

    public function testAckRequestsDelegates(): void
    {
        $out = $this->createMock(CoreSyncOrdersOutEntity::class);
        $out->expects($this->once())->method('ackRequests')->with(7)->willReturn([7]);

        $marker = $this->createMock(OrdersStatusMarker::class);

        $this->assertSame(['ok' => true], $this->service($out, $marker)->ackRequests('7'));
    }
}
