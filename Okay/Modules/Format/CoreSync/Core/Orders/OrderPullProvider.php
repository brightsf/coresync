<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Выдача заказов ядру (FEAT-ORD-M, order.schema.json). Заказ = типовые поля `__orders` + позиции
 * `__purchases` (снимок как есть) с обратным маппингом варианта local→external по карте владения.
 * «Собираем широко»: весь объект уедет в `orders.payload` ядра (README §«собираем широко,
 * потребляем узко») — модуль шлёт типовые поля схемы, ядро сохраняет объект целиком.
 */
class OrderPullProvider extends AbstractPullProvider
{
    private const PURCHASES_TABLE = '__purchases';
    private const MAP_TABLE = '__format__coresync_map';

    /** Тип сущности карты владения для обратного маппинга варианта (Contract::ENTITY_VARIANT). */
    private const MAP_ENTITY_VARIANT = 'variant';

    protected function entityType(): string
    {
        return Contract::OUT_ENTITY_ORDER;
    }

    protected function responseKey(): string
    {
        return 'orders';
    }

    protected function sourceTable(): string
    {
        return '__orders';
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function serializeBatch(array $rows): array
    {
        $orderIds = $this->rowIds($rows);
        $purchasesByOrder = $this->fetchPurchases($orderIds);

        // Все локальные id вариантов пачки → обратная карта local→external одним запросом.
        $variantIds = [];
        foreach ($purchasesByOrder as $purchases) {
            foreach ($purchases as $p) {
                $vid = (int) ($p->variant_id ?? 0);
                if ($vid > 0) {
                    $variantIds[$vid] = $vid;
                }
            }
        }
        $reverseMap = $this->reverseVariantMap(array_values($variantIds));
        $currency = $this->mainCurrencyCode();

        $records = [];
        foreach ($rows as $order) {
            $records[] = $this->serializeOrder($order, $purchasesByOrder[(int) $order->id] ?? [], $reverseMap, $currency);
        }

        return $records;
    }

    /**
     * @param object                     $order
     * @param array<int, object>         $purchases
     * @param array<int, string>         $reverseMap local variant_id → external_variant_id ядра
     * @return array<string, mixed>
     */
    private function serializeOrder(object $order, array $purchases, array $reverseMap, ?string $currency): array
    {
        $name = trim((string) ($order->name ?? '') . ' ' . (string) ($order->last_name ?? ''));

        return [
            'external_id'     => (string) $order->id,
            // Статус на стороне сателлита — задел под обратный статус-обмен; потребителя v1 нет
            // (README), поэтому снимок не собираем (лок. status_id — числовой id справочника, не строка).
            'external_status' => null,
            'customer'        => [
                'name'    => $name !== '' ? $name : null,
                'phone'   => $this->stringOrNull($order->phone ?? null),
                'email'   => $this->stringOrNull($order->email ?? null),
                'comment' => $this->stringOrNull($order->comment ?? null),
            ],
            'total'           => $this->money($order->total_price ?? null, $currency),
            'ordered_at'      => $this->isoOrNull($order->date ?? null),
            'items'           => $this->serializeItems($purchases, $reverseMap, $currency),
        ];
    }

    /**
     * @param array<int, object> $purchases
     * @param array<int, string> $reverseMap
     * @return array<int, array<string, mixed>>
     */
    private function serializeItems(array $purchases, array $reverseMap, ?string $currency): array
    {
        $items = [];
        $position = 0;
        foreach ($purchases as $p) {
            $variantId = (int) ($p->variant_id ?? 0);
            // external_variant_id = product_variants.id ЯДРА из карты владения; нет строки → null,
            // снимок (name/sku/qty/price) обязателен всегда (спека §4).
            $external = ($variantId > 0 && isset($reverseMap[$variantId])) ? $reverseMap[$variantId] : null;

            $name = trim((string) ($p->product_name ?? '') . ' ' . (string) ($p->variant_name ?? ''));

            $items[] = [
                'external_variant_id' => $external,
                'name'                => $name !== '' ? $name : null,
                'sku'                 => $this->stringOrNull($p->sku ?? null),
                'qty'                 => (float) ($p->amount ?? 0),
                'price'               => $this->money($p->price ?? null, $currency),
                'position'            => $position,
            ];
            $position++;
        }

        return $items;
    }

    /**
     * Позиции заказов пачки, сгруппированные по order_id (шов для тестов).
     *
     * @param array<int> $orderIds
     * @return array<int, array<int, object>>
     */
    protected function fetchPurchases(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $select = $this->queryFactory->newSelect();
        $select->cols(['id', 'order_id', 'variant_id', 'product_name', 'variant_name', 'price', 'amount', 'sku'])
            ->from(self::PURCHASES_TABLE)
            ->where('order_id IN (:ids)')->bindValue('ids', $orderIds)
            ->orderBy(['order_id ASC', 'id ASC']);

        $this->db->query($select);

        $byOrder = [];
        foreach ((array) $this->db->results() as $row) {
            $byOrder[(int) $row->order_id][] = $row;
        }

        return $byOrder;
    }

    /**
     * Обратная карта local variant_id → external_id ядра (шов для тестов).
     *
     * @param array<int> $variantIds
     * @return array<int, string>
     */
    protected function reverseVariantMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $select = $this->queryFactory->newSelect();
        $select->cols(['local_id', 'external_id'])
            ->from(self::MAP_TABLE)
            ->where('entity_type = :type')->bindValue('type', self::MAP_ENTITY_VARIANT)
            ->where('local_id IN (:ids)')->bindValue('ids', $variantIds);

        $this->db->query($select);

        $map = [];
        foreach ((array) $this->db->results() as $row) {
            $local = (int) $row->local_id;
            if ($local > 0 && $row->external_id !== null && (string) $row->external_id !== '') {
                $map[$local] = (string) $row->external_id;
            }
        }

        return $map;
    }

    /** Код основной валюты канала (снимок суммы; шов для тестов). null → total/price = null. */
    protected function mainCurrencyCode(): ?string
    {
        /** @var CurrenciesEntity $currencies */
        $currencies = $this->entityFactory->get(CurrenciesEntity::class);
        $main = $currencies->getMainCurrency();
        $code = is_object($main) ? (string) ($main->code ?? '') : '';

        return $code !== '' ? $code : null;
    }
}
