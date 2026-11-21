<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Psr\Log\LoggerInterface;

/**
 * Фоновый прогон обмена по пинку ядра (coresync-trigger-module) [SECURITY-SENSITIVE].
 *
 * В режиме `run_trigger = core` приёмник пинка не гоняет {@see SyncRunner::run()} в веб-запросе (там
 * большие обмены упираются в лимиты PHP-FPM/CGI), а запускает отдельный CLI-процесс
 * `bin/coresync coresync:run`, отвязанный от запроса: новая сессия через `setsid` (если есть, иначе
 * `nohup`), stdin из /dev/null, stdout/stderr в {@see LOG_RELATIVE_PATH}.
 *
 * **Команда строится только из констант модуля и настроек.** Путь к PHP CLI берётся из настройки
 * (проверяется форматом и пробой `-v`) или из автоопределения; корень сайта из Config, скрипт из
 * каталога модуля, имя команды константой. Каждый аргумент проходит escapeshellarg. Тело пинка сюда
 * не попадает: у {@see spawn()} нет входа для данных запроса.
 *
 * Возможность запуска: доступна proc_open или exec (не в disable_functions) и найден PHP CLI той же
 * версии major.minor, что исполняет витрину (`PHP_BINARY` под FPM указывает на php-fpm, а CLI другой
 * версии может не поднять код витрины).
 */
class BackgroundRun
{
    const OUTCOME_SPAWNED = 'spawned';
    const OUTCOME_BUSY = 'busy';
    const OUTCOME_FAILED = 'failed';

    /** Консольная команда прогона в `bin/coresync`. */
    const COMMAND = 'coresync:run';

    /** Лог фоновых процессов внутри корня сайта, сосед каталога замков. */
    const LOG_RELATIVE_PATH = 'files/coresync/logs/background-run.log';

    /** Порог ротации лога: при превышении текущий файл переименовывается в `.1`. */
    const LOG_MAX_BYTES = 5242880;

    /** Максимальная длина пути к PHP CLI из настройки. */
    const PHP_CLI_PATH_MAX = 512;

    /** Сколько байт ответа `-v` читается (защита от программы, которая пишет без конца). */
    const PROBE_MAX_BYTES = 4096;

    /** @var Config */
    private $config;

    /** @var LockHelper */
    private $lock;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var string */
    private $lastError = '';

    /** @var array<string, array{available:bool, reason:string, php_cli:string}> */
    private $capabilityCache = [];

    public function __construct(Config $config, LockHelper $lock, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->lock = $lock;
        $this->logger = $logger;
    }

    /**
     * Может ли витрина запускать фоновый процесс прогона.
     *
     * @param array<string, mixed> $cfg значение Settings::get(Contract::SETTINGS_KEY)
     * @return array{available:bool, reason:string, php_cli:string} reason пуст, когда available
     */
    public function capability(array $cfg): array
    {
        $configured = trim((string) ($cfg[Contract::SETTINGS_PHP_CLI_FIELD] ?? ''));
        if (isset($this->capabilityCache[$configured])) {
            return $this->capabilityCache[$configured];
        }

        return $this->capabilityCache[$configured] = $this->detect($configured);
    }

