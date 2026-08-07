<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Orders\EventPingClient;
use Okay\Modules\Format\CoreSync\Extenders\OrdersHelperExtender;
use PHPUnit\Framework\TestCase;

/**
 * Пинок «есть новые заказы» — best-effort (acceptance §5): недоступное/невыключенное ядро НЕ ломает
 * оформление заказа. Выключенный/несконфигурированный модуль ядро не будит; падающий клиент не роняет
 * хук создания заказа (Extender глушит).
 */
class EventPingBestEffortTest extends TestCase
{
    /**
     * @param array<string, mixed> $cfg
     */
    private function client(array $cfg): EventPingClient
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(function (string $key) use ($cfg) {
            return $key === Contract::SETTINGS_KEY ? $cfg : null;
        });

        return new EventPingClient($settings);
    }

    public function testDisabledModuleDoesNotPingAndDoesNotThrow(): void
    {
        // enabled=0 → ранний возврат ДО любого сетевого вызова; не бросает.
        $this->client(['enabled' => 0, 'core_url' => 'http://core.example', 'channel_code' => '5', 'token' => 't'])
            ->pingOrders();

        $this->addToAssertionCount(1);
    }

    public function testUnconfiguredModuleDoesNotThrow(): void
    {
        // Нет core_url/channel/token → не пингуем, не бросаем.
        $this->client(['enabled' => 1])->pingOrders();

        $this->addToAssertionCount(1);
    }

    public function testUnreachableCoreDoesNotThrow(): void
    {
        // Заведомо недоступный адрес: file_get_contents фейлит → warning, НЕ бросок (best-effort).
        $this->client([
            'enabled' => 1,
            'core_url' => 'http://127.0.0.1:1', // закрытый порт
            'channel_code' => '5',
            'token' => 'secret',
        ])->pingOrders();

        $this->addToAssertionCount(1);
    }

    public function testExtenderSwallowsPingFailureAndReturnsResult(): void
    {
        // Хук создания заказа НЕ должен падать из-за пинка: клиент бросает — Extender глушит.
        $client = $this->createMock(EventPingClient::class);
        $client->method('pingOrders')->willThrowException(new \RuntimeException('ядро упало'));

        $extender = new OrdersHelperExtender($client);
        $order = (object) ['id' => 10];

        $this->assertSame('RESULT', $extender->extendFinalCreateOrderProcedure('RESULT', $order));
    }
}
