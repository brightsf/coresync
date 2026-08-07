<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Единая точка обработки order-action'ов, приходящих ВХОДЯЩИМ HMAC-каналом (тот же `/coresync/ping`,
 * что пинок/церемония — README ядра §«Заказы и заявки: pull + ack»). PingController уже проверил
 * подпись и стоп-кран; сюда доходит только валидное тело за стоп-краном. Фасад маршрутизирует
 * `action` в провайдер/ack и отдаёт массив-конверт для JSON-ответа.
 */
class OrdersSyncGateway
{
    /** @var OrderPullProvider */
    private $orderPull;
    /** @var RequestPullProvider */
    private $requestPull;
    /** @var AckService */
    private $ack;

    public function __construct(OrderPullProvider $orderPull, RequestPullProvider $requestPull, AckService $ack)
    {
        $this->orderPull = $orderPull;
        $this->requestPull = $requestPull;
        $this->ack = $ack;
    }

    /**
     * @param string               $action один из Contract::ORDER_ACTIONS
     * @param array<string, mixed> $payload декодированное тело запроса
     * @return array<string, mixed>
     */
    public function handle(string $action, array $payload): array
    {
        switch ($action) {
            case Contract::ACTION_PULL_ORDERS:
                return $this->orderPull->pull($this->cursor($payload), $this->limit($payload));
            case Contract::ACTION_PULL_REQUESTS:
                return $this->requestPull->pull($this->cursor($payload), $this->limit($payload));
            case Contract::ACTION_ACK_ORDERS:
                return $this->ack->ackOrders($this->cursor($payload));
            case Contract::ACTION_ACK_REQUESTS:
                return $this->ack->ackRequests($this->cursor($payload));
            default:
                // Недостижимо: PingController фильтрует action по Contract::ORDER_ACTIONS до вызова.
                return ['ok' => false];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cursor(array $payload): ?string
    {
        $cursor = $payload['cursor'] ?? null;

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function limit(array $payload): int
    {
        $limit = $payload['limit'] ?? null;

        return is_int($limit) ? $limit : Contract::ORDER_PULL_LIMIT_MAX;
    }
}
