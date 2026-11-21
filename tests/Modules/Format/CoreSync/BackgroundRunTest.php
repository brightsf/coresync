<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Settings;
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
     * Путь с пробелом и кавычкой остаётся одним аргументом; сценарий отвязки константа, аргументы
     * приходят в него позиционно.
     */
    public function testCommandIsBuiltFromConstantsAndEscapedSettingsOnly(): void
    {
        $run = $this->launcher();
        $run->root = "/srv/my shop's";
        $php = "/opt/php 7.4/bin/php'x";

        $command = $run->command($php);

        $script = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/bin/coresync';
        $detach = escapeshellarg(BackgroundRun::DETACH_SCRIPT) . ' coresync ' . escapeshellarg($php) . ' '
            . escapeshellarg($script) . ' ' . escapeshellarg(BackgroundRun::COMMAND);
        $this->assertSame(
            'cd ' . escapeshellarg("/srv/my shop's") . ' && ( if command -v bash >/dev/null 2>&1; then exec bash -c '
            . $detach . '; else exec sh -c ' . $detach . '; fi ) >>' . escapeshellarg($run->logPath()) . ' 2>&1 </dev/null &',
            $command
        );
        $this->assertSame('coresync:run', BackgroundRun::COMMAND);
        $this->assertStringNotContainsString("'", BackgroundRun::DETACH_SCRIPT, 'сценарий без кавычек, в нём нет данных');
        $this->assertStringContainsString('exec setsid "$@"', BackgroundRun::DETACH_SCRIPT);
    }

    /**
     * F3: лог лежит в каталоге, который отдаётся веб-сервером. Имя со случайной частью из настроек
     * (одно на установку, переживает процессы), каталог закрыт .htaccess.
     * Kill-проба: вернуть постоянное имя лога -> красный.
     */
    public function testLogNameIsRandomPersistedAndDirectoryDeniesApache(): void
    {
        $store = [];
        $settings = $this->memorySettings($store);
        $first = new RecordingBackgroundRun($this->config(), $this->createMock(LockHelper::class), $settings);
        $path = $first->logPath();

        $this->assertSame(1, preg_match(
            '#^' . preg_quote($first->root, '#') . '/files/coresync/logs/background-run-([a-f0-9]{32})\.log$#',
            $path,
            $m
        ), $path);
        $this->assertSame($m[1], $store[BackgroundRun::SETTINGS_LOG_NAME_KEY] ?? null);
        $this->assertSame('files/coresync/logs/background-run-' . $m[1] . '.log', $first->logRelativePath());

        $second = new RecordingBackgroundRun($this->config(), $this->createMock(LockHelper::class), $settings);
        $this->assertSame($first->logRelativePath(), $second->logRelativePath(), 'имя постоянно для установки');

        $other = [];
        $third = new RecordingBackgroundRun($this->config(), $this->createMock(LockHelper::class), $this->memorySettings($other));
        $this->assertNotSame($first->logRelativePath(), $third->logRelativePath(), 'у другой установки другое имя');

        $broken = [BackgroundRun::SETTINGS_LOG_NAME_KEY => '../../index'];
        $fourth = new RecordingBackgroundRun($this->config(), $this->createMock(LockHelper::class), $this->memorySettings($broken));
        $this->assertSame(1, preg_match('#/background-run-[a-f0-9]{32}\.log$#', $fourth->logPath()), 'мусор в настройке заменяется');

        $this->assertTrue($first->realPrepareLog());
        $this->assertSame("Deny from all\nRequire all denied\n", (string) file_get_contents($first->root . '/files/coresync/logs/.htaccess'));
    }

    /**
     * Проба `php -v` кэшируется в настройках: повторная проверка (следующий пинок, загрузка админки)
     * не запускает процесс. Смена пути промахивается мимо кэша, неуспех не кэшируется, кэш протухает.
     * Kill-проба: не читать кэш -> красный.
     */
    public function testSuccessfulProbeIsCachedPerPathAndExpires(): void
    {
        $store = [];
        $first = $this->cachingLauncher($store);
        $this->assertTrue($first->capability(['php_cli_path' => self::PHP])['available']);
        $this->assertCount(1, $first->probes);

        $second = $this->cachingLauncher($store);
        $this->assertSame(
            ['available' => true, 'reason' => '', 'php_cli' => self::PHP],
            $second->capability(['php_cli_path' => self::PHP])
        );
        $this->assertSame([], $second->probes, 'кэш пробы из настроек');

        $third = $this->cachingLauncher($store);
        $third->capability(['php_cli_path' => '/usr/bin/php']);
        $this->assertCount(1, $third->probes, 'другой путь: новая проба');
        $this->assertSame(self::PHP, $store[BackgroundRun::SETTINGS_PROBE_CACHE_KEY]['php_cli'], 'неуспех не кэшируется');

        $store[BackgroundRun::SETTINGS_PROBE_CACHE_KEY]['checked_at'] = time() - BackgroundRun::PROBE_CACHE_TTL;
        $fourth = $this->cachingLauncher($store);
        $fourth->capability(['php_cli_path' => self::PHP]);
        $this->assertCount(1, $fourth->probes, 'протухший кэш: новая проба');

        $store[BackgroundRun::SETTINGS_PROBE_CACHE_KEY]['runtime'] = '5.6';
        $fifth = $this->cachingLauncher($store);
        $fifth->capability(['php_cli_path' => self::PHP]);
        $this->assertCount(1, $fifth->probes, 'кэш другой версии витрины не используется');
    }

    /**
     * F1: серия пинков в окне бутстрапа (процесс ещё не взял замок, job нет) запускает ровно один
     * процесс: остальные видят свежий маркер запуска и получают BUSY. Процесс снимает маркер,
     * протухший маркер не блокирует.
     * Kill-проба: убрать проверку маркера в spawn() -> красный.
     */
    public function testSpawnSeriesInBootstrapWindowLaunchesExactlyOneProcess(): void
    {
        $run = $this->launcher();
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = $run->spawn(['php_cli_path' => self::PHP]);
        }

        $this->assertSame(BackgroundRun::OUTCOME_SPAWNED, $outcomes[0]);
        $this->assertSame(array_fill(0, 9, BackgroundRun::OUTCOME_BUSY), array_slice($outcomes, 1));
        $this->assertCount(1, $run->launched, 'ровно один процесс');
        $this->assertTrue($run->claimIsFresh());

        $claim = $run->root . '/' . BackgroundRun::CLAIM_RELATIVE_PATH;
        touch($claim, time() - BackgroundRun::CLAIM_TTL);
        $this->assertFalse($run->claimIsFresh(), 'протухший маркер');
        $this->assertSame(BackgroundRun::OUTCOME_SPAWNED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertCount(2, $run->launched);

        $run->clearClaim();
        $this->assertFalse(is_file($claim));
        $this->assertSame(BackgroundRun::OUTCOME_SPAWNED, $run->spawn(['php_cli_path' => self::PHP]), 'маркер снят процессом');
        $this->assertCount(3, $run->launched);
    }

    /** Неудачный запуск снимает свой маркер: следующий пинок не ждёт TTL. */
    public function testFailedLaunchClearsItsClaim(): void
    {
        $run = $this->launcher();
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];
        $run->launchResult = false;
        $this->assertSame(BackgroundRun::OUTCOME_FAILED, $run->spawn(['php_cli_path' => self::PHP]));
        $this->assertFalse($run->claimIsFresh());

        $run->launchResult = true;
        $this->assertSame(BackgroundRun::OUTCOME_SPAWNED, $run->spawn(['php_cli_path' => self::PHP]));
    }

    /**
     * @param array<string, mixed> $store
     */
    private function cachingLauncher(array &$store): RecordingBackgroundRun
    {
        $run = new RecordingBackgroundRun($this->config(), $this->createMock(LockHelper::class), $this->memorySettings($store));
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        return $run;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function memorySettings(array &$store): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $settings->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): void {
            $store[$key] = $value;
        });

        return $settings;
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
