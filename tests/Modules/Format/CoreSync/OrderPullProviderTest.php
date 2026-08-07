<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Orders\OrderPullProvider;
use PHPUnit\Framework\TestCase;

/**
 * Выдача заказов по order.schema.json (acceptance §1/§3): конверт по схеме, курсор=max id, честный
 * has_more, обратный маппинг варианта local→external (null вне карты), идемпотентность повторного pull.
 * DB-швы провайдера переопределены фикстурами — БД не нужна.
 */
class OrderPullProviderTest extends TestCase
{
    /**
     * @param array<int, object>          $orders
     * @param array<int, array<int,object>> $purchases
     * @param array<int, string>          $reverseMap
     */
    private function provider(array $orders, array $purchases, array $reverseMap, ?string $currency, bool $hasMore): TestOrderPullProvider
    {
        return new TestOrderPullProvider($orders, $purchases, $reverseMap, $currency, $hasMore);
    }

    private function order(int $id, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => $id, 'name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+380501234567',
            'email' => 'ivan@example.com', 'comment' => 'после 18:00', 'total_price' => '1499.00',
            'date' => '2026-07-17 09:15:00',
        ], $overrides);
    }

    private function purchase(int $variantId, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1, 'order_id' => 1, 'variant_id' => $variantId, 'product_name' => 'Насос',
            'variant_name' => 'погружной', 'price' => '749.50', 'amount' => '2', 'sku' => 'PMP-42',
        ], $overrides);
    }

    public function testEnvelopeShapeAndCursor(): void
    {
        $p = $this->provider(
            [$this->order(10), $this->order(11)],
            [10 => [$this->purchase(42, ['order_id' => 10])], 11 => [$this->purchase(43, ['order_id' => 11])]],
            [42 => '42', 43 => '43'],
            'UAH',
            true
        );

        $res = $p->pull(null, 100);

        $this->assertSame(Contract::SCHEMA_VERSION, $res['schema_version']);
        $this->assertCount(2, $res['orders']);
        $this->assertSame('11', $res['cursor'], 'курсор = max id пачки');
        $this->assertTrue($res['has_more']);
        $this->assertSame([10, 11], $p->deliveredIds, 'выдача зафиксирована в журнале');
    }

    public function testOrderSerializationMatchesSchema(): void
    {
        $p = $this->provider(
            [$this->order(10)],
            [10 => [$this->purchase(42, ['order_id' => 10])]],
            [42 => '42'],
            'UAH',
            false
        );

        $order = $p->pull(null, 100)['orders'][0];

        $this->assertSame('10', $order['external_id']);
        $this->assertNull($order['external_status']);
        $this->assertSame('Иван Петров', $order['customer']['name']);
        $this->assertSame('+380501234567', $order['customer']['phone']);
        $this->assertSame(['amount' => '1499.00', 'currency' => 'UAH'], $order['total']);
        // ISO-8601 со смещением зоны витрины (снимок как есть); проверяем стенную дату+формат, не офсет.
        $this->assertStringStartsWith('2026-07-17T09:15:00', (string) $order['ordered_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/', (string) $order['ordered_at']);

        $item = $order['items'][0];
        $this->assertSame('42', $item['external_variant_id']);
        $this->assertSame('Насос погружной', $item['name']);
        $this->assertSame('PMP-42', $item['sku']);
        $this->assertSame(2.0, $item['qty']);
        $this->assertSame(['amount' => '749.50', 'currency' => 'UAH'], $item['price']);
        $this->assertSame(0, $item['position']);
    }

    public function testVariantOutsideMapYieldsNullExternalWithSnapshot(): void
    {
        $p = $this->provider(
            [$this->order(10)],
            [10 => [$this->purchase(99, ['order_id' => 10])]], // вариант 99 не в карте
            [], // пустая карта
            'UAH',
            false
        );

        $item = $p->pull(null, 100)['orders'][0]['items'][0];

        $this->assertNull($item['external_variant_id'], 'вне карты → null');
        $this->assertSame('Насос погружной', $item['name'], 'снимок обязателен всегда');
        $this->assertSame('PMP-42', $item['sku']);
    }

    public function testNoCurrencyYieldsNullTotals(): void
    {
        $p = $this->provider(
            [$this->order(10)],
            [10 => [$this->purchase(42, ['order_id' => 10])]],
            [42 => '42'],
            null, // валюта канала не резолвится
            false
        );

        $order = $p->pull(null, 100)['orders'][0];
        $this->assertNull($order['total'], 'нет валюты → total=null (частичный запрещён схемой)');
        $this->assertNull($order['items'][0]['price'], 'нет валюты → price=null');
    }

    public function testEmptyBatchKeepsCursorAndNoMore(): void
    {
        $p = $this->provider([], [], [], 'UAH', false);

        $res = $p->pull('5', 100);

        $this->assertSame([], $res['orders']);
        $this->assertSame('5', $res['cursor'], 'пусто → курсор не двигаем');
        $this->assertFalse($res['has_more']);
        $this->assertSame([], $p->deliveredIds, 'пусто → ничего не фиксируем');
    }

    public function testRePullSameCursorIsDeterministic(): void
    {
        $make = fn () => $this->provider(
            [$this->order(10), $this->order(11)],
            [10 => [$this->purchase(42, ['order_id' => 10])], 11 => [$this->purchase(43, ['order_id' => 11])]],
            [42 => '42', 43 => '43'],
            'UAH',
            false
        );

        // Пока нет ack, ядро re-pull'ит тот же курсор — выборка `id > cursor` детерминирована.
        $this->assertSame($make()->pull(null, 100), $make()->pull(null, 100));
    }
}

/**
 * Провайдер с переопределёнными DB-швами: фикстуры вместо запросов. Конструктор НЕ зовёт родителя
 * (protected db/queryFactory/entityFactory остаются null — все их пользователи переопределены).
 */
class TestOrderPullProvider extends OrderPullProvider
{
    /** @var array<int, object> */
    private $orders;
    /** @var array<int, array<int, object>> */
    private $purchases;
    /** @var array<int, string> */
    private $reverse;
    /** @var string|null */
    private $currency;
    /** @var bool */
    private $hasMore;
    /** @var array<int> */
    public $deliveredIds = [];

    public function __construct(array $orders, array $purchases, array $reverse, ?string $currency, bool $hasMore)
    {
        $this->orders = $orders;
        $this->purchases = $purchases;
        $this->reverse = $reverse;
        $this->currency = $currency;
        $this->hasMore = $hasMore;
    }

    protected function fetchRows(int $cursor, int $limit): array
    {
        return $this->orders;
    }

    protected function hasMoreBeyond(int $maxId): bool
    {
        return $this->hasMore;
    }

    protected function recordDelivered(array $localIds): void
    {
        $this->deliveredIds = $localIds;
    }

    protected function fetchPurchases(array $orderIds): array
    {
        return $this->purchases;
    }

    protected function reverseVariantMap(array $variantIds): array
    {
        return $this->reverse;
    }

    protected function mainCurrencyCode(): ?string
    {
        return $this->currency;
    }
}
