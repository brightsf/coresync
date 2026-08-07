<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Core\EntityFactory;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncOrdersOutEntity;
use Psr\Log\LoggerInterface;

/**
 * ACK заказов/заявок (FEAT-ORD-M, спека §5): по курсору ядра пометить выданное «принято» и перевести
 * заказы в статус-маркер «принят в обработку» (заявки → processed=1). Ack — единственный обратный
 * сигнал v1 (решение владельца §3.5). Kill-проба §9.2: ack не пришёл → заказ остаётся «новым» и
 * переизвлекается следующим pull (гарантирует идемпотентная выборка `id > cursor` провайдера).
 */
class AckService
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var OrdersStatusMarker */
    private $statusMarker;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(EntityFactory $entityFactory, OrdersStatusMarker $statusMarker, ?LoggerInterface $logger = null)
    {
        $this->entityFactory = $entityFactory;
        $this->statusMarker = $statusMarker;
        $this->logger = $logger;
    }

    /**
     * ACK заказов. Ответ контракта — `{"ok":true}` (иначе ядро не двигает курсор и переизвлекает).
     *
     * @return array{ok:bool}
     */
    public function ackOrders(?string $cursor): array
    {
        $cursorInt = $this->cursorInt($cursor);
        if ($cursorInt <= 0) {
            return ['ok' => true]; // нечего подтверждать — идемпотентный no-op
        }

        $markerId = $this->statusMarker->ensureAcceptedStatusId();
        $newStatusId = $this->statusMarker->defaultNewStatusId();

        /** @var CoreSyncOrdersOutEntity $out */
        $out = $this->entityFactory->get(CoreSyncOrdersOutEntity::class);
        $acked = $out->ackOrders($cursorInt, $markerId, $newStatusId);

        if ($this->logger !== null && $acked !== []) {
            $this->logger->info('CoreSync ack_orders: подтверждено ' . count($acked) . ' (cursor=' . $cursorInt . ')');
        }

        return ['ok' => true];
    }

    /**
     * ACK заявок.
     *
     * @return array{ok:bool}
     */
    public function ackRequests(?string $cursor): array
    {
        $cursorInt = $this->cursorInt($cursor);
        if ($cursorInt <= 0) {
            return ['ok' => true];
        }

        /** @var CoreSyncOrdersOutEntity $out */
        $out = $this->entityFactory->get(CoreSyncOrdersOutEntity::class);
        $acked = $out->ackRequests($cursorInt);

        if ($this->logger !== null && $acked !== []) {
            $this->logger->info('CoreSync ack_requests: подтверждено ' . count($acked) . ' (cursor=' . $cursorInt . ')');
        }

        return ['ok' => true];
    }

    private function cursorInt(?string $cursor): int
    {
        return ($cursor !== null && $cursor !== '' && ctype_digit($cursor)) ? (int) $cursor : 0;
    }
}
