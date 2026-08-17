<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\Update\ArtifactDownloader;
use Okay\Modules\Format\CoreSync\Core\Update\ModuleSwapper;
use Okay\Modules\Format\CoreSync\Core\Update\ReleasePin;
use Okay\Modules\Format\CoreSync\Core\Update\TarSafeExtractor;
use Okay\Modules\Format\CoreSync\Core\Update\UpdateException;
use Okay\Modules\Format\CoreSync\Core\Update\Updater;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\TarFixtureBuilder;

require_once __DIR__ . '/../Support/TarFixtureBuilder.php';

/**
 * Оркестрация самообновления [SECURITY-SENSITIVE]: цепочка гейтов и их порядок отказа. Реальный
 * экстрактор на temp-дереве; http/downloader/swapper — фейки. Kill-пробы: sha256-mismatch и
 * непинованный url НЕ доходят до swap.
 */
class UpdaterTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var array<int, array{0:string,1:mixed}> */
    private $settingsSets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_upd_' . uniqid('', true);
        $mod = $this->root . '/Okay/Modules/Format/CoreSync/Init';
        mkdir($mod, 0755, true);
        file_put_contents($mod . '/module.json', json_encode(['version' => '1.2.0'])); // локальная версия
        mkdir($this->root . '/files', 0755, true);
        $this->settingsSets = [];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function pinnedUrl(string $tag = 'okay-v1.3.0'): string
    {
        return ReleasePin::RELEASE_PREFIX . $tag . '/' . $tag . '.tar.gz';
    }

    /** @param array{engine:string,module:string,version:string,url:string,sha256:string}|null $release */
    private function http(?array $release): SnapshotHttpClient
    {
        return new class($release) extends SnapshotHttpClient {
            /** @var array|null */
            private $release;

            public function __construct(?array $release)
            {
                parent::__construct(null);
                $this->release = $release;
            }

            public function fetchRelease(string $baseUrl, string $channelCode, string $token): ?array
            {
                return $this->release;
            }
        };
    }

    /** Даунлоадер, копирующий готовый тарбол в dest (или считающий, что до него не дошли). */
    private function downloader(?string $fixtureTarball): ArtifactDownloader
    {
        return new class($fixtureTarball) extends ArtifactDownloader {
            /** @var string|null */
            private $fixture;
            /** @var int */
            public $calls = 0;

            public function __construct(?string $fixture)
            {
                $this->fixture = $fixture;
            }

            public function download(string $url, string $destPath, int $maxBytes): void
            {
                $this->calls++;
                if (!ReleasePin::isPinned($url)) {
                    throw new UpdateException('pin');
                }
                $dir = dirname($destPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy((string) $this->fixture, $destPath);
            }
        };
    }

    private function swapper(): ModuleSwapper
    {
        $config = $this->configMock();

        return new class($config) extends ModuleSwapper {
            /** @var string|null */
            public $swappedVersion = null;
            /** @var int */
            public $calls = 0;

            public function swap(string $stagingModuleDir, string $expectedVersion): void
            {
                $this->calls++;
                $this->swappedVersion = $expectedVersion;
            }
        };
    }

    private function installingSwapper(): ModuleSwapper
    {
        $config = $this->configMock();

        return new class($config, $this->root) extends ModuleSwapper {
            /** @var string */
            private $root;

            public function __construct(Config $config, string $root)
            {
                parent::__construct($config);
                $this->root = $root;
            }

            public function swap(string $stagingModuleDir, string $expectedVersion): void
            {
                file_put_contents(
                    $this->root . '/Okay/Modules/Format/CoreSync/Init/module.json',
                    json_encode(['version' => $expectedVersion])
                );
            }
        };
    }

    private function failingSwapper(): ModuleSwapper
    {
        $config = $this->configMock();

        return new class($config) extends ModuleSwapper {
            public function swap(string $stagingModuleDir, string $expectedVersion): void
            {
                throw new UpdateException('swap failed and old live module stayed installed');
            }
        };
    }

    private function configMock(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(function (string $key) {
            return $key === 'root_dir' ? $this->root . '/' : null;
        });

        return $config;
    }

    private function settingsMock(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $sets = &$this->settingsSets;
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use (&$sets): void {
            $sets[] = [$key, $value];
        });

        return $settings;
    }

    private function updater(SnapshotHttpClient $http, ArtifactDownloader $dl, ModuleSwapper $swapper): Updater
    {
        return new Updater($http, $dl, new TarSafeExtractor(), $swapper, $this->settingsMock(), $this->configMock(), null);
    }

    /** @return array{0:string,1:string} [путь к тарболу, его sha256] */
    private function fixtureTarball(string $version): array
    {
        $path = $this->root . '/files/fixture-' . $version . '.tar.gz';
        TarFixtureBuilder::validModule($version)->writeGz($path);

        return [$path, (string) hash_file('sha256', $path)];
    }

    private function outcome(): ?array
    {
        foreach ($this->settingsSets as [$key, $value]) {
            if ($key === Updater::SETTINGS_UPDATE_STATUS_KEY) {
                return $value;
            }
        }

        return null;
    }

    // ── happy path ──────────────────────────────────────────────────────────

    public function testNewerReleaseRunsFullCycleAndSwapsToTargetVersion(): void
    {
        [$tar, $sha] = $this->fixtureTarball('1.3.0');
        $swapper = $this->swapper();

        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.3.0', 'url' => $this->pinnedUrl(), 'sha256' => $sha];
        $this->updater($this->http($release), $this->downloader($tar), $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame('1.3.0', $swapper->swappedVersion, 'swap вызван с целевой версией');
        $this->assertSame('updated', $this->outcome()['status'] ?? null);
        $this->assertSame('1.2.0', $this->outcome()['from'] ?? null);
        $this->assertSame('1.3.0', $this->outcome()['to'] ?? null);
    }

    public function testUpdatedResultIsReadFromLiveModuleMetadataAfterSwap(): void
    {
        [$tar, $sha] = $this->fixtureTarball('1.3.0');
        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.3.0', 'url' => $this->pinnedUrl(), 'sha256' => $sha];

        $installedVersion = $this->updater(
            $this->http($release),
            $this->downloader($tar),
            $this->installingSwapper()
        )->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame('1.3.0', $installedVersion);
    }

    // ── kill-пробы SEC ──────────────────────────────────────────────────────

    public function testSha256MismatchRefusesSwap(): void
    {
        [$tar] = $this->fixtureTarball('1.3.0');
        $swapper = $this->swapper();

        // Хеш из ответа ядра НЕ совпадает с файлом → swap НЕ должен произойти.
        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.3.0', 'url' => $this->pinnedUrl(), 'sha256' => str_repeat('0', 64)];
        $this->updater($this->http($release), $this->downloader($tar), $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame(0, $swapper->calls, 'sha256-mismatch → swap не вызывается');
        $this->assertSame('failed', $this->outcome()['status'] ?? null);
    }

    public function testUnpinnedUrlRefusedBeforeDownloadAndSwap(): void
    {
        $dl = $this->downloader(null);
        $swapper = $this->swapper();

        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.3.0', 'url' => 'https://evil.example/x.tar.gz', 'sha256' => str_repeat('a', 64)];
        $this->updater($this->http($release), $dl, $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame(0, $dl->calls, 'непинованный url не доходит до скачивания');
        $this->assertSame(0, $swapper->calls, 'и до swap');
        $this->assertSame('refused_pin', $this->outcome()['status'] ?? null);
    }

    // ── version-гейт / no-op ────────────────────────────────────────────────

    public function testEqualOrOlderVersionIsNoop(): void
    {
        $dl = $this->downloader(null);
        $swapper = $this->swapper();

        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.2.0', 'url' => $this->pinnedUrl('okay-v1.2.0'), 'sha256' => str_repeat('a', 64)];
        $this->updater($this->http($release), $dl, $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame(0, $dl->calls, 'версия == локальной → ничего не качаем');
        $this->assertSame(0, $swapper->calls);
        $this->assertNull($this->outcome(), 'no-op не пишет исход');
    }

    public function testNoopResultIsCurrentLiveModuleVersion(): void
    {
        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.2.0', 'url' => $this->pinnedUrl('okay-v1.2.0'), 'sha256' => str_repeat('a', 64)];

        $installedVersion = $this->updater(
            $this->http($release),
            $this->downloader(null),
            $this->swapper()
        )->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame('1.2.0', $installedVersion);
    }

    public function testFailedSwapReturnsVersionThatRemainsInstalled(): void
    {
        [$tar, $sha] = $this->fixtureTarball('1.3.0');
        $release = ['engine' => 'okay', 'module' => 'Format/CoreSync', 'version' => '1.3.0', 'url' => $this->pinnedUrl(), 'sha256' => $sha];

        $installedVersion = $this->updater(
            $this->http($release),
            $this->downloader($tar),
            $this->failingSwapper()
        )->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame('1.2.0', $installedVersion, 'desired 1.3.0 must not be reported after failed swap');
        $this->assertSame('failed', $this->outcome()['status'] ?? null);
    }

    public function testNoPublishedReleaseIsNoop(): void
    {
        $dl = $this->downloader(null);
        $swapper = $this->swapper();

        $this->updater($this->http(null), $dl, $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame(0, $dl->calls);
        $this->assertSame(0, $swapper->calls);
    }

    public function testForeignModuleReleaseIsIgnored(): void
    {
        $dl = $this->downloader(null);
        $swapper = $this->swapper();

        // Ядро отдало релиз для ДРУГОГО модуля (напр. будущий Simpla) — не наш, игнор.
        $release = ['engine' => 'simpla', 'module' => 'Simpla/CoreSync', 'version' => '9.9.9', 'url' => $this->pinnedUrl('simpla-v9.9.9'), 'sha256' => str_repeat('a', 64)];
        $this->updater($this->http($release), $dl, $swapper)
            ->checkAndUpdate('https://core.example', '42', 'tok');

        $this->assertSame(0, $dl->calls, 'чужой модуль не качаем');
        $this->assertSame(0, $swapper->calls);
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
