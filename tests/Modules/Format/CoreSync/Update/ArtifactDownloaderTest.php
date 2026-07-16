<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\ArtifactDownloader;
use Okay\Modules\Format\CoreSync\Core\Update\ReleasePin;
use Okay\Modules\Format\CoreSync\Core\Update\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * Скачивание артефакта [SECURITY-SENSITIVE]: пин ДО сети, кап объёма, чистка частичного файла.
 * Сетевой шов openSource() подменён фикстурой (реальный HTTP — в round-trip, не в unit).
 */
class ArtifactDownloaderTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/coresync_dl_' . uniqid('', true);
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff((array) scandir($this->tmp), ['.', '..']) as $f) {
            @unlink($this->tmp . '/' . $f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function pinnedUrl(): string
    {
        return ReleasePin::RELEASE_PREFIX . 'okay-v1.2.0/okay-v1.2.0.tar.gz';
    }

    /** Даунлоадер с фикстурными байтами; считает вызовы openSource (для kill-пробы пина). */
    private function downloader(string $bytes): ArtifactDownloader
    {
        return new class($bytes) extends ArtifactDownloader {
            /** @var string */
            private $bytes;
            /** @var int */
            public $opened = 0;

            public function __construct(string $bytes)
            {
                $this->bytes = $bytes;
            }

            protected function openSource(string $url)
            {
                $this->opened++;
                $fh = fopen('php://temp', 'r+b');
                fwrite($fh, $this->bytes);
                rewind($fh);

                return $fh;
            }
        };
    }

    public function testDownloadsPinnedUrlToFile(): void
    {
        $dl = $this->downloader('ARTIFACT-BYTES');
        $dest = $this->tmp . '/a.tar.gz';

        $dl->download($this->pinnedUrl(), $dest, 1024);

        $this->assertFileExists($dest);
        $this->assertSame('ARTIFACT-BYTES', file_get_contents($dest));
    }

    public function testUnpinnedUrlRefusedBeforeAnyNetwork(): void
    {
        // KILL-проба пина: чужой url не доходит до openSource и ничего не пишет.
        $dl = $this->downloader('EVIL');
        $dest = $this->tmp . '/b.tar.gz';

        $thrown = false;
        try {
            $dl->download('https://evil.example/x.tar.gz', $dest, 1024);
        } catch (UpdateException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'чужой url обязан быть отвергнут');
        $this->assertSame(0, $dl->opened, 'openSource НЕ должен вызываться на непинованном url');
        $this->assertFileDoesNotExist($dest, 'частичный файл не создаётся');
    }

    public function testOversizeAbortsAndRemovesPartialFile(): void
    {
        $dl = $this->downloader(str_repeat('X', 5000));
        $dest = $this->tmp . '/c.tar.gz';

        $this->expectException(UpdateException::class);
        try {
            $dl->download($this->pinnedUrl(), $dest, 1024); // кап 1 КиБ < 5000
        } finally {
            $this->assertFileDoesNotExist($dest, 'превысивший кап частичный файл удалён');
        }
    }

    public function testEmptyResponseIsRejected(): void
    {
        $dl = $this->downloader('');
        $dest = $this->tmp . '/d.tar.gz';

        $this->expectException(UpdateException::class);
        try {
            $dl->download($this->pinnedUrl(), $dest, 1024);
        } finally {
            $this->assertFileDoesNotExist($dest);
        }
    }
}
