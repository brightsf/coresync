<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\TarSafeExtractor;
use Okay\Modules\Format\CoreSync\Core\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\TarFixtureBuilder;

require_once __DIR__ . '/../Support/TarFixtureBuilder.php';

/**
 * Безопасная распаковка релизного tar.gz [SECURITY-SENSITIVE], threat-model §4 (path traversal).
 * Реальный диск (temp) + реальный gzip; злонамеренные записи штампуются TarFixtureBuilder'ом.
 */
class TarSafeExtractorTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/coresync_tar_' . uniqid('', true);
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function extractor(): TarSafeExtractor
    {
        return new TarSafeExtractor();
    }

    public function testExtractsValidModuleToCoreSyncRoot(): void
    {
        $gz = TarFixtureBuilder::validModule('1.3.0')->writeGz($this->tmp . '/a.tar.gz');
        $dest = $this->tmp . '/out';

        $root = $this->extractor()->extract($gz, $dest);

        $this->assertSame($dest . '/CoreSync', $root);
        $this->assertFileExists($root . '/Init/module.json');
        $this->assertFileExists($root . '/Core/Describer.php');
        $json = json_decode((string) file_get_contents($root . '/Init/module.json'), true);
        $this->assertSame('1.3.0', $json['version']);
    }

    public function testRejectsPathTraversalEntry(): void
    {
        // Файл с ../ уводит запись ВЫШЕ staging — обязан быть отвергнут, ничего не записано.
        $gz = (new TarFixtureBuilder())
            ->addDir('CoreSync')
            ->addFile('CoreSync/../../evil.php', '<?php danger();')
            ->writeGz($this->tmp . '/evil.tar.gz');
        $dest = $this->tmp . '/out';

        $this->expectException(UpdateException::class);
        try {
            $this->extractor()->extract($gz, $dest);
        } finally {
            $this->assertFileDoesNotExist($this->tmp . '/evil.php');
            $this->assertFileDoesNotExist(dirname($this->tmp) . '/evil.php');
        }
    }

    public function testRejectsSymlinkEntry(): void
    {
        // typeflag '2' = симлинк: вектор ре-таргета swap'а на произвольный путь. Отказ.
        $gz = (new TarFixtureBuilder())
            ->addDir('CoreSync')
            ->addFile('CoreSync/evil-link', '/etc/passwd', '2')
            ->writeGz($this->tmp . '/link.tar.gz');

        $this->expectException(UpdateException::class);
        $this->extractor()->extract($gz, $this->tmp . '/out');
    }

    public function testRejectsEntryOutsideCoreSyncRoot(): void
    {
        // Корень не CoreSync/ — не наш контракт (чужой модуль/произвольная запись).
        $gz = (new TarFixtureBuilder())
            ->addFile('OtherModule/x.php', '<?php')
            ->writeGz($this->tmp . '/other.tar.gz');

        $this->expectException(UpdateException::class);
        $this->extractor()->extract($gz, $this->tmp . '/out');
    }

    public function testRejectsAbsolutePathEntry(): void
    {
        $gz = (new TarFixtureBuilder())
            ->addFile('/etc/cron.d/evil', 'x')
            ->writeGz($this->tmp . '/abs.tar.gz');

        $this->expectException(UpdateException::class);
        $this->extractor()->extract($gz, $this->tmp . '/out');
    }

    public function testRejectsMissingRoot(): void
    {
        // Пустой архив (только конец) → нет корня CoreSync/.
        $gz = (new TarFixtureBuilder())->writeGz($this->tmp . '/empty.tar.gz');

        $this->expectException(UpdateException::class);
        $this->extractor()->extract($gz, $this->tmp . '/out');
    }

    public function testRejectsNonGzipArtifact(): void
    {
        file_put_contents($this->tmp . '/plain.tar.gz', 'not gzip at all');

        $this->expectException(UpdateException::class);
        $this->extractor()->extract($this->tmp . '/plain.tar.gz', $this->tmp . '/out');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
