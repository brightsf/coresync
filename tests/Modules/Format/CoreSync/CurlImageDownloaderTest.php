<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Apply\CurlImageDownloader;
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
}
