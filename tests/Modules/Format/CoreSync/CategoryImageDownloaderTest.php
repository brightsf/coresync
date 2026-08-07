<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Apply\CategoryImageDownloader;
use PHPUnit\Framework\TestCase;

class CategoryImageDownloaderTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/coresync_safe_category_' . uniqid('', true);
        mkdir($this->root . '/category-originals', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/category-originals/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root . '/category-originals');
        rmdir($this->root);
    }

    public function testExactVerifiedImageIsAtomicallyInstalledAndSameHashReused(): void
    {
        $bytes = $this->png();
        $downloader = $this->downloader();
        $downloader->hops[] = ['status' => 200, 'location' => null, 'body' => $bytes];

        $filename = $downloader->download($this->descriptor('https://cdn.example/image.png', $bytes), 'grundfos:77');

        $this->assertNotNull($filename);
        $this->assertStringStartsWith(CategoryImageDownloader::OWNED_PREFIX, $filename);
        $this->assertSame($bytes, file_get_contents($this->root . '/category-originals/' . $filename));
        $this->assertSame('93.184.216.34', $downloader->fetchCalls[0]['target']['ip'], 'validated DNS answer is pinned into transport');
        $this->assertSame([], glob($this->root . '/category-originals/.coresync_category_*') ?: []);
        $calls = $downloader->fetchCalls;

        // Deterministic verified target handles crash-after-rename without another egress.
        $this->assertSame($filename, $downloader->download($this->descriptor('https://cdn.example/image.png', $bytes), 'grundfos:77'));
        $this->assertSame($calls, $downloader->fetchCalls);
    }

    /** @dataProvider unsafeTargetProvider */
    public function testLegacyNumericScopedAndPrivateTargetsNeverReachTransport(string $url): void
    {
        $downloader = $this->downloader();

        $this->assertNull($downloader->download($this->descriptor($url, $this->png()), 'opaque'));
        $this->assertSame([], $downloader->fetchCalls);
        $this->assertSame('unsafe_target', $downloader->lastErrorCode());
    }

    /** @return array<string, array{string}> */
    public function unsafeTargetProvider(): array
    {
        return [
            'single decimal' => ['http://2130706433/image.png'],
            'octal mix' => ['http://0177.0.0.1/image.png'],
            'short IPv4' => ['http://127.1/image.png'],
            'hex mix' => ['http://0x7f.0.1/image.png'],
            'single hex' => ['http://0x7f000001/image.png'],
            'private literal' => ['http://10.0.0.1/image.png'],
            'scoped IPv6' => ['http://[2001:4860:4860::8888%25eth0]/image.png'],
        ];
    }

    public function testDnsWithAnyPrivateAnswerFailsClosedAndPinsNoConnection(): void
    {
        $downloader = $this->downloader();
        $downloader->dns['cdn.example'] = ['93.184.216.34', '127.0.0.1'];

        $this->assertNull($downloader->download($this->descriptor('https://cdn.example/image.png', $this->png()), 'opaque'));
        $this->assertSame([], $downloader->fetchCalls);
    }

    public function testEveryRedirectTargetIsRevalidatedBeforeNextConnection(): void
    {
        $downloader = $this->downloader();
        $downloader->hops[] = ['status' => 302, 'location' => 'http://169.254.169.254/latest', 'body' => ''];

        $this->assertNull($downloader->download($this->descriptor('https://cdn.example/image.png', $this->png()), 'opaque'));
        $this->assertCount(1, $downloader->fetchCalls, 'private redirect is rejected before its transport hop');
        $this->assertSame('unsafe_target', $downloader->lastErrorCode());
    }

    public function testSizeHashMimeAndDecodeAreAllFailClosed(): void
    {
        $png = $this->png();

        $tooLarge = $this->downloader();
        $tooLarge->hops[] = ['status' => 200, 'location' => null, 'body' => str_repeat('x', CategoryImageDownloader::MAX_BYTES + 1)];
        $this->assertNull($tooLarge->download($this->descriptor('https://cdn.example/a.png', $png), 'size'));

        $wrongHash = $this->downloader();
        $wrongHash->hops[] = ['status' => 200, 'location' => null, 'body' => $png];
        $descriptor = $this->descriptor('https://cdn.example/b.png', $png);
        $descriptor['sha256'] = str_repeat('0', 64);
        $this->assertNull($wrongHash->download($descriptor, 'hash'));

        $notImage = $this->downloader();
        $payload = 'plain text with a claimed image mime';
        $notImage->hops[] = ['status' => 200, 'location' => null, 'body' => $payload];
        $this->assertNull($notImage->download([
            'url' => 'https://cdn.example/c.jpg',
            'sha256' => hash('sha256', $payload),
            'mime' => 'image/jpeg',
            'bytes' => strlen($payload),
        ], 'mime'));

        $invalidExpectedSize = $this->downloader();
        $this->assertNull($invalidExpectedSize->download([
            'url' => 'https://cdn.example/too-big.jpg',
            'sha256' => str_repeat('a', 64),
            'mime' => 'image/jpeg',
            'bytes' => CategoryImageDownloader::MAX_BYTES + 1,
        ], 'expected-size'));
        $this->assertSame([], $invalidExpectedSize->fetchCalls, 'oversized expectation is rejected before egress');
    }

    public function testUnavailableRealDecoderFailsClosedAndPreservesOldImage(): void
    {
        $bytes = $this->png();
        $old = $this->oldOwnedImage();
        $downloader = $this->downloader();
        $downloader->decoderAvailableOverride = false;
        $downloader->decodeResultOverride = true;
        $downloader->hops[] = ['status' => 200, 'location' => null, 'body' => $bytes];

        $this->assertNull($downloader->download(
            $this->descriptor('https://cdn.example/no-decoder.png', $bytes),
            'decoder-missing'
        ));
        $this->assertSame('old-image', file_get_contents($old));
        $this->assertSame(0, $downloader->decodeCalls, 'decode is never attempted when its runtime is unavailable');
        $this->assertSame('content_mismatch', $downloader->lastErrorCode());
    }

    public function testHeaderOnlyRasterFailsRealDecodeBeforeRenameAndPreservesOldImage(): void
    {
        $bytes = substr($this->png(), 0, 33); // valid PNG signature+IHDR; no IDAT/IEND pixels
        $old = $this->oldOwnedImage();
        $downloader = $this->downloader();
        $downloader->hops[] = ['status' => 200, 'location' => null, 'body' => $bytes];

        $this->assertNull($downloader->download(
            $this->descriptor('https://cdn.example/truncated.png', $bytes),
            'truncated'
        ));
        $this->assertSame(1, $downloader->decodeCalls, 'header probes cannot replace a real pixel decode');
        $this->assertSame('old-image', file_get_contents($old));
        $this->assertCount(1, glob($this->root . '/category-originals/*') ?: [], 'no unverified target is renamed');
    }

    /** @dataProvider oversizedRasterHeaders */
    public function testDimensionsAndPixelBudgetRejectCompressedBombHeaderBeforeDecode(int $width, int $height): void
    {
        $bytes = $this->pngHeader($width, $height);
        $downloader = $this->downloader();
        $downloader->decoderAvailableOverride = true;
        $downloader->decodeResultOverride = true;
        $downloader->hops[] = ['status' => 200, 'location' => null, 'body' => $bytes];

        $this->assertSame(8192, CategoryImageDownloader::MAX_DIMENSION);
        $this->assertSame(16000000, CategoryImageDownloader::MAX_PIXELS);
        $this->assertNull($downloader->download(
            $this->descriptor('https://cdn.example/bomb.png', $bytes),
            'dimensions'
        ));
        $this->assertSame(0, $downloader->decodeCalls, 'dimension budget is checked before any allocating decoder');
        $this->assertSame([], glob($this->root . '/category-originals/*') ?: []);
    }

    /** @return array<string, array{int,int}> */
    public function oversizedRasterHeaders(): array
    {
        return [
            'dimension cap independent of pixels' => [8193, 1],
            'pixel cap within per-dimension limit' => [8000, 3000],
        ];
    }

    public function testDeletionRequiresAnExactModuleOwnedBasename(): void
    {
        $bytes = $this->png();
        $downloader = $this->downloader();
        $downloader->hops[] = ['status' => 200, 'location' => null, 'body' => $bytes];
        $filename = $downloader->download($this->descriptor('https://cdn.example/image.png', $bytes), 'delete');
        $sentinel = $this->root . '/sentinel';
        file_put_contents($sentinel, 'keep');

        $this->assertFalse($downloader->deleteOwned('../sentinel'));
        $this->assertFileExists($sentinel);
        $this->assertTrue($downloader->deleteOwned((string) $filename));
        $this->assertFileDoesNotExist($this->root . '/category-originals/' . $filename);

        unlink($sentinel);
    }

    private function downloader(): ScriptedCategoryImageDownloader
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(function (string $key) {
            if ($key === 'root_dir') {
                return $this->root;
            }
            if ($key === 'original_categories_dir') {
                return 'category-originals/';
            }

            return null;
        });
        $downloader = new ScriptedCategoryImageDownloader($config);
        $downloader->dns['cdn.example'] = ['93.184.216.34'];

        return $downloader;
    }

    /** @return array{url:string,sha256:string,mime:string,bytes:int} */
    private function descriptor(string $url, string $bytes): array
    {
        return [
            'url' => $url,
            'sha256' => hash('sha256', $bytes),
            'mime' => 'image/png',
            'bytes' => strlen($bytes),
        ];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC');
    }

    private function pngHeader(int $width, int $height): string
    {
        $data = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = 'IHDR' . $data;

        return "\x89PNG\r\n\x1a\n" . pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
    }

    private function oldOwnedImage(): string
    {
        $path = $this->root . '/category-originals/coresync_category_aaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbb.png';
        file_put_contents($path, 'old-image');

        return $path;
    }
}

