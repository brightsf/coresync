<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Psr\Log\LoggerInterface;

/**
 * HTTP-клиент выдачи ядра: получение манифеста и скачивание файлов набора с ретраями
 * (образец APIImport\DownloadHelper::fetchWithRetries). Токен НЕ логируется.
 *
 * URL-схема (пинуется SAT-B; при рассинхроне выравнивается follow-up'ом):
 *   GET {core_url}/api/satellite/{channel_code}/manifest.json?token=…
 *   GET {core_url}/api/satellite/{channel_code}/{file_name}?token=…
 */
class SnapshotHttpClient
{
    const MAX_ATTEMPTS = 5;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Скачать и вернуть сырое тело манифеста.
     *
     * @throws ManifestException
     */
    public function fetchManifest(string $baseUrl, string $channelCode, string $token): string
    {
        $url = $this->buildUrl($baseUrl, $channelCode, 'manifest.json');
        $body = $this->fetchWithRetries($url, $token);
        if ($body === null) {
            throw new ManifestException(
                'Не удалось получить манифест снапшота (проверьте адрес ядра, канал и токен)'
            );
        }

        return $body;
    }

    /**
     * Скачать файл набора в $destPath (перезапись). Директория создаётся при необходимости.
     *
     * @throws ManifestException
     */
    public function downloadFile(
        string $baseUrl,
        string $channelCode,
        string $token,
        string $fileName,
        string $destPath
    ): void {
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ManifestException('Не удалось создать staging-директорию: ' . $dir);
        }

        $url = $this->buildUrl($baseUrl, $channelCode, $fileName);
        $body = $this->fetchWithRetries($url, $token);
        if ($body === null) {
            throw new ManifestException('Не удалось скачать файл снапшота: ' . $fileName);
        }

        if (file_put_contents($destPath, $body) === false) {
            throw new ManifestException('Не удалось записать файл снапшота на диск: ' . $fileName);
        }
    }

    private function buildUrl(string $baseUrl, string $channelCode, string $fileName): string
    {
        return rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/' . ltrim($fileName, '/');
    }

    /**
     * @return string|null тело ответа или null при исчерпании попыток
     */
    private function fetchWithRetries(string $url, string $token): ?string
    {
        $withToken = $url . (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 30,
                'ignore_errors' => true,
                'header'        => "Accept: application/json, application/octet-stream\r\n",
            ],
        ]);

        $attempt = 0;
        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $http_response_header = [];
            $data = @file_get_contents($withToken, false, $context);
            $status = $this->statusFromHeaders($http_response_header ?? []);

            if ($data !== false && ($status === null || ($status >= 200 && $status < 300))) {
                return $data;
            }

            // 4xx (например 404 по токену/каналу) не лечится ретраем — выходим сразу.
            if ($status !== null && $status >= 400 && $status < 500) {
                if ($this->logger !== null) {
                    $this->logger->warning('CoreSync: HTTP ' . $status . ' при запросе ' . $url);
                }

                return null;
            }
        }

        if ($this->logger !== null) {
            $this->logger->warning('CoreSync: исчерпаны попытки скачивания ' . $url);
        }

        return null;
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusFromHeaders(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status ?? null;
    }
}
