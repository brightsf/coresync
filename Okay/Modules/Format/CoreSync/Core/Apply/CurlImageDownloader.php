<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Psr\Log\LoggerInterface;

/**
 * Зеркалирование картинки curl'ом по образцу Okay\Core\Image::downloadImage
 * (CURLOPT_ENCODING="" авто-gzip, FOLLOWLOCATION) в original_images_dir, но БЕЗ session/resize-логики
 * ядра: eager-скачивание догоняющей фазы (спека §4 «зеркалирование, не хотлинк»). Ретраи с backoff.
 */
class CurlImageDownloader implements ImageDownloader
{
    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(Config $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function download(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $rootDir = rtrim((string) $this->config->get('root_dir'), '/\\') . '/';
        $originalDir = (string) $this->config->get('original_images_dir');
        $targetDir = $rootDir . ltrim($originalDir, '/');

        $filename = $this->uniqueFilename($targetDir, $url);
        $localFile = $targetDir . $filename;

        for ($attempt = 1; $attempt <= Contract::IMAGE_DOWNLOAD_RETRIES; $attempt++) {
            $body = $this->fetch($url);
            if ($body !== null) {
                if (@file_put_contents($localFile, $body) !== false) {
                    return $filename;
                }
                $this->warning('CoreSync image: не удалось записать файл ' . $localFile);

                return null;
            }
            if ($attempt < Contract::IMAGE_DOWNLOAD_RETRIES) {
                usleep(200000 * $attempt); // backoff 0.2s, 0.4s
            }
        }

        return null;
    }

    /**
     * @return string|null тело ответа при HTTP 200 и непустом размере; null при сбое
     */
    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (is_string($body) && (int) ($info['http_code'] ?? 0) === 200 && (int) ($info['size_download'] ?? 0) > 0) {
            return $body;
        }

        return null;
    }

    /**
     * Локальное имя из basename URL, уникализируем при коллизии (по образцу ядра).
     */
    private function uniqueFilename(string $targetDir, string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        $base = preg_replace('~[^\w.\-]+~u', '_', rawurldecode($base));
        if ($base === '' || $base === null || strpos($base, '.') === false) {
            $base = 'image_' . substr(md5($url), 0, 12) . '.jpg';
        }

        $name = $base;
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $i = 1;
        while (is_file($targetDir . $name)) {
            $name = $stem . '_' . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        }

        return $name;
    }

    private function warning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }
}
