<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use PHPUnit\Framework\TestCase;

/**
 * Настоящий фоновый запуск в контейнере PHP 7.4/8.0 (бриф: "процесс живёт после завершения родителя,
 * пишет лог, держит замок").
 *
 * Родитель (отдельный `php -r`, как веб-запрос пинка) вызывает боевой BackgroundRun::spawn() и сразу
 * завершается. Вместо `bin/coresync` запускается скрипт-заглушка, который берёт тот же замок
 * {@see LockHelper} (его же берёт SyncRunner::run()), пишет в stdout и спит. Проверяется: родитель не
 * ждёт прогона, процесс жив после выхода родителя и стал лидером своей сессии (setsid), stdout ушёл в
 * лог модуля, замок держится, второй spawn при живом процессе ничего не запускает.
 */
class BackgroundRunProcessTest extends TestCase
{
    /** @var string */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open') || !is_dir('/proc/self')) {
            $this->markTestSkipped('нужны proc_open и /proc (контейнер Linux)');
        }
        $this->root = sys_get_temp_dir() . '/coresync_bg_' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->waitFor(function (): bool {
                return strpos($this->log(), 'child-done') !== false;
            }, 10.0);
            $this->rrmdir($this->root);
        }
        parent::tearDown();
    }

    public function testSpawnedProcessOutlivesItsParentWritesTheLogAndHoldsTheLock(): void
    {
        $bootstrap = dirname(__DIR__, 4) . '/tools/php74-suite/bootstrap.php';
        $script = $this->root . '/fake-coresync';
        file_put_contents($script, '<?php' . "\n"
            . 'require ' . var_export($bootstrap, true) . ';' . "\n"
            . $this->configFactorySource()
            . '$lock = new Okay\Modules\Format\CoreSync\Core\LockHelper(coresyncTestConfig(getcwd()));' . "\n"
            . 'if (!$lock->acquire()) { echo "child-lock-refused\n"; exit(3); }' . "\n"
            . 'echo "child-started pid=" . getmypid() . " sid=" . posix_getsid(0) . " arg=" . ($argv[1] ?? "") . "\n";' . "\n"
            . 'sleep(3);' . "\n"
            . '$lock->release();' . "\n"
            . 'echo "child-done\n";' . "\n");

        $parent = '<?php' . "\n"
            . 'require ' . var_export($bootstrap, true) . ';' . "\n"
            . $this->configFactorySource()
            . '$config = coresyncTestConfig(' . var_export($this->root, true) . ');' . "\n"
            . '$run = new class($config, new Okay\Modules\Format\CoreSync\Core\LockHelper($config)) extends Okay\Modules\Format\CoreSync\Core\BackgroundRun {' . "\n"
            . '    protected function scriptPath(): string { return ' . var_export($script, true) . '; }' . "\n"
            . '};' . "\n"
            . '$outcome = $run->spawn(["php_cli_path" => PHP_BINARY]);' . "\n"
            . 'echo $outcome, " ", getmypid(), " ", $run->lastError();' . "\n";
        $parentFile = $this->root . '/parent.php';
        file_put_contents($parentFile, $parent);

        $started = microtime(true);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($parentFile) . ' 2>&1', $output, $exitCode);
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringStartsWith('spawned ', (string) ($output[0] ?? ''), implode("\n", $output));
        $this->assertLessThan(2.5, $elapsed, 'родитель не ждёт прогона');
        $parentPid = (int) explode(' ', (string) $output[0])[1];

        $this->assertTrue($this->waitFor(function (): bool {
            return strpos($this->log(), 'child-started') !== false;
        }, 5.0), 'stdout процесса пишется в лог модуля: ' . $this->log());
        $this->assertSame(1, preg_match('/child-started pid=(\d+) sid=(\d+) arg=(\S+)/', $this->log(), $m), $this->log());
        $childPid = (int) $m[1];
        $this->assertSame(BackgroundRun::COMMAND, $m[3]);
        $this->assertNotSame($parentPid, $childPid);
        $this->assertFalse(is_dir('/proc/' . $parentPid), 'родитель завершился');
        $this->assertTrue(is_dir('/proc/' . $childPid), 'фоновый процесс жив после выхода родителя');
        $this->assertSame($childPid, (int) $m[2], 'процесс отвязан от сессии родителя (setsid)');

        $config = $this->config($this->root);
        $probe = new LockHelper($config);
        $this->assertFalse($probe->acquire(), 'фоновый процесс держит замок прогона');
        $this->assertSame(LockHelper::FAILURE_BUSY, $probe->lastFailure());

        $second = new BackgroundRun($config, new LockHelper($config));
        $this->assertSame(BackgroundRun::OUTCOME_BUSY, $second->spawn(['php_cli_path' => PHP_BINARY]),
            'второй пинок при живом процессе не запускает второй прогон');

        $this->assertTrue($this->waitFor(function (): bool {
            return strpos($this->log(), 'child-done') !== false;
        }, 10.0), $this->log());
        $this->assertSame(1, substr_count($this->log(), 'child-started'), 'ровно один процесс прогона');
        $this->assertTrue($probe->acquire(), 'после завершения процесса замок свободен');
        $probe->release();
    }

    private function configFactorySource(): string
    {
        return 'function coresyncTestConfig(string $root) {' . "\n"
            . '    $config = (new ReflectionClass(Okay\Core\Config::class))->newInstanceWithoutConstructor();' . "\n"
            . '    $vars = new ReflectionProperty(Okay\Core\Config::class, "vars");' . "\n"
            . '    $vars->setAccessible(true);' . "\n"
            . '    $vars->setValue($config, ["root_dir" => $root . "/"]);' . "\n"
            . '    return $config;' . "\n"
            . '}' . "\n";
    }

    private function config(string $root): Config
    {
        $config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $vars = new \ReflectionProperty(Config::class, 'vars');
        $vars->setAccessible(true);
        $vars->setValue($config, ['root_dir' => $root . '/']);

        return $config;
    }

    private function log(): string
    {
        $path = $this->root . '/' . BackgroundRun::LOG_RELATIVE_PATH;
        clearstatcache(true, $path);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function waitFor(callable $condition, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return true;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        return (bool) $condition();
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrmdir($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
