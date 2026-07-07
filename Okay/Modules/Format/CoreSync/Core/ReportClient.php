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

        $payload = $this->buildPayload($status, $stats, $error);

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

    /**
     * Тело apply-report по контракту SAT-B: snapshot_version + status на ВЕРХНЕМ уровне, stats?
     * (nullable array), error_message? (НЕ «error»). SyncRunner складывает snapshot_version внутрь
     * $stats — вынимаем его наверх. Стык SAT-RT: без верхнеуровневого snapshot_version/error_message
     * ядро отвечает 422, отчёт не сохраняется, health вкладки «Сателлит» не двигается.
     *
     * @param array<string, mixed> $stats
     */
    protected function buildPayload(string $status, array $stats, ?string $error): string
    {
        $snapshotVersion = null;
        if (array_key_exists('snapshot_version', $stats)) {
            $snapshotVersion = (int) $stats['snapshot_version'];
            unset($stats['snapshot_version']);
        }

        return (string) json_encode([
            'snapshot_version' => $snapshotVersion,
            'status'           => $status,
            'stats'            => $stats !== [] ? $stats : null,
            'error_message'    => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
