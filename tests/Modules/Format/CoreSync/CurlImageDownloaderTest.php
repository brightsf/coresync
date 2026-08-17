<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Apply\CurlImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;

class CurlImageDownloaderTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_image_cleanup_' . uniqid('', true) . '/';
        mkdir($this->root . 'files/originals/', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . 'files/originals/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->root . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->root . 'files/originals');
        @rmdir($this->root . 'files');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testDeleteOwnedIsConfinedToOriginalImagesDirectory(): void
    {
        $owned = $this->root . 'files/originals/fresh.jpg';
        $outside = $this->root . 'outside.jpg';
        file_put_contents($owned, 'owned');
        file_put_contents($outside, 'outside');
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnMap([
            ['root_dir', $this->root],
            ['original_images_dir', 'files/originals/'],
        ]);
        $downloader = new CurlImageDownloader($config);

        $this->assertFalse($downloader->deleteOwned('../fresh.jpg'));
        $this->assertFileExists($owned);
        $this->assertTrue($downloader->deleteOwned('fresh.jpg'));
        $this->assertFileDoesNotExist($owned);
        $this->assertFileExists($outside);
    }

    /**
     * Живой прод-дефект (media_id=21680, RECON §4): http 200, declared/actual size совпадают,
     * заголовок объявляет image/jpeg, но тело — сплошные нулевые байты. Заголовок один не спасает:
     * отказ обязан прийти именно от проверки ТЕЛА (fail-closed, Acceptance §1, primary signal).
     */
    public function testZeroBodyWithImageHeaderIsRejectedByBodyCheck(): void
    {
        $body = str_repeat("\0", 2048);
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/jpeg', strlen($body), $body);

        $filename = $downloader->download('https://cdn.example/broken.jpg');

        $this->assertNull($filename);
        $this->assertSame('body_mime', $downloader->lastErrorCode());
        $this->assertSame([], glob($this->root . 'files/originals/*') ?: [], 'rejected body must not reach disk');
        $this->assertCount(1, $downloader->transportCalls, 'content-invalid body is terminal, not retried as a network failure');
    }

    /**
     * Обратный канал (Acceptance §2): настоящая картинка, но заголовок Content-Type не image/*.
     * Отдельная спека — не совмещена с проверкой тела, отказ обязан прийти от проверки ЗАГОЛОВКА.
     */
    public function testRealImageWithNonImageHeaderIsRejectedByHeaderCheck(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'application/octet-stream', strlen($png), $png);

        $filename = $downloader->download('https://cdn.example/mislabeled.png');

        $this->assertNull($filename);
        $this->assertSame('header_mime', $downloader->lastErrorCode());
        $this->assertSame([], glob($this->root . 'files/originals/*') ?: []);
        $this->assertCount(1, $downloader->transportCalls, 'header-invalid response is terminal, not retried as a network failure');
    }

    /** Declared Content-Length present but not matching the actual body length (Scope B, 5th bullet). */
    public function testDeclaredLengthMismatchIsRejected(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/png', strlen($png) + 5, $png);

        $filename = $downloader->download('https://cdn.example/truncated.png');

        $this->assertNull($filename);
        $this->assertSame('size_mismatch', $downloader->lastErrorCode());
        $this->assertSame([], glob($this->root . 'files/originals/*') ?: []);
    }

    /** Empty body must be rejected outright, independent of any header claim. */
    public function testEmptyBodyIsRejected(): void
    {
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/jpeg', 0, '');

        $filename = $downloader->download('https://cdn.example/empty.jpg');

        $this->assertNull($filename);
        $this->assertSame('body_empty', $downloader->lastErrorCode());
    }

    /** Regression (Acceptance §3): a genuinely valid image is still downloaded and written as before. */
    public function testValidImageIsDownloadedAndWrittenLikeBefore(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/png', strlen($png), $png);

        $filename = $downloader->download('https://cdn.example/real.png');

        $this->assertIsString($filename);
        $this->assertSame($png, file_get_contents($this->root . 'files/originals/' . $filename));
        $this->assertNull($downloader->lastErrorCode());
        $this->assertCount(1, $downloader->transportCalls);
    }

    /**
     * Acceptance §4 (network side): a transport-level failure (no response at all — timeout, DNS,
     * connection refused) is NOT terminal and keeps retrying up to Contract::IMAGE_DOWNLOAD_RETRIES,
     * exactly like before this stage.
     */
    public function testTransportFailureRetriesUpToConfiguredAttemptsThenFails(): void
    {
        $downloader = $this->downloader();
        $downloader->responses[] = null;
        $downloader->responses[] = null;
        $downloader->responses[] = null;

        $filename = $downloader->download('https://cdn.example/unreachable.jpg');

        $this->assertNull($filename);
        $this->assertSame('transport_failed', $downloader->lastErrorCode());
        $this->assertCount(Contract::IMAGE_DOWNLOAD_RETRIES, $downloader->transportCalls);
        $this->assertSame(3, Contract::IMAGE_DOWNLOAD_RETRIES, 'pin the constant the assertion above relies on');
    }

    /** A transport failure that clears up on a later attempt still succeeds — retries are not wasted. */
    public function testTransportFailureSucceedsOnALaterAttempt(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        $downloader->responses[] = null;
        $downloader->responses[] = $this->response(200, 'image/png', strlen($png), $png);

        $filename = $downloader->download('https://cdn.example/flaky.png');

        $this->assertIsString($filename);
        $this->assertSame($png, file_get_contents($this->root . 'files/originals/' . $filename));
        $this->assertCount(2, $downloader->transportCalls);
    }

    /**
     * Acceptance §4 (content side): content-invalid responses on every attempt do not exhaust the
     * network-failure retry budget — the very first attempt is terminal.
     */
    public function testContentInvalidBodyIsTerminalOnFirstAttemptEvenIfRepeated(): void
    {
        $body = str_repeat("\0", 16);
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/jpeg', strlen($body), $body);
        $downloader->responses[] = $this->response(200, 'image/jpeg', strlen($body), $body);
        $downloader->responses[] = $this->response(200, 'image/jpeg', strlen($body), $body);

        $filename = $downloader->download('https://cdn.example/always-broken.jpg');

        $this->assertNull($filename);
        $this->assertCount(1, $downloader->transportCalls, 'content failure must not consume the network-retry budget');
    }

    private function downloader(): ScriptedCurlImageDownloader
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnMap([
            ['root_dir', $this->root],
            ['original_images_dir', 'files/originals/'],
        ]);

        return new ScriptedCurlImageDownloader($config);
    }

    /** @return array{http_code:int,content_type:?string,declared_length:?int,body:string} */
    private function response(int $httpCode, ?string $contentType, ?int $declaredLength, string $body): array
    {
        return [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'declared_length' => $declaredLength,
            'body' => $body,
        ];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC');
    }
}

/** Test double: replaces only the curl transport, real validation code (body/header/size) runs unmodified. */
final class ScriptedCurlImageDownloader extends CurlImageDownloader
{
    /** @var list<array{http_code:int,content_type:?string,declared_length:?int,body:string}|null> */
    public $responses = [];
    /** @var list<string> */
    public $transportCalls = [];

    protected function transport(string $url): ?array
    {
        $this->transportCalls[] = $url;

        return array_shift($this->responses);
    }
}
