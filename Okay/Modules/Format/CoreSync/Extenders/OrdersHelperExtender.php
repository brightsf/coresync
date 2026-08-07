<?php

namespace Okay\Modules\Format\CoreSync\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Format\CoreSync\Core\Orders\EventPingClient;

/**
 * Хук создания заказа (FEAT-ORD-M, спека §5). Подписан на `OrdersHelper::finalCreateOrderProcedure`
 * (queue-extension: сайд-эффект, возврат не меняем) — он фаерится ПОСЛЕ полного создания заказа с
 * позициями и суммами (разведка ядра Okay). Будим ядро пинком «есть новые заказы».
 *
 * Best-effort: пинок — ускоритель, а не транспорт. Любая ошибка проглатывается (клиент сам не
 * бросает; здесь дополнительный барьер) — оформление заказа НЕ должно падать из-за недоступного ядра.
 */
class OrdersHelperExtender implements ExtensionInterface
{
    /** @var EventPingClient */
    private $eventPingClient;

    public function __construct(EventPingClient $eventPingClient)
    {
        $this->eventPingClient = $eventPingClient;
    }

    /**
     * @param mixed $result возврат хука (не меняем — queue-extension)
     * @param mixed $order  созданный заказ (объект с ->id)
     * @return mixed
     */
    public function extendFinalCreateOrderProcedure($result, $order)
    {
        try {
            $this->eventPingClient->pingOrders();
        } catch (\Throwable $e) {
            // Оформление заказа важнее пинка — глушим (barrier поверх best-effort клиента).
        }

        return $result;
    }
}
