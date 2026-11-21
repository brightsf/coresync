<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use PHPUnit\Framework\TestCase;

/**
 * Настоящий фоновый запуск в контейнере PHP 7.4/8.0 (бриф: "процесс живёт после завершения родителя,
 * пишет лог, держит замок"; приёмка F1 и F4).
 *
 * Родитель (отдельный `php`, как веб-запрос пинка) держит открытыми посторонние дескрипторы (файл и
 * пару сокетов, как FastCGI и MySQL под FPM), вызывает боевой BackgroundRun::spawn() серией из 10
 * пинков с шагом 50 мс и завершается. Вместо `bin/coresync` запускается заглушка: она секунду
 * "грузится" (окно бутстрапа, замка ещё нет), затем берёт тот же замок {@see LockHelper}, что и
 * SyncRunner::run(), пишет в stdout свои дескрипторы и спит.
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
        $this->root = sys_get_temp_dir() . '/coresync_bg_' . str_replace('.', '', uniqid('', true));
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

    public function testSpawnSeriesStartsOneDetachedProcessWithoutInheritedDescriptors(): void
    {
        $bootstrap = dirname(__DIR__, 4) . '/tools/php74-suite/bootstrap.php';
        $script = $this->root . '/fake-coresync';
        file_put_contents($script, '<?php' . "\n"
            . '$fds = [];' . "\n"
            . 'foreach (scandir("/proc/self/fd") as $fd) { if (ctype_digit($fd) && (int) $fd > 2) { $fds[] = $fd . "=" . @readlink("/proc/self/fd/" . $fd); } }' . "\n"
            . 'usleep(1000000);' . "\n"
            . 'require ' . var_export($bootstrap, true) . ';' . "\n"
            . $this->configFactorySource()
            . '$lock = new Okay\Modules\Format\CoreSync\Core\LockHelper(coresyncTestConfig(getcwd()));' . "\n"
            . 'if (!$lock->acquire()) { echo "child-lock-refused\n"; exit(3); }' . "\n"
            . 'echo "child-started pid=" . getmypid() . " sid=" . posix_getsid(0) . " arg=" . ($argv[1] ?? "") . "\n";' . "\n"
            . 'echo "child-fds " . json_encode($fds) . "\n";' . "\n"
            . 'sleep(2);' . "\n"
            . '$lock->release();' . "\n"
            . 'echo "child-done\n";' . "\n");

        $parent = '<?php' . "\n"
            . 'require ' . var_export($bootstrap, true) . ';' . "\n"
            . $this->configFactorySource()
            . '$sentinel = fopen(' . var_export($this->root . '/sentinel-file', true) . ', "w");' . "\n"
            . '$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);' . "\n"
            . '$config = coresyncTestConfig(' . var_export($this->root, true) . ');' . "\n"
            . '$run = new class($config, new Okay\Modules\Format\CoreSync\Core\LockHelper($config)) extends Okay\Modules\Format\CoreSync\Core\BackgroundRun {' . "\n"
            . '    protected function scriptPath(): string { return ' . var_export($script, true) . '; }' . "\n"
            . '};' . "\n"
            . '$outcomes = [];' . "\n"
            . 'for ($i = 0; $i < 10; $i++) { $outcomes[] = $run->spawn(["php_cli_path" => PHP_BINARY]); usleep(50000); }' . "\n"
            . 'echo json_encode(["outcomes" => $outcomes, "pid" => getmypid(), "log" => $run->logPath(), "error" => $run->lastError()]);' . "\n";
        $parentFile = $this->root . '/parent.php';
        file_put_contents($parentFile, $parent);

        $started = microtime(true);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($parentFile) . ' 2>&1', $output, $exitCode);
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true);
        $this->assertIsArray($result, implode("\n", $output));
        $this->assertSame(
            array_merge([BackgroundRun::OUTCOME_SPAWNED], array_fill(0, 9, BackgroundRun::OUTCOME_BUSY)),
            $result['outcomes'],
            'серия пинков в окне бутстрапа: один запуск, остальные BUSY (' . $result['error'] . ')'
        );
        $this->assertLessThan(3.0, $elapsed, 'родитель не ждёт прогона');
        $this->assertSame(1, preg_match('#/files/coresync/logs/background-run-[a-f0-9]{32}\.log$#', $result['log']));
        $parentPid = (int) $result['pid'];

        $this->assertTrue($this->waitFor(function (): bool {
            return strpos($this->log(), 'child-fds') !== false;
        }, 6.0), 'stdout процесса пишется в лог модуля: ' . $this->log());
        $this->assertSame(1, preg_match('/child-started pid=(\d+) sid=(\d+) arg=(\S+)/', $this->log(), $m), $this->log());
        $childPid = (int) $m[1];
        $this->assertSame(BackgroundRun::COMMAND, $m[3]);
        $this->assertFalse(is_dir('/proc/' . $parentPid), 'родитель завершился');
        $this->assertTrue(is_dir('/proc/' . $childPid), 'фоновый процесс жив после выхода родителя');
        $this->assertSame($childPid, (int) $m[2], 'процесс отвязан от сессии родителя (setsid)');

        $this->assertSame(1, preg_match('/child-fds (\[.*\])/', $this->log(), $fdMatch));
        $inherited = json_decode($fdMatch[1], true);
        // scandir() сам держал дескриптор каталога /proc/self/fd и закрыл его до readlink(): такая
        // запись приходит с пустой целью. Любая другая запись > 2 (файл, сокет) унаследована.
        $foreign = array_values(array_filter($inherited, static function (string $entry): bool {
            return substr($entry, -1) !== '=';
        }));
        $this->assertSame([], $foreign, 'у процесса нет дескрипторов родителя > 2: ' . $fdMatch[1]);
        $this->assertStringNotContainsString('sentinel', $fdMatch[1]);
        $this->assertStringNotContainsString('socket:', $fdMatch[1]);

        $config = $this->config($this->root);
        $probe = new LockHelper($config);
        $this->assertFalse($probe->acquire(), 'фоновый процесс держит замок прогона');
        $this->assertSame(LockHelper::FAILURE_BUSY, $probe->lastFailure());

        $second = new BackgroundRun($config, new LockHelper($config));
        $this->assertSame(BackgroundRun::OUTCOME_BUSY, $second->spawn(['php_cli_path' => PHP_BINARY]),
            'пинок при живом процессе не запускает второй прогон');

        $this->assertTrue($this->waitFor(function (): bool {
            return strpos($this->log(), 'child-done') !== false;
        }, 10.0), $this->log());
        usleep(300000);
        $this->assertSame(1, substr_count($this->log(), 'child-started'), 'ровно один процесс прогона');
        $this->assertSame(0, substr_count($this->log(), 'child-lock-refused'), 'лишних процессов не было');
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
        $paths = glob($this->root . '/files/coresync/logs/background-run-*.log') ?: [];
        $content = '';
        foreach ($paths as $path) {
            $content .= (string) file_get_contents($path);
        }

        return $content;
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
        foreach (array_merge(scandir($dir) ?: []) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrmdir($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
