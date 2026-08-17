<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryAdoptionException;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryContentAdopter;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryFileProbe;
use Okay\Modules\Format\CoreSync\Core\Apply\LegacyGalleryAdopter;
use PHPUnit\Framework\TestCase;

/**
 * Единственный набор проверок файла галереи. Оба пути усыновления (подписанный план и автоматическое
 * усыновление по содержимому) обязаны ходить в ОДИН класс: второй набор проверок писать нельзя.
 */
class GalleryFileProbeTest extends TestCase
{
    /** @var list<string> */
    private $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function testBothAdoptionPathsShareOneFileCheckImplementation(): void
    {
        $plan = new \ReflectionClass(LegacyGalleryAdopter::class);
        $auto = new \ReflectionClass(GalleryContentAdopter::class);
        $planSource = (string) file_get_contents((string) $plan->getFileName());
        $autoSource = (string) file_get_contents((string) $auto->getFileName());

        foreach ([$planSource, $autoSource] as $source) {
            $this->assertStringContainsString(
                'fileProbe',
                $source,
                'оба пути усыновления обязаны переиспользовать общий набор проверок файла'
            );
            // Дубль проверок узнаётся по собственному потоковому хешированию файла в обход пробы.
            $this->assertStringNotContainsString('hash_update_stream', $source, 'второй набор проверок файла запрещён');
            $this->assertStringNotContainsString('lstat(', $source, 'второй набор проверок файла запрещён');
        }
        $this->assertStringContainsString('hash_update_stream', (string) file_get_contents(
            (string) (new \ReflectionClass(GalleryFileProbe::class))->getFileName()
        ), 'проверки живут именно в общем классе');
    }

    public function testMeasureWithoutExpectedSizeReturnsRealSizeAndContentHash(): void
    {
        $root = $this->root();
        $bytes = 'client-bytes-of-a-real-file';
        file_put_contents($root . '/image.jpg', $bytes);
        $this->paths[] = $root . '/image.jpg';

        $measured = $this->probe($root)->measure($root, 'image.jpg');

        $this->assertSame(strlen($bytes), $measured['size']);
        $this->assertSame(hash('sha256', $bytes), $measured['sha256']);
    }

    public function testMeasureWithExpectedSizeKeepsPlanPathRefusalBeforeReading(): void
    {
        $root = $this->root();
        file_put_contents($root . '/image.jpg', 'twelve-bytes');
        $this->paths[] = $root . '/image.jpg';

        $this->expectException(GalleryAdoptionException::class);
        $this->expectExceptionMessage('size drifted');
        $this->probe($root)->measure($root, 'image.jpg', 999);
    }

    public function testMeasureRefusesSymlinkRegardlessOfContent(): void
    {
        $root = $this->root();
        file_put_contents($root . '/target.jpg', 'bytes');
        $this->paths[] = $root . '/target.jpg';
        symlink($root . '/target.jpg', $root . '/link.jpg');
        $this->paths[] = $root . '/link.jpg';

        $this->expectException(GalleryAdoptionException::class);
        $this->expectExceptionMessage('file type is unsafe');
        $this->probe($root)->measure($root, 'link.jpg');
    }

    public function testMeasureRefusesPathEscapeAndDirectory(): void
    {
        $root = $this->root();
        $nested = $root . '/nested';
        mkdir($nested, 0700);
        $this->paths[] = $nested;

        try {
            $this->probe($root)->measure($root, '../etc/passwd');
            $this->fail('имя файла обязано быть только basename');
        } catch (GalleryAdoptionException $e) {
            $this->assertStringContainsString('filename is unsafe', $e->getMessage());
        }

        $this->expectException(GalleryAdoptionException::class);
        $this->expectExceptionMessage('file type is unsafe');
        $this->probe($root)->measure($root, 'nested');
    }

    public function testUnavailableRootIsRefusedByPlanPathAndSkippedByAutomaticPath(): void
    {
        $missing = sys_get_temp_dir() . '/coresync-probe-missing-' . bin2hex(random_bytes(4));
        $probe = $this->probe($missing);

        $this->assertNull(
            (new GalleryContentAdopter($probe))->root(),
            'автоматический путь при небезопасном корне НЕ усыновляет, а отдаёт null (fail-closed)'
        );

        $this->expectException(GalleryAdoptionException::class);
        $this->expectExceptionMessage('originals root is unsafe or unavailable');
        $probe->root();
    }

    public function testAutomaticPathSkipsUnusableFileInsteadOfFailingTheRun(): void
    {
        $root = $this->root();
        file_put_contents($root . '/good.jpg', 'good-bytes');
        $this->paths[] = $root . '/good.jpg';
        $adopter = new GalleryContentAdopter($this->probe($root));

        $matched = $adopter->match($root, [
            ['key' => 'missing', 'sha256' => hash('sha256', 'gone-bytes')],
            ['key' => 'good', 'sha256' => hash('sha256', 'good-bytes')],
        ], [
            ['image_id' => 5, 'filename' => 'absent.jpg', 'position' => 0],
            ['image_id' => 6, 'filename' => 'good.jpg', 'position' => 1],
        ]);

        $this->assertSame(['good' => ['image_id' => 6, 'filename' => 'good.jpg', 'sha256' => hash('sha256', 'good-bytes')]], $matched);
    }

    public function testOneCandidateIsAdoptedByAtMostOneWantedRow(): void
    {
        $root = $this->root();
        file_put_contents($root . '/twin.jpg', 'twin-bytes');
        $this->paths[] = $root . '/twin.jpg';
        $sha = hash('sha256', 'twin-bytes');

        $matched = (new GalleryContentAdopter($this->probe($root)))->match($root, [
            ['key' => 'first', 'sha256' => $sha],
            ['key' => 'second', 'sha256' => $sha],
        ], [
            ['image_id' => 9, 'filename' => 'twin.jpg', 'position' => 0],
        ]);

        $this->assertSame(['first'], array_keys($matched), 'один image_id — одна durable-строка');
        $this->assertSame(9, $matched['first']['image_id']);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/coresync-probe-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $this->paths[] = $root;

        return $root;
    }

    private function probe(string $root): GalleryFileProbe
    {
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            if ($key === 'root_dir') {
                return $root;
            }
            if ($key === 'original_images_dir') {
                return '/';
            }

            return null;
        });

        return new GalleryFileProbe($config);
    }
}
