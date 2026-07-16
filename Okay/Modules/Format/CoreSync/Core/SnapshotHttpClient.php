<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Psr\Log\LoggerInterface;

/**
 * HTTP-клиент выдачи ядра: получение манифеста и скачивание файлов набора с ретраями
 * (образец APIImport\DownloadHelper::fetchWithRetries). Токен НЕ логируется.
 *
 * URL-схема (пин SAT-B §1 — сегмент /files/ для файлов набора):
 *   GET {core_url}/api/satellite/{channel_code}/manifest.json?token=…
 *   GET {core_url}/api/satellite/{channel_code}/files/{file_name}?token=…
 */
class SnapshotHttpClient
{
    const MAX_ATTEMPTS = 5;

    /**
     * Кэп тела ответа на манифест — малый JSON (список файлов). 1 МиБ = огромный запас над реальным
     * манифестом (сотни байт даже на тысячи files[]-записей); враждебный/сломанный core не выест память.
     */
    const MAX_MANIFEST_BYTES = 1048576; // 1 MiB

    /** Кэп тела ответа на release — крошечный JSON из 5 строковых полей; 1 МиБ с огромным запасом. */
    const MAX_RELEASE_BYTES = 1048576; // 1 MiB

    /**
     * Кэп тела файла набора — ndjson.gz легитимно КРУПНЫЕ (шардированный каталог). 64 МиБ = потолок
     * модуля (TarSafeExtractor::MAX_DECOMPRESSED_BYTES): сжатый шард на порядок меньше, запас велик,
     * но верхняя граница памяти против враждебного/сломанного core есть. Целостность файла всё равно
     * гарантирует sha256 в SnapshotDownloader — этот кэп только про память тика.
     */
    const MAX_FILE_BYTES = 67108864; // 64 MiB

    /** Чанк потокового чтения тела (как ArtifactDownloader::CHUNK). */
    private const READ_CHUNK = 262144; // 256 KiB

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
        $url = $this->buildManifestUrl($baseUrl, $channelCode);
        $body = $this->fetchWithRetries($url, $token, self::MAX_MANIFEST_BYTES);
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

        $url = $this->buildFileUrl($baseUrl, $channelCode, $fileName);
        $body = $this->fetchWithRetries($url, $token, self::MAX_FILE_BYTES);
        if ($body === null) {
            throw new ManifestException('Не удалось скачать файл снапшота: ' . $fileName);
        }

        if (file_put_contents($destPath, $body) === false) {
            throw new ManifestException('Не удалось записать файл снапшота на диск: ' . $fileName);
        }
    }

    /**
     * Спросить у ядра актуальный релиз движка сателлита (stage-rollout-core §C):
     *   GET {core_url}/api/satellite/{channel}/release?token=…
     * Форма ответа: {engine, module, version, url, sha256}. Любой отказ ядра (неразличимая 404 —
     * релиз не опубликован / движок не распознан) → null (обновлять нечем, не ошибка). Токен НЕ логируется.
     *
     * @return array{engine:string, module:string, version:string, url:string, sha256:string}|null
     */
    public function fetchRelease(string $baseUrl, string $channelCode, string $token): ?array
    {
        $url = rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/release';
        $body = $this->fetchWithRetries($url, $token, self::MAX_RELEASE_BYTES);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['engine', 'module', 'version', 'url', 'sha256'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return null;
            }
        }

        return [
            'engine'  => $data['engine'],
            'module'  => $data['module'],
            'version' => $data['version'],
            'url'     => $data['url'],
            'sha256'  => $data['sha256'],
        ];
    }

    private function buildManifestUrl(string $baseUrl, string $channelCode): string
    {
        return rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/manifest.json';
    }

    /** Файлы набора живут под сегментом /files/ (пин SAT-B §1). */
    private function buildFileUrl(string $baseUrl, string $channelCode, string $fileName): string
    {
        return rtrim($baseUrl, '/')
            . '/api/satellite/' . rawurlencode($channelCode)
            . '/files/' . ltrim($fileName, '/');
    }

    /**
     * Скачать тело с ретраями, читая ПОТОКОМ чанками под кэпом $maxBytes (bounded-чтение по паттерну
     * ArtifactDownloader — враждебный/сломанный core не выест память тика). Статус-код извлекается из
     * заголовков ответа; 4xx — fast-fail без ретрая; превышение кэпа — провал попытки без раздутия
     * (ретрай не поможет сломанному ответу). Токен клеится локально и НЕ логируется.
     *
     * @return string|null тело ответа или null (исчерпание попыток / 4xx / превышение кэпа)
     */
    private function fetchWithRetries(string $url, string $token, int $maxBytes): ?string
    {
        $withToken = $url . (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);

        $attempt = 0;
        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $opened = $this->openStream($withToken);
            $stream = $opened['stream'];
            if (!is_resource($stream)) {
                continue; // сеть недоступна — попытка не удалась, ретрай
            }

            $status = $this->statusFromHeaders($opened['headers']);

            // 4xx (например 404 по токену/каналу) не лечится ретраем — выходим сразу.
            if ($status !== null && $status >= 400 && $status < 500) {
                fclose($stream);
                if ($this->logger !== null) {
                    $this->logger->warning('CoreSync: HTTP ' . $status . ' при запросе ' . $url);
                }

                return null;
            }

            // 2xx (или неизвестный статус — как в прежнем поведении) → читаем тело под кэпом.
            if ($status === null || ($status >= 200 && $status < 300)) {
                $body = $this->readCapped($stream, $maxBytes);
                fclose($stream);

                if ($body === null) {
                    // Превышение кэпа: враждебный/сломанный ответ, ретрай не поможет. URL без токена.
                    if ($this->logger !== null) {
                        $this->logger->warning(
                            'CoreSync: ответ превысил лимит ' . $maxBytes . ' байт при запросе ' . $url
                        );
                    }

                    return null;
                }

                return $body;
            }

            // 5xx / прочее — попытка не удалась, ретрай.
            fclose($stream);
        }

        if ($this->logger !== null) {
            $this->logger->warning('CoreSync: исчерпаны попытки скачивания ' . $url);
        }

        return null;
    }

    /**
     * Прочитать поток чанками с бегущим счётчиком; обрыв ДО накопления чанка при превышении $maxBytes
     * (память ограничена $maxBytes + один чанк). Паттерн ArtifactDownloader::download.
     *
     * @param resource $stream
     * @return string|null тело; null при превышении кэпа
     */
    private function readCapped($stream, int $maxBytes): ?string
    {
        $body = '';
        $read = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK);
            if ($chunk === false) {
                break; // ошибка чтения — возвращаем накопленное (проверка целостности — выше по стеку)
            }
            $read += strlen($chunk);
            if ($read > $maxBytes) {
                return null; // превышение — обрыв ДО добавления чанка в буфер
            }
            $body .= $chunk;
        }

        return $body;
    }

    /**
     * Сетевой шов: открыть GET-поток к $url и вернуть его вместе с ответными заголовками. Вынесен
     * protected для тестируемости — тест подменяет фикстурой (реальный HTTP — SAT-RT round-trip).
     * Заголовки берутся из stream_get_meta_data()['wrapper_data'] (http-обёртка PHP).
     *
     * @return array{stream: resource|false, headers: array<int, string>}
     */
    protected function openStream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 30,
                'ignore_errors' => true,
                'header'        => "Accept: application/json, application/octet-stream\r\n",
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            return ['stream' => false, 'headers' => []];
        }

        $meta = stream_get_meta_data($stream);
        $headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];

        return ['stream' => $stream, 'headers' => $headers];
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
