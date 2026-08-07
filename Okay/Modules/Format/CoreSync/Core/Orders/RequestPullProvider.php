<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Выдача заявок ядру (FEAT-ORD-M, request.schema.json). Заявка Okay = обратный звонок `__callbacks`
 * (name/phone/message/date/url) → `type=callback`; сообщение едет в `customer.comment`, страница — в
 * свободный `fields`. Весь объект уедет в `requests.payload` ядра.
 */
class RequestPullProvider extends AbstractPullProvider
{
    protected function entityType(): string
    {
        return Contract::OUT_ENTITY_REQUEST;
    }

    protected function responseKey(): string
    {
        return 'requests';
    }

    protected function sourceTable(): string
    {
        return '__callbacks';
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function serializeBatch(array $rows): array
    {
        $records = [];
        foreach ($rows as $callback) {
            $page = $this->stringOrNull($callback->url ?? null);
            $records[] = [
                'external_id'     => (string) $callback->id,
                'type'            => Contract::REQUEST_TYPE_CALLBACK,
                'external_status' => null,
                'customer'        => [
                    'name'    => $this->stringOrNull($callback->name ?? null),
                    'phone'   => $this->stringOrNull($callback->phone ?? null),
                    'email'   => null,
                    'comment' => $this->stringOrNull($callback->message ?? null),
                ],
                'total'           => null,
                'ordered_at'      => $this->isoOrNull($callback->date ?? null),
                'fields'          => $page !== null ? ['page' => $page] : new \stdClass(),
            ];
        }

        return $records;
    }
}
