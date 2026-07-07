<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\Sha256MismatchException;
use Psr\Log\LoggerInterface;

/**
 * Движок скачивания набора: качает каждый files[]-файл в staging, сверяет sha256,
 * поддерживает resume (verified скипается, pending/failed докачивается) и кооперативную отмену
 * (проверка между файлами). Чистое ядро — без БД: чекпоинт-состояние через FileCheckpointStore,
 * отмена через callable. Применение данных — вне ответственности (M2).
 */
class SnapshotDownloader
{
    /** @var SnapshotHttpClient */
    private $http;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(SnapshotHttpClient $http, ?LoggerInterface $logger = null)
    {
        $this->http = $http;
        $this->logger = $logger;
    }

    /**
     * @param array<int, array<string, mixed>> $files    манифестные спеки (name/sha256/bytes/rows)
     * @param callable():bool                   $isCancelled кооперативная отмена (проверяется между файлами)
     * @return string Contract::STATUS_DOWNLOADED | Contract::STATUS_CANCELLED
     * @throws Sha256MismatchException набор не готов — sha256 не сошёлся после перекачек
     */
    public function download(
        string $baseUrl,
        string $channelCode,
        string $token,
        array $files,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled
    ): string {
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new Sha256MismatchException('Не удалось создать staging-директорию: ' . $stagingDir);
        }

        foreach ($files as $file) {
            if ($isCancelled()) {
                $this->log('info', 'CoreSync: отмена перед файлом, набор не завершён');

                return Contract::STATUS_CANCELLED;
            }

            $name = (string) ($file['name'] ?? '');
            $expected = strtolower((string) ($file['sha256'] ?? ''));
            if ($name === '' || $expected === '') {
                throw new Sha256MismatchException('Некорректная запись files[] в манифесте (нет name/sha256)');
            }

            // Resume: уже проверенный файл не перекачиваем.
            if ($checkpoints->getStatus($name) === Contract::FILE_VERIFIED) {
                continue;
            }

            $destPath = rtrim($stagingDir, '/') . '/' . $name;
            $this->downloadAndVerify($baseUrl, $channelCode, $token, $name, $expected, $destPath, $checkpoints);
        }

        return Contract::STATUS_DOWNLOADED;
    }

    /**
     * @throws Sha256MismatchException
     */
    private function downloadAndVerify(
        string $baseUrl,
        string $channelCode,
        string $token,
        string $name,
        string $expected,
        string $destPath,
        FileCheckpointStore $checkpoints
    ): void {
        $attempt = 0;
        while ($attempt < Contract::MAX_FILE_RETRIES) {
            $attempt++;
            try {
                $this->http->downloadFile($baseUrl, $channelCode, $token, $name, $destPath);
                $checkpoints->setStatus($name, Contract::FILE_DOWNLOADED);
            } catch (\Exception $e) {
                $this->log('warning', 'CoreSync: сбой скачивания ' . $name . ' (попытка ' . $attempt . '): ' . $e->getMessage());
                continue;
            }

            $actual = is_file($destPath) ? strtolower((string) hash_file('sha256', $destPath)) : '';
            if ($actual === $expected) {
                $checkpoints->setStatus($name, Contract::FILE_VERIFIED);

                return;
            }

            $this->log('warning', 'CoreSync: sha256 не совпал для ' . $name . ' (попытка ' . $attempt . ')');
        }

        $checkpoints->setStatus($name, Contract::FILE_FAILED);
        throw new Sha256MismatchException('sha256 не совпал для файла снапшота: ' . $name);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger === null) {
            return;
        }
        if ($level === 'warning') {
            $this->logger->warning($message);
        } else {
            $this->logger->info($message);
        }
    }
}
