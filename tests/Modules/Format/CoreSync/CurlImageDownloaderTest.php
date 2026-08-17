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

    /**
     * REJECT-round finding (independent reviewer, xhigh): CURLOPT_ENCODING='' makes curl decompress
     * the body itself before it reaches here, but download_content_length still reports the
     * COMPRESSED wire length — comparing it to the decompressed $body used to fail-close a genuinely
     * valid image (measured on both PHP 8.0.30 and the artaz.ru storefront's 7.4.33: declared=131 vs
     * actual=178 for a real PNG). A response carrying Content-Encoding is exempt from the length
     * check for exactly this reason — the two numbers describe different representations of the body,
     * not the same one, so comparing them was never a meaningful check to begin with.
     */
    public function testValidImageOverGzipContentEncodingIsAcceptedDespiteCompressedDeclaredLength(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        // declared_length deliberately smaller than the (decompressed) body — the compressed wire size.
        $downloader->responses[] = $this->response(200, 'image/png', intdiv(strlen($png), 2), $png, true);

        $filename = $downloader->download('https://cdn.example/gzip.png');

        $this->assertIsString($filename);
        $this->assertSame($png, file_get_contents($this->root . 'files/originals/' . $filename));
        $this->assertNull($downloader->lastErrorCode());
    }

    /**
     * Sibling of the gzip spec above: the same declared/actual mismatch, but WITHOUT Content-Encoding,
     * must still be rejected exactly as before. Without this spec, an implementation that stops
     * checking length altogether (instead of gating on `compressed`) would pass the gzip spec too.
     */
    public function testDeclaredLengthMismatchWithoutContentEncodingIsStillRejected(): void
    {
        $png = $this->png();
        $downloader = $this->downloader();
        $downloader->responses[] = $this->response(200, 'image/png', intdiv(strlen($png), 2), $png, false);

        $filename = $downloader->download('https://cdn.example/not-gzip.png');

        $this->assertNull($filename);
        $this->assertSame('size_mismatch', $downloader->lastErrorCode());
        $this->assertSame([], glob($this->root . 'files/originals/*') ?: []);
    }

    /**
     * REJECT-round 2 (independent reviewer, xhigh): the compression decision used to live entirely
     * inside the CURLOPT_HEADERFUNCTION closure — no test double could reach it (ScriptedCurlImageDownloader
     * replaces transport() wholesale and injects `compressed` itself), so a mutation of the real
     * parsing logic was invisible to every spec. CurlImageDownloader::compressionFromHeaderLines() is
     * the extracted, directly callable unit: these specs call the REAL static method on the REAL
     * class, no test double involved, so a mutation of its body is guaranteed visible here.
     *
     * @dataProvider compressionHeaderSequenceProvider
     * @param string[] $headerLines
     */
    public function testCompressionFromHeaderLines(array $headerLines, bool $expected, string $because): void
    {
        $this->assertSame($expected, CurlImageDownloader::compressionFromHeaderLines($headerLines), $because);
    }

    /** @return array<string, array{0: string[], 1: bool, 2: string}> */
    public function compressionHeaderSequenceProvider(): array
    {
        return [
            '1. redirect gzip then final plain — hop must not leak' => [
                [
                    "HTTP/1.1 302 Found\r\n",
                    "Location: https://cdn.example/final.png\r\n",
                    "Content-Encoding: gzip\r\n",
                    "\r\n",
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Length: 178\r\n",
                    "\r\n",
                ],
                false,
                'redirect hop Content-Encoding must not leak into the final decision',
            ],
            '2. redirect plain then final gzip' => [
                [
                    "HTTP/1.1 302 Found\r\n",
                    "Location: https://cdn.example/final.png\r\n",
                    "\r\n",
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Encoding: gzip\r\n",
                    "Content-Length: 131\r\n",
                    "\r\n",
                ],
                true,
                'final response Content-Encoding must be picked up',
            ],
            '3. uppercase IDENTITY is explicit no-compression' => [
                [
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Encoding: IDENTITY\r\n",
                    "Content-Length: 178\r\n",
                    "\r\n",
                ],
                false,
                'RFC 9110 §8.4.1 identity is a no-op coding, case-insensitive',
            ],
            '4. empty Content-Encoding value is not a compression claim' => [
                [
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Encoding: \r\n",
                    "Content-Length: 178\r\n",
                    "\r\n",
                ],
                false,
                'an empty header value carries no encoding claim',
            ],
            '5. comma-separated list containing identity is still compressed' => [
                [
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Encoding: identity, gzip\r\n",
                    "\r\n",
                ],
                true,
                'a multi-value list is compressed unless the value is EXACTLY identity alone',
            ],
            '6. no Content-Encoding header at all' => [
                [
                    "HTTP/1.1 200 OK\r\n",
                    "Content-Type: image/png\r\n",
                    "Content-Length: 178\r\n",
                    "\r\n",
                ],
                false,
                'absence of the header means no compression claim',
            ],
        ];
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

    /** @return array{http_code:int,content_type:?string,declared_length:?int,compressed:bool,body:string} */
    private function response(int $httpCode, ?string $contentType, ?int $declaredLength, string $body, bool $compressed = false): array
    {
        return [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'declared_length' => $declaredLength,
            'compressed' => $compressed,
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
    /** @var list<array{http_code:int,content_type:?string,declared_length:?int,compressed:bool,body:string}|null> */
    public $responses = [];
    /** @var list<string> */
    public $transportCalls = [];

    protected function transport(string $url): ?array
    {
        $this->transportCalls[] = $url;

        return array_shift($this->responses);
    }
}
