<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\RecordingBackgroundRun;

/**
 * Фоновый прогон по пинку ядра: проверка возможности (функции запуска процесса, PHP CLI той же версии)
 * и сборка команды только из настроек модуля и констант.
 */
class BackgroundRunTest extends TestCase
{
    const PHP = '/opt/php74/bin/php';

    public function testCapabilityWithConfiguredCliIsAvailable(): void
    {
        $run = $this->launcher();
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        $capability = $run->capability(['php_cli_path' => self::PHP]);

        $this->assertTrue($capability['available']);
        $this->assertSame(self::PHP, $capability['php_cli']);
        $this->assertSame('', $capability['reason']);
        $this->assertSame([escapeshellarg(self::PHP) . ' -v'], $run->probes);
    }

    /**
     * Acceptance 3: нет ни proc_open, ни exec (disable_functions) -> возможности нет, PHP не пробуется.
     * Kill-проба: убрать проверку функций в capability() -> красный.
     */
    public function testCapabilityRequiresProcOpenOrExec(): void
    {
        $run = $this->launcher();
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];
        $run->functions = ['proc_open' => false, 'exec' => false];

        $capability = $run->capability(['php_cli_path' => self::PHP]);

        $this->assertFalse($capability['available']);
        $this->assertStringContainsString('proc_open', $capability['reason']);
        $this->assertSame([], $run->probes);

