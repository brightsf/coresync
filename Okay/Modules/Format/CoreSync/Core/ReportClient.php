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
     * Отправить ядру живую identity установленного модуля. Недоставка — только warning:
     * следующий тик снова отправит inventory после update-check. Token и тело не логируются.
     */
    public function sendInventory(
        string $baseUrl,
        string $channelCode,
        string $token,
        string $moduleName,
        string $moduleVersion
    ): void {
        try {
            if (!$this->postInventory(
                $this->buildInventoryUrl($baseUrl, $channelCode, $token),
                $this->buildInventoryPayload($moduleName, $moduleVersion)
            )) {
                $this->warnInventoryFailure();
            }
        } catch (\Throwable $e) {
            $this->warnInventoryFailure();
        }
    }

    protected function buildInventoryUrl(string $baseUrl, string $channelCode, string $token): string
    {
        return rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/inventory?token=' . rawurlencode($token);
    }

    protected function buildInventoryPayload(string $moduleName, string $moduleVersion): string
    {
        return (string) json_encode([
            'module_name' => $moduleName,
            'module_version' => $moduleVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function postInventory(string $url, string $payload): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return false;
        }

        $headers = isset($http_response_header) && is_array($http_response_header)
            ? $http_response_header
            : [];

        return $this->isSuccessfulInventoryResponse($headers);
    }

    /**
     * @param array<int, string> $headers
     */
    protected function isSuccessfulInventoryResponse(array $headers): bool
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+([0-9]{3})(?:\s|$)~i', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status !== null && $status >= 200 && $status < 300;
    }

    private function warnInventoryFailure(): void
    {
        if ($this->logger !== null) {
            $this->logger->warning('CoreSync: inventory-report не доставлен');
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