class ScriptedCategoryImageDownloader extends CategoryImageDownloader
{
    /** @var array<string, string[]> */
    public $dns = [];
    /** @var list<array{status:int,location:?string,body:string}> */
    public $hops = [];
    /** @var list<array{url:string,target:array<string,mixed>}> */
    public $fetchCalls = [];
    /** @var bool|null */
    public $decoderAvailableOverride;
    /** @var bool|null */
    public $decodeResultOverride;
    /** @var int */
    public $decodeCalls = 0;

    protected function resolveHost(string $host): array
    {
        return $this->dns[$host] ?? [];
    }

    protected function fetchHop(string $url, array $target, $stream): array
    {
        $this->fetchCalls[] = ['url' => $url, 'target' => $target];
        $hop = array_shift($this->hops);
        if (!is_array($hop)) {
            return ['status' => 0, 'location' => null];
        }
        fwrite($stream, $hop['body']);

        return ['status' => $hop['status'], 'location' => $hop['location']];
    }

    protected function hasRealImageDecoder(): bool
    {
        return $this->decoderAvailableOverride !== null
            ? $this->decoderAvailableOverride
            : parent::hasRealImageDecoder();
    }

    protected function realDecode(string $path): bool
    {
        $this->decodeCalls++;
        if ($this->decodeResultOverride !== null) {
            return $this->decodeResultOverride;
        }

        return parent::realDecode($path);
    }
}