    /**
     * Запустить фоновый прогон. Замок прогона проверяется до запуска: занят (идёт прогон, обновление
     * или уже запущенный процесс ещё работает) -> OUTCOME_BUSY и ничего не запускается. Свободен ->
     * отпускается и процесс запускается; дальше замок берёт сам процесс в SyncRunner::run().
     *
     * @param array<string, mixed> $cfg значение Settings::get(Contract::SETTINGS_KEY)
     * @return string один из OUTCOME_*; при OUTCOME_FAILED причина в {@see lastError()}
     */
    public function spawn(array $cfg): string
    {
        $this->lastError = '';
        $capability = $this->capability($cfg);
        if (!$capability['available']) {
            return $this->fail($capability['reason']);
        }

        if (!$this->lock->acquire()) {
            if ($this->lock->lastFailure() === LockHelper::FAILURE_BUSY) {
                return self::OUTCOME_BUSY;
            }

            return $this->fail('замок прогона недоступен, подробности в журнале витрины');
        }
        $this->lock->release();

        if (!$this->prepareLog()) {
            return $this->fail('не удалось подготовить лог фонового прогона ' . $this->logPath());
        }
        if (!$this->launch($this->command($capability['php_cli']))) {
            return $this->fail('командная оболочка не запустила фоновый процесс');
        }

        return self::OUTCOME_SPAWNED;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Командная строка фонового прогона. Публичная ради теста состава команды (Acceptance 5).
     */
    public function command(string $phpCli): string
    {
        $run = escapeshellarg($phpCli) . ' ' . escapeshellarg($this->scriptPath()) . ' ' . escapeshellarg(self::COMMAND);

        return 'cd ' . escapeshellarg($this->rootDir())
            . ' && ( if command -v setsid >/dev/null 2>&1; then exec setsid ' . $run
            . '; else exec nohup ' . $run . '; fi )'
            . ' >>' . escapeshellarg($this->logPath()) . ' 2>&1 </dev/null &';
    }

    /** Абсолютный путь без управляющих символов и разумной длины. */
    public static function isSafePhpCliPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::PHP_CLI_PATH_MAX
            && $path[0] === '/'
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    /** @return array{available:bool, reason:string, php_cli:string} */
    private function detect(string $configured): array
    {
        if (!$this->functionUsable('proc_open') && !$this->functionUsable('exec')) {
            return $this->unavailable(
                'PHP-функции proc_open и exec недоступны (disable_functions): витрина не может запустить фоновый процесс.'
            );
        }

        if ($configured !== '') {
            if (!self::isSafePhpCliPath($configured)) {
                return $this->unavailable('Путь к PHP CLI должен быть абсолютным путём без управляющих символов.');
            }
            if (!$this->isCli($configured)) {
                return $this->unavailable(sprintf(
                    'Указанный путь к PHP CLI %s не отвечает на -v как PHP %s (cli).',
                    $configured,
                    $this->runtimeVersion()
                ));
            }

            return ['available' => true, 'reason' => '', 'php_cli' => $configured];
        }

        $candidates = $this->autodetectCandidates();
        foreach ($candidates as $candidate) {
            if (self::isSafePhpCliPath($candidate) && $this->isCli($candidate)) {
                return ['available' => true, 'reason' => '', 'php_cli' => $candidate];
            }
        }

        return $this->unavailable(sprintf(
            'PHP CLI %s не найден автоматически (проверены: %s). Укажите путь к PHP CLI в настройках модуля.',
            $this->runtimeVersion(),
            implode(', ', $candidates)
        ));
    }

    /** @return array{available:bool, reason:string, php_cli:string} */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason, 'php_cli' => ''];
    }

    /** Ответ `<php> -v` начинается с `PHP X.Y.* (cli)` той же major.minor, что у витрины. */
    private function isCli(string $path): bool
    {
        $output = $this->capture(escapeshellarg($path) . ' -v');
        if ($output === null
            || preg_match('/^PHP (\d+)\.(\d+)\.\S* \(cli\)/', ltrim($output), $match) !== 1) {
            return false;
        }

        return $match[1] . '.' . $match[2] === $this->runtimeVersion();
    }

    private function runtimeVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    private function fail(string $reason): string
    {
        $this->lastError = $reason;
        if ($this->logger !== null) {
            $this->logger->error('CoreSync: фоновый прогон не запущен: ' . $reason);
        }

        return self::OUTCOME_FAILED;
    }

    private function logPath(): string
    {
        return $this->rootDir() . '/' . self::LOG_RELATIVE_PATH;
    }

    /** @return string[] кандидаты автоопределения PHP CLI для текущего интерпретатора */
    protected function autodetectCandidates(): array
    {
        return $this->candidatesFrom(PHP_SAPI, PHP_BINARY, PHP_BINDIR);
    }

    /** @return string[] */
    protected function candidatesFrom(string $sapi, string $binary, string $bindir): array
    {
        $mm = $this->runtimeVersion();
        $candidates = [];
        if ($sapi === 'cli' && $binary !== '') {
            $candidates[] = $binary;
        }
        if ($binary !== '' && basename(dirname($binary)) === 'sbin') {
            // FastPanel и сборки из исходников: /opt/php74/sbin/php-fpm -> /opt/php74/bin/php.
            $candidates[] = dirname($binary, 2) . '/bin/php';
        }
        $bindir = rtrim($bindir, '/');
        if ($bindir !== '') {
            $candidates[] = $bindir . '/php' . $mm;
            $candidates[] = $bindir . '/php';
        }
        foreach (['/usr/bin/php' . $mm, '/usr/local/bin/php' . $mm, '/usr/bin/php', '/usr/local/bin/php'] as $path) {
            $candidates[] = $path;
        }

        $result = [];
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $result, true) && strpos(basename($candidate), 'php-fpm') !== 0) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /** Функция есть и не перечислена в disable_functions (в PHP 7.4 отключённая всё ещё "существует"). */
    protected function functionUsable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return !in_array(strtolower($name), $disabled, true);
    }

    /**
     * Выполнить короткую пробу и вернуть вывод (stdout и stderr), не больше PROBE_MAX_BYTES.
     * null: оболочку запустить не удалось.
     */
    protected function capture(string $command): ?string
    {
        $command .= ' 2>&1 </dev/null';
        if ($this->functionUsable('proc_open')) {
            $process = @proc_open($command, [1 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                return null;
            }
            $output = (string) stream_get_contents($pipes[1], self::PROBE_MAX_BYTES);
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);

            return $output;
        }
        if ($this->functionUsable('exec')) {
            $lines = [];
            @exec($command . ' | head -c ' . self::PROBE_MAX_BYTES, $lines);

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Запустить команду, которая сама уходит в фон (`&`). Оболочка завершается сразу, фоновый процесс
     * не держит её вывод (перенаправлен в лог), поэтому вызов не ждёт прогона.
     */
    protected function launch(string $command): bool
    {
        if ($this->functionUsable('proc_open')) {
            $process = @proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                return false;
            }

            return proc_close($process) === 0;
        }
        if ($this->functionUsable('exec')) {
            $lines = [];
            $code = 1;
            @exec($command, $lines, $code);

            return $code === 0;
        }

        return false;
    }

    /** Каталог лога и ротация по размеру. */
    protected function prepareLog(): bool
    {
        $path = $this->logPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        clearstatcache(true, $path);
        if (is_file($path) && (int) @filesize($path) > self::LOG_MAX_BYTES) {
            @rename($path, $path . '.1');
        }

        return is_writable($dir);
    }

    protected function rootDir(): string
    {
        return rtrim((string) $this->config->get('root_dir'), '/\\');
    }

    protected function scriptPath(): string
    {
        return dirname(__DIR__) . '/bin/coresync';
    }
}
