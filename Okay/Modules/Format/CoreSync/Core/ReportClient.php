<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Psr\Log\LoggerInterface;

/**
 * Отправка apply-report ядру (POST). В M1 шлётся только failed (fail-closed / sha256-fail).
 * Недоставка отчёта — деградация (warning), а НЕ провал прогона. Токен не логируется.
 *
 *   POST {core_url}/api/satellite/{channel_code}/apply-report?token=…
 */
class ReportClient
{
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $stats
     */
    public function send(
        string $baseUrl,
        string $channelCode,
        string $token,
        string $status,
        array $stats,
        ?string $error
    ): void {
        $url = rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/apply-report?token=' . rawurlencode($token);

        $payload = json_encode([
            'status' => $status,
            'stats'  => $stats,
            'error'  => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
            ],
        ]);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false && $this->logger !== null) {
                $this->logger->warning('CoreSync: apply-report не доставлен (' . $status . ')');
            }
        } catch (\Exception $e) {
            if ($this->logger !== null) {
                $this->logger->warning('CoreSync: ошибка отправки apply-report: ' . $e->getMessage());
            }
        }
    }
}