        foreach ([['proc_open' => false], ['exec' => false]] as $functions) {
            $run = $this->launcher();
            $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];
            $run->functions = $functions;
            $this->assertTrue($run->capability(['php_cli_path' => self::PHP])['available'], 'достаточно одной из двух функций');
        }
    }

    /**
     * `PHP_BINARY` под FPM указывает на php-fpm: путь, который на `-v` не отвечает как CLI той же
     * версии, не принимается.
     */
    public function testConfiguredPathMustAnswerAsCliOfTheSameVersion(): void
    {
        $other = (PHP_MAJOR_VERSION + 1) . '.0.1';
        $cases = [
            'fpm'           => 'PHP ' . PHP_VERSION . ' (fpm-fcgi) (built: Nov  1 2026)',
            'другая версия' => RecordingBackgroundRun::cliBanner($other),
            'не php'        => 'bash: no such file',
            'пусто'         => null,
        ];
        foreach ($cases as $label => $banner) {
            $run = $this->launcher();
            if ($banner !== null) {
                $run->versions = [self::PHP => $banner];
            }

            $capability = $run->capability(['php_cli_path' => self::PHP]);

            $this->assertFalse($capability['available'], $label);
            $this->assertStringContainsString(self::PHP, $capability['reason'], $label);
            $this->assertSame([escapeshellarg(self::PHP) . ' -v'], $run->probes, $label . ': без автоподбора при явном пути');
        }
    }

    /** @dataProvider unsafePaths */
    public function testUnsafeConfiguredPathIsRejectedWithoutProbing(string $path): void
    {
        $run = $this->launcher();

        $capability = $run->capability(['php_cli_path' => $path]);

        $this->assertFalse($capability['available']);
        $this->assertSame([], $run->probes, 'небезопасный путь не уходит в шелл даже пробой');
        $this->assertFalse(BackgroundRun::isSafePhpCliPath($path));
    }

    /** @return array<string, array{0:string}> */
    public function unsafePaths(): array
    {
        return [
            'относительный' => ['php'],
            'перевод строки' => ["/usr/bin/php\n-r"],
            'нулевой байт'  => ["/usr/bin/php\0x"],
            'табуляция'     => ["/usr/b\tin/php"],
            'слишком длинный' => ['/' . str_repeat('a', 600)],
        ];
    }

    public function testAutodetectTakesTheFirstCandidateThatAnswersAsCli(): void
    {
        $run = $this->launcher();
        $run->candidates = ['/opt/php74/sbin/php-fpm', '/usr/local/bin/php', '/usr/bin/php'];
        $run->versions = [
            '/opt/php74/sbin/php-fpm' => 'PHP ' . PHP_VERSION . ' (fpm-fcgi)',
            '/usr/bin/php'            => RecordingBackgroundRun::cliBanner(),
        ];

        $capability = $run->capability([]);

        $this->assertTrue($capability['available']);
        $this->assertSame('/usr/bin/php', $capability['php_cli']);
    }

    public function testAutodetectWithoutCliNamesTheReason(): void
    {
        $run = $this->launcher();
        $run->candidates = ['/usr/bin/php'];

        $capability = $run->capability(['php_cli_path' => '  ']);

        $this->assertFalse($capability['available']);
        $this->assertStringContainsString('PHP CLI', $capability['reason']);
        $this->assertStringContainsString('/usr/bin/php', $capability['reason']);
    }

    /** Боевые кандидаты: CLI-процесс берёт себя, FPM выводит bin/php рядом с sbin/php-fpm. */
    public function testDefaultCandidatesStartFromTheCurrentInterpreterLayout(): void
    {
        $run = new class($this->config(), $this->createMock(LockHelper::class)) extends BackgroundRun {
            /** @return string[] */
            public function candidatesFor(string $sapi, string $binary, string $bindir): array
            {
                return $this->candidatesFrom($sapi, $binary, $bindir);
            }
        };
        $mm = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $this->assertSame('/usr/bin/php' . $mm, $run->candidatesFor('cli', '/usr/bin/php' . $mm, '/usr/bin')[0]);
        $fpm = $run->candidatesFor('fpm-fcgi', '/opt/php74/sbin/php-fpm', '/opt/php74/bin');
        $this->assertSame('/opt/php74/bin/php', $fpm[0]);
        $this->assertNotContains('/opt/php74/sbin/php-fpm', $fpm, 'php-fpm не кандидат в CLI');
        $this->assertSame(array_values(array_unique($fpm)), $fpm);
        $this->assertContains('/usr/bin/php', $fpm);
    }

    /**
     * Acceptance 5: команда состоит только из констант модуля и настроек, каждый аргумент экранирован.
     * Путь с пробелом и кавычкой остаётся одним аргументом.
     */
    public function testCommandIsBuiltFromConstantsAndEscapedSettingsOnly(): void
    {
        $run = $this->launcher();
        $run->root = "/srv/my shop's";
        $php = "/opt/php 7.4/bin/php'x";

        $command = $run->command($php);

        $script = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/bin/coresync';
        $log = "/srv/my shop's/" . BackgroundRun::LOG_RELATIVE_PATH;
        $runPart = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(BackgroundRun::COMMAND);
        $this->assertSame(
            'cd ' . escapeshellarg("/srv/my shop's") . ' && ( if command -v setsid >/dev/null 2>&1; then exec setsid '
            . $runPart . '; else exec nohup ' . $runPart . '; fi ) >>' . escapeshellarg($log) . ' 2>&1 </dev/null &',
            $command
        );
        $this->assertSame('coresync:run', BackgroundRun::COMMAND);
    }

    public function testSpawnChecksLockReleasesItAndLaunchesTheCommand(): void
    {
        $lock = $this->createMock(LockHelper::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $run = $this->launcher($lock);
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        $this->assertSame(BackgroundRun::OUTCOME_SPAWNED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertSame([$run->command(self::PHP)], $run->launched);
    }

    /**
     * Замок занят (прогон, обновление или уже запущенный процесс): второй процесс не запускается.
     * Kill-проба: убрать проверку замка в spawn() -> красный.
     */
    public function testSpawnWithBusyLockLaunchesNothing(): void
    {
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(false);
        $lock->method('lastFailure')->willReturn(LockHelper::FAILURE_BUSY);
        $lock->expects($this->never())->method('release');
        $run = $this->launcher($lock);
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        $this->assertSame(BackgroundRun::OUTCOME_BUSY, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertSame([], $run->launched);
    }

    public function testSpawnFailsLoudlyWithoutCapabilityUnavailableLockOrFailedLaunch(): void
    {
        $run = $this->launcher();
        $run->functions = ['proc_open' => false, 'exec' => false];
        $this->assertSame(BackgroundRun::OUTCOME_FAILED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertStringContainsString('proc_open', $run->lastError());
        $this->assertSame([], $run->launched);

        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(false);
        $lock->method('lastFailure')->willReturn(LockHelper::FAILURE_UNAVAILABLE);
        $run = $this->launcher($lock);
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];
        $this->assertSame(BackgroundRun::OUTCOME_FAILED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertStringContainsString('замок', $run->lastError());
        $this->assertSame([], $run->launched);

        $run = $this->launcher();
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];
        $run->launchResult = false;
        $this->assertSame(BackgroundRun::OUTCOME_FAILED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertNotSame('', $run->lastError());
    }

    private function launcher(?MockObject $lock = null): RecordingBackgroundRun
    {
        if ($lock === null) {
            $lock = $this->createMock(LockHelper::class);
            $lock->method('acquire')->willReturn(true);
        }

        return new RecordingBackgroundRun($this->config(), $lock);
    }

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $key) {
            return $key === 'root_dir' ? '/srv/shop/' : null;
        });

        return $config;
    }
}
