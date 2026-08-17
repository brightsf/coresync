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
    /**
     * Content-validation failures (Scope B): the response body was read successfully but is not a
     * trustworthy image. Re-fetching the exact same URL will not fix a permanently broken object,
     * so these are terminal for the whole download() call — NOT retried like a transport failure.
     *
     * @var string[]
     */
    private const TERMINAL_ERROR_CODES = ['body_empty', 'header_mime', 'body_mime', 'size_mismatch'];

    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;
    /** @var string|null Safe machine code only (form reused from CategoryImageDownloader::lastErrorCode()). */
    private $lastErrorCode;

    public function __construct(Config $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function download(string $url): ?string
    {
        $this->lastErrorCode = null;
        $url = trim($url);
        if ($url === '') {
            $this->lastErrorCode = 'empty_url';

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
                    $this->lastErrorCode = null;

                    return $filename;
                }
                $this->lastErrorCode = 'write_failed';
                $this->warning('CoreSync image: не удалось записать файл ' . $localFile);

                return null;
            }
            if (in_array($this->lastErrorCode, self::TERMINAL_ERROR_CODES, true)) {
                // Invalid content is a property of the object at that URL, not of this attempt;
                // burning the network-retry budget on it would only delay a deterministic failure.
                break;
            }
            if ($attempt < Contract::IMAGE_DOWNLOAD_RETRIES) {
                usleep(200000 * $attempt); // backoff 0.2s, 0.4s
            }
        }

        return null;
    }

    /**
     * Safe machine code for the last download() failure, or null after a call that succeeded.
     * Form reused from CategoryImageDownloader::lastErrorCode() (this stage's error-code эталон).
     */
    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function deleteOwned(string $filename): bool
    {
        $basename = basename($filename);
        if ($filename === ''
            || $filename !== $basename
            || strpos($filename, '/') !== false
            || strpos($filename, '\\') !== false
            || strpos($filename, "\0") !== false
            || $filename === '.'
            || $filename === '..') {
            return false;
        }
        $rootDir = rtrim((string) $this->config->get('root_dir'), '/\\') . '/';
        $originalDir = (string) $this->config->get('original_images_dir');
        $path = $rootDir . ltrim($originalDir, '/') . $filename;

        return !is_file($path) || @unlink($path);
    }

    /**
     * @return string|null тело ответа, ПРОШЕДШЕЕ проверку заголовка И тела (validatedBody()); null
     *                     при сбое — конкретную причину смотри через lastErrorCode()
     */
    private function fetch(string $url): ?string
    {
        $raw = $this->transport($url);
        if ($raw === null) {
            $this->lastErrorCode = 'transport_failed';

            return null;
        }
        if ($raw['http_code'] !== 200) {
            $this->lastErrorCode = 'http_status';

            return null;
        }

        return $this->validatedBody($raw['body'], $raw['content_type'], $raw['declared_length'], $raw['compressed']);
    }

    /**
     * Единственная точка сетевого ввода-вывода — единственный шов, подменяемый в тестах (форма
     * CategoryImageDownloader::fetchHop()): реальная валидация (validatedBody()) тестами НЕ
     * подменяется и гоняется как есть.
     *
     * @return array{http_code:int,content_type:?string,declared_length:?int,compressed:bool,body:string}|null
     *         null → транспортный сбой (нет ответа вовсе: DNS/таймаут/connection refused) — ретраится
     */
    protected function transport(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        // Callback — ЧИСТЫЙ коллектор, без единого бита решения: копит сырые строки заголовков в
        // порядке получения от curl (включая промежуточные 3xx-хопы при FOLLOWLOCATION). Решение
        // «сжат ли финальный ответ» целиком живёт в compressionFromHeaderLines() — тестируемой
        // единице, которую можно прогнать без сети (REJECT-раунд 2 приёмки, xhigh: логика внутри
        // самого замыкания подменить нельзя, отсюда и невозможность её запереть тестом).
        $headerLines = [];
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curlHandle, string $headerLine) use (&$headerLines): int {
            $headerLines[] = $headerLine;

            return strlen($headerLine);
        });
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (!is_string($body)) {
            return null;
        }

        $contentType = isset($info['content_type']) && is_string($info['content_type']) && $info['content_type'] !== ''
            ? $info['content_type']
            : null;
        $declaredLength = isset($info['download_content_length']) && (float) $info['download_content_length'] >= 0
            ? (int) $info['download_content_length']
            : null;

        return [
            'http_code' => (int) ($info['http_code'] ?? 0),
            'content_type' => $contentType,
            'declared_length' => $declaredLength,
            'compressed' => self::compressionFromHeaderLines($headerLines),
            'body' => $body,
        ];
    }

    /**
     * Тестируемая единица: была ли Content-Encoding у ФИНАЛЬНОГО ответа (не у промежуточного
     * редиректного хопа), из сырой последовательности строк заголовков в порядке получения от curl.
     * Каждая новая статус-строка (`HTTP/…`) обнуляет накопленное значение — без этого сброса
     * Content-Encoding редиректного хопа протекал бы в решение по финальному ответу (REJECT-раунд 2:
     * приёмщик воспроизвёл эту протечку живьём после снятия сброса). Пустая строка не считается
     * кодом сжатия; `identity` (регистронезависимо) — явное «без сжатия» по RFC 9110 §8.4.1; любое
     * ДРУГОЕ значение, в том числе список через запятую («identity, gzip»), — сжатие есть.
     *
     * @param string[] $headerLines
     */
    public static function compressionFromHeaderLines(array $headerLines): bool
    {
        $contentEncoding = null;
        foreach ($headerLines as $headerLine) {
            if (stripos($headerLine, 'HTTP/') === 0) {
                $contentEncoding = null;

                continue;
            }
            if (stripos($headerLine, 'content-encoding:') === 0) {
                $contentEncoding = trim(substr($headerLine, strlen('content-encoding:')));
            }
        }

        return $contentEncoding !== null && $contentEncoding !== '' && strtolower($contentEncoding) !== 'identity';
    }

    /**
     * Контракт по смыслу повторяет GuardedMediaFetcher::validatedBody() (эталон, backend/app/Legacy/
     * Okay/Media/GuardedMediaFetcher.php): image/ обязателен И в заголовке, И по фактическому телу
     * (finfo), объявленная длина (если есть) обязана совпасть с фактической. Заголовка одного
     * недостаточно — ровно эта проверка ловит живой прод-дефект media_id=21680 (RECON §4): тело из
     * нулевых байт с валидным на вид Content-Type.
     *
     * Длина НЕ сверяется, когда ответ пришёл с Content-Encoding (CURLOPT_ENCODING='' заставляет curl
     * раздекодировать тело САМ до возврата сюда): Content-Length описывает байты НА ПРОВОДЕ (сжатые),
     * $body — уже распакованные. Это два разных числа про два разных представления, сравнивать их —
     * не ослабление проверки, а снятие сравнения, у которого нет смысла. Для не-сжатого ответа проверка
     * остаётся такой же жёсткой, как была.
     */
    private function validatedBody(string $body, ?string $headerContentType, ?int $declaredLength, bool $compressed): ?string
    {
        if ($body === '') {
            $this->lastErrorCode = 'body_empty';

            return null;
        }
        $headerMime = strtolower(trim(explode(';', (string) $headerContentType)[0]));
        if (strpos($headerMime, 'image/') !== 0) {
            $this->lastErrorCode = 'header_mime';

            return null;
        }
        if (!$compressed && $declaredLength !== null && $declaredLength !== strlen($body)) {
            $this->lastErrorCode = 'size_mismatch';

            return null;
        }
        $actualMime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body));
        if (strpos($actualMime, 'image/') !== 0) {
            $this->lastErrorCode = 'body_mime';

            return null;
        }

        return $body;
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
