<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\Sha256MismatchException;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\FakeHttpClient;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;

// Нет PSR-4 автозагрузки для Tests\ (репо-паттерн APIImport) — подключаем стабы явно.
require_once __DIR__ . '/Support/FakeHttpClient.php';
require_once __DIR__ . '/Support/InMemoryCheckpointStore.php';

/**
 * Движок скачивания: sha256-верификация, resume/докачка, кооперативная отмена.
 * Реальный диск (staging temp) + реальный hash_file; HTTP подменён FakeHttpClient.
 */
class SnapshotDownloaderTest extends TestCase
{
    /** @var string */
    private $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingDir = sys_get_temp_dir() . '/coresync_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->stagingDir);
        parent::tearDown();
    }

    private function downloader(FakeHttpClient $http): SnapshotDownloader
    {
        return new SnapshotDownloader($http, null);
    }

    /**
     * @param array<string, string> $contents
     * @return array<int, array<string, mixed>> манифестные спеки с реальными sha256 содержимого
     */
    private function fileSpecs(array $contents): array
    {
        $specs = [];
        foreach ($contents as $name => $bytes) {
            $specs[] = [
                'name'   => $name,
                'sha256' => hash('sha256', $bytes),
                'bytes'  => strlen($bytes),
                'rows'   => 1,
            ];
        }

        return $specs;
    }

    private function neverCancel(): callable
    {
        return static function (): bool {
            return false;
        };
    }

    public function testDownloadsAndVerifiesAllFiles(): void
    {
        $contents = ['a.ndjson.gz' => 'AAA', 'b.ndjson.gz' => 'BBB'];
        $http = new FakeHttpClient($contents);
        $checkpoints = new InMemoryCheckpointStore();

        $status = $this->downloader($http)->download(
            'https://core.example',
            'site-a',
            'secret-token',
            $this->fileSpecs($contents),
            $this->stagingDir,
            $checkpoints,
            $this->neverCancel()
        );

        $this->assertSame(Contract::STATUS_DOWNLOADED, $status);
        $this->assertSame(['a.ndjson.gz', 'b.ndjson.gz'], $http->downloadedNames);
        $this->assertSame(Contract::FILE_VERIFIED, $checkpoints->getStatus('a.ndjson.gz'));
        $this->assertSame(Contract::FILE_VERIFIED, $checkpoints->getStatus('b.ndjson.gz'));
        $this->assertFileExists($this->stagingDir . '/a.ndjson.gz');
    }

    public function testSha256MismatchFailsAfterRetriesAndSetNotReady(): void
    {
        // Манифест ждёт sha256 от "GOOD", а сервер отдаёт "CORRUPT" — расхождение.
        $specs = $this->fileSpecs(['x.ndjson.gz' => 'GOOD']);
        $http = new FakeHttpClient(['x.ndjson.gz' => 'CORRUPT']);
        $checkpoints = new InMemoryCheckpointStore();

        $thrown = false;
        try {
            $this->downloader($http)->download(
                'https://core.example',
                'site-a',
                'secret-token',
                $specs,
                $this->stagingDir,
                $checkpoints,
                $this->neverCancel()
            );
        } catch (Sha256MismatchException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'ожидалось Sha256MismatchException');
        $this->assertSame(Contract::MAX_FILE_RETRIES, count($http->downloadedNames), 'файл должен быть перекачан ровно MAX_FILE_RETRIES раз');
        $this->assertSame(Contract::FILE_FAILED, $checkpoints->getStatus('x.ndjson.gz'), 'файл помечен failed — набор НЕ готов');
    }

    public function testCancellationBetweenFilesStopsDownload(): void
    {
        $contents = ['a.ndjson.gz' => 'AAA', 'b.ndjson.gz' => 'BBB', 'c.ndjson.gz' => 'CCC'];
        $http = new FakeHttpClient($contents);
        $checkpoints = new InMemoryCheckpointStore();

        // Отмена перед вторым файлом: false на 1-й проверке (перед a), true на 2-й (перед b).
        $calls = 0;
        $isCancelled = static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        };

        $status = $this->downloader($http)->download(
            'https://core.example',
            'site-a',
            'secret-token',
            $this->fileSpecs($contents),
            $this->stagingDir,
            $checkpoints,
            $isCancelled
        );

        $this->assertSame(Contract::STATUS_CANCELLED, $status);
        $this->assertSame(['a.ndjson.gz'], $http->downloadedNames, 'после отмены остальные файлы не качаются');
        $this->assertSame(Contract::FILE_VERIFIED, $checkpoints->getStatus('a.ndjson.gz'));
        $this->assertNull($checkpoints->getStatus('b.ndjson.gz'));
    }

    public function testResumeSkipsVerifiedAndDownloadsOnlyRemainder(): void
    {
        $contents = ['a.ndjson.gz' => 'AAA', 'b.ndjson.gz' => 'BBB'];
        $specs = $this->fileSpecs($contents);
        $checkpoints = new InMemoryCheckpointStore();

        // Фаза 1: отмена перед вторым файлом → a скачан/verified, b остаётся pending.
        $calls = 0;
        $cancelAfterFirst = static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        };
        $http1 = new FakeHttpClient($contents);
        $status1 = $this->downloader($http1)->download(
            'https://core.example',
            'site-a',
            'secret-token',
            $specs,
            $this->stagingDir,
            $checkpoints,
            $cancelAfterFirst
        );
        $this->assertSame(Contract::STATUS_CANCELLED, $status1);
        $this->assertSame(['a.ndjson.gz'], $http1->downloadedNames);

        // Фаза 2: повторный запуск той же версии, без отмены → качается ТОЛЬКО остаток (b).
        $http2 = new FakeHttpClient($contents);
        $status2 = $this->downloader($http2)->download(
            'https://core.example',
            'site-a',
            'secret-token',
            $specs,
            $this->stagingDir,
            $checkpoints,
            $this->neverCancel()
        );

        $this->assertSame(Contract::STATUS_DOWNLOADED, $status2);
        $this->assertSame(['b.ndjson.gz'], $http2->downloadedNames, 'resume: verified-файл не перекачивается');
        $this->assertSame(Contract::FILE_VERIFIED, $checkpoints->getStatus('b.ndjson.gz'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
