<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Psr\Log\LoggerInterface;

/**
 * Пинок ядра «есть новые заказы/заявки» (FEAT-ORD-M, спека §5; образец {@see \Okay\Modules\Format\
 * CoreSync\Core\ReportClient}). Лёгкий inbound на токен-URL ядра:
 *
 *   POST {core_url}/api/satellite/{channel_code}/events?token=…
 *   {"event":"orders"}   // либо {"event":"requests"}
 *
 * **Ускоритель, не транспорт**: потеря пинка ничего не теряет — расписание ядра (`satellites:
 * pull-orders`) догонит. Best-effort: недоступное ядро НЕ ломает оформление заказа (warning, не
 * бросок). За стоп-краном (выключенный модуль с ядром не говорит). Токен НЕ логируется (KI-06).
 */
class EventPingClient
{
    /** Таймаут пинка (короткий — это лишь будильник). */
    private const HTTP_TIMEOUT = 5;

    /** @var Settings */
    private $settings;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(Settings $settings, ?LoggerInterface $logger = null)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /** Пинок «новый заказ». */
    public function pingOrders(): void
    {
        $this->ping(Contract::EVENT_ORDERS);
    }

    /** Пинок «новая заявка». */
    public function pingRequests(): void
    {
        $this->ping(Contract::EVENT_REQUESTS);
    }

    private function ping(string $event): void
    {
        $cfg = $this->settings->get(Contract::SETTINGS_KEY);
        $cfg = is_array($cfg) ? $cfg : [];

        // Стоп-кран: выключенный модуль не будит ядро (симметрично pull за стоп-краном).
        if (!Contract::isEnabled($cfg)) {
            return;
        }

        $coreUrl = trim((string) ($cfg['core_url'] ?? ''));
        $channel = trim((string) ($cfg['channel_code'] ?? ''));
        $token = (string) ($cfg['token'] ?? '');
        if ($coreUrl === '' || $channel === '' || $token === '') {
            return; // не настроено — не пингуем (крон ядра всё равно догонит)
        }

        $url = rtrim($coreUrl, '/')
            . '/api/satellite/' . rawurlencode($channel)
            . '/events?token=' . rawurlencode($token);

        $payload = (string) json_encode(['event' => $event]);
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
            ],
        ]);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false && $this->logger !== null) {
                $this->logger->warning('CoreSync: пинок events (' . $event . ') не доставлен');
            }
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning('CoreSync: ошибка пинка events: ' . $e->getMessage());
            }
        }
    }
}
