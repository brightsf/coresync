<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Update\ModuleSwapper;
use Okay\Modules\Format\CoreSync\Core\Update\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * Атомарный swap каталога модуля [SECURITY-SENSITIVE] на реальном temp-дереве.
 * Проверяет: успешный swap + место бэкапа ВНЕ модульного скана + сброс скомпилированных шаблонов;
 * и инвариант отката — провал переноса ИЛИ само-проверки возвращает ИСХОДНУЮ версию (доказано).
 */
class ModuleSwapperTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_swap_' . uniqid('', true);
        $this->layoutLiveModule('1.2.0');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    /** Разложить живой модуль версии $v + пустые files/ и backend/design/compiled/ с coresync-артефактом. */
    private function layoutLiveModule(string $v): void
    {
        $mod = $this->root . '/Okay/Modules/Format/CoreSync';
        mkdir($mod . '/Init', 0755, true);
        mkdir($mod . '/Core', 0755, true);
        file_put_contents($mod . '/Init/module.json', json_encode(['version' => $v]));
        file_put_contents($mod . '/Core/Describer.php', "<?php // live {$v}\n");

        $compiled = $this->root . '/backend/design/compiled';
        mkdir($compiled, 0755, true);
        file_put_contents($compiled . '/abc123_0.coresync.tpl.php', '<?php /* stale */');
        file_put_contents($compiled . '/zzz_0.other.tpl.php', '<?php /* keep */');

        mkdir($this->root . '/files', 0755, true);
    }

    /** Разложить стейджинг новой версии $v, вернуть путь к CoreSync/. */
    private function stagingModule(string $v, bool $withDescriber = true): string
    {
        $stage = $this->root . '/files/coresync/updates/staging-' . uniqid('', true) . '/CoreSync';
        mkdir($stage . '/Init', 0755, true);
        mkdir($stage . '/Core', 0755, true);
        file_put_contents($stage . '/Init/module.json', json_encode(['version' => $v]));
        if ($withDescriber) {
            file_put_contents($stage . '/Core/Describer.php', "<?php // new {$v}\n");
        }

        return $stage;
    }

    private function configMock(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(function (string $key) {
            return $key === 'root_dir' ? $this->root . '/' : null;
        });

        return $config;
    }

    private function liveVersion(): ?string
    {
        $path = $this->root . '/Okay/Modules/Format/CoreSync/Init/module.json';
        if (!is_file($path)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($path), true);

        return is_array($d) ? ($d['version'] ?? null) : null;
    }

    public function testSuccessfulSwapReplacesVersionBacksUpOutsideModuleAndPurgesCompiled(): void
    {
        $staging = $this->stagingModule('1.3.0');
        (new ModuleSwapper($this->configMock()))->swap($staging, '1.3.0');

        $this->assertSame('1.3.0', $this->liveVersion(), 'живой модуль несёт целевую версию');

        // Бэкап живёт под files/coresync/updates — ВНЕ Okay/Modules/** (глоб Languages.php:269).
        $backups = glob($this->root . '/files/coresync/updates/rollback-*');
        $this->assertNotEmpty($backups, 'исходная версия отведена в бэкап');
        $this->assertStringNotContainsString('/Okay/Modules/', $backups[0], 'бэкап НЕ внутри модульного скана');
        $backupJson = json_decode((string) file_get_contents($backups[0] . '/Init/module.json'), true);
        $this->assertSame('1.2.0', $backupJson['version'], 'бэкап хранит прежнюю версию');

        // Скомпилированный coresync-шаблон сброшен; чужой — нетронут.
        $compiled = $this->root . '/backend/design/compiled';
        $this->assertFileDoesNotExist($compiled . '/abc123_0.coresync.tpl.php', 'stale coresync-компиляция сброшена');
        $this->assertFileExists($compiled . '/zzz_0.other.tpl.php', 'чужие шаблоны не тронуты');
    }

    public function testStagingVersionMismatchAbortsWithoutTouchingLive(): void
    {
        $staging = $this->stagingModule('9.9.9'); // не совпадает с ожидаемой 1.3.0

        $thrown = false;
        try {
            (new ModuleSwapper($this->configMock()))->swap($staging, '1.3.0');
        } catch (UpdateException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame('1.2.0', $this->liveVersion(), 'санити-провал стейджинга не трогает живой модуль');
        $this->assertEmpty(glob($this->root . '/files/coresync/updates/rollback-*'), 'бэкапа нет — swap не начинался');
    }

    public function testFailedStagingMoveRollsBackToOriginalVersion(): void
    {
        $staging = $this->stagingModule('1.3.0');

        // Форсируем провал ВТОРОГО переноса (staging→live); первый (live→backup) и третий (restore) — реальны.
        $swapper = new class($this->configMock()) extends ModuleSwapper {
            /** @var int */
            private $calls = 0;

            protected function move(string $from, string $to): bool
            {
                $this->calls++;
                if ($this->calls === 2) {
                    return false; // внесение новой версии «сорвалось»
                }

                return @rename($from, $to);
            }
        };

        $thrown = false;
        try {
            $swapper->swap($staging, '1.3.0');
        } catch (UpdateException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'провал переноса → исключение');
        $this->assertSame('1.2.0', $this->liveVersion(), 'откат вернул ИСХОДНУЮ версию (нет смеси версий)');
    }

    public function testFailedSelfCheckRollsBackToOriginalVersion(): void
    {
        $staging = $this->stagingModule('1.3.0');

        // Само-проверка после swap «падает» — обязателен откат к исходной.
        $swapper = new class($this->configMock()) extends ModuleSwapper {
            protected function selfCheck(string $expectedVersion): void
            {
                throw new UpdateException('искусственный провал само-проверки');
            }
        };

        $thrown = false;
        try {
            $swapper->swap($staging, '1.3.0');
        } catch (UpdateException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame('1.2.0', $this->liveVersion(), 'провал само-проверки → откат к исходной версии');
        // Неисправная новая версия отведена в failed-*, живой модуль — исходный.
        $this->assertNotEmpty(glob($this->root . '/files/coresync/updates/failed-*'), 'неисправная версия отведена');
    }

    /**
     * SEC-ревью (unchecked restore): ДВОЙНОЙ сбой — внесение новой версии сорвалось И восстановление
     * бэкапа сорвалось — обязан кричать: error-лог с «КРИТИЧНО» + исключение, называющее путь бэкапа.
     * Молчаливый двойной сбой оставил бы витрину без модуля вообще без следа в логах.
     */
    public function testDoubleFaultScreamsWithBackupPath(): void
    {
        $staging = $this->stagingModule('1.3.0');

        $errors = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function ($message) use (&$errors): void {
            $errors[] = (string) $message;
        });

        // Провал переносов 2 (staging→live) И 3 (restore backup→live); 1-й (live→backup) реален.
        $swapper = new class($this->configMock(), $logger) extends ModuleSwapper {
            /** @var int */
            private $calls = 0;

            protected function move(string $from, string $to): bool
            {
                $this->calls++;
                if ($this->calls >= 2) {
                    return false;
                }

                return @rename($from, $to);
            }
        };

        $thrown = null;
        try {
            $swapper->swap($staging, '1.3.0');
        } catch (UpdateException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'двойной сбой → исключение');
        $this->assertStringContainsString('rollback-', $thrown->getMessage(), 'исключение называет путь бэкапа для ручного восстановления');
        $this->assertNotEmpty($errors, 'двойной сбой пишет error-лог');
        $this->assertStringContainsString('КРИТИЧНО', implode(' ', $errors));
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
