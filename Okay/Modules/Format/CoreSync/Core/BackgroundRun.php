<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Okay\Core\Settings;
use Psr\Log\LoggerInterface;

/**
 * Фоновый прогон обмена по пинку ядра (coresync-trigger-module) [SECURITY-SENSITIVE].
 *
 * В режиме `run_trigger = core` приёмник пинка не гоняет {@see SyncRunner::run()} в веб-запросе (там
 * большие обмены упираются в лимиты PHP-FPM/CGI), а запускает отдельный CLI-процесс
 * `bin/coresync coresync:run`, отвязанный от запроса: новая сессия через `setsid` (если есть, иначе
 * `nohup`), stdin из /dev/null, stdout/stderr в лог модуля с неугадываемым именем ({@see logPath()}),
 * унаследованные дескрипторы родителя (под FPM сокет FastCGI, соединение MySQL) закрыты до exec
 * ({@see DETACH_SCRIPT}).
 *
 * **Один процесс на окно бутстрапа.** Процесс берёт замок прогона только после загрузки Okay, а job
 * появляется ещё позже. Чтобы серия пинков в это окно не плодила процессы, spawn() под замком ставит
 * маркер запуска {@see CLAIM_RELATIVE_PATH}: пока он свежий ({@see CLAIM_TTL}), следующий spawn()
 * отвечает OUTCOME_BUSY (пинок ставит pending). Процесс снимает маркер, как только проверил замок
 * ({@see \Okay\Modules\Format\CoreSync\Console\RunCommand}); упавший процесс не блокирует
 * навсегда, маркер протухает по TTL.
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

    /**
     * Каталог лога фоновых процессов внутри корня сайта. Каталог отдаётся веб-сервером, а в логе тексты
     * исключений, поэтому: .htaccess с запретом (Apache) и имя файла со случайным суффиксом, который
     * хранится в настройках модуля ({@see SETTINGS_LOG_NAME_KEY}); под nginx .htaccess не действует.
     */
    const LOG_RELATIVE_DIR = 'files/coresync/logs';
    const LOG_FILE_PREFIX = 'background-run-';

    /** Ключ Settings со случайной частью имени лога (32 hex). Отдельный ключ: форма настроек его не затирает. */
    const SETTINGS_LOG_NAME_KEY = 'coresync_background_log_name';

    /** Маркер запуска: поставлен spawn(), снимается процессом, протухает через CLAIM_TTL секунд. */
    const CLAIM_RELATIVE_PATH = 'files/coresync/locks/spawn.claim';
    const CLAIM_TTL = 180;

    /** Ключ Settings с кэшем найденного PHP CLI (только успешная проба) и его срок жизни. */
    const SETTINGS_PROBE_CACHE_KEY = 'coresync_php_cli_probe';
    const PROBE_CACHE_TTL = 3600;

    /**
     * Сценарий отвязки, исполняется `bash -c` (или `sh -c`, если bash нет) с позиционными аргументами
     * `<php> <bin/coresync> coresync:run`. Константа: данных в тексте нет, аргументы приходят через "$@".
     * Закрывает все дескрипторы больше 2: bash по списку /proc/$$/fd (или 3..255 без /proc); dash
     * многозначные номера в перенаправлениях не понимает, поэтому без bash закрываются 3..9. Затем
     * exec setsid (новая сессия) или nohup.
     */
    const DETACH_SCRIPT = 'if [ -n "${BASH_VERSION:-}" ]; then if [ -d /proc/$$/fd ]; then for f in /proc/$$/fd/*; do f=${f##*/}; '
        . 'case $f in ""|*[!0-9]*|0|1|2) ;; *) eval "exec $f>&-" ;; esac; done; else f=3; while [ $f -lt 256 ]; do '
        . 'eval "exec $f>&-"; f=$((f+1)); done; fi; else for f in 3 4 5 6 7 8 9; do eval "exec $f>&-"; done; fi; '
        . 'if command -v setsid >/dev/null 2>&1; then exec setsid "$@"; else exec nohup "$@"; fi';

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

    /** @var Settings|null null: нет кэша пробы и постоянного имени лога (тесты) */
    private $settings;

    /** @var string|null */
    private $logName;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var string */
    private $lastError = '';

    /** @var array<string, array{available:bool, reason:string, php_cli:string}> */
    private $capabilityCache = [];

    public function __construct(
        Config $config,
        LockHelper $lock,
        ?Settings $settings = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->lock = $lock;
        $this->settings = $settings;
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
     * Запустить фоновый прогон. Замок прогона проверяется до запуска: занят (идёт прогон, обновление)
     * -> OUTCOME_BUSY. Свободен, но стоит свежий маркер запуска (прошлый процесс ещё грузится) ->
     * OUTCOME_BUSY. Иначе под замком ставится маркер, замок отпускается и процесс запускается; дальше
     * замок берёт сам процесс в SyncRunner::run().
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
        try {
            if ($this->claimIsFresh()) {
                return self::OUTCOME_BUSY;
            }
            if (!$this->writeClaim()) {
                return $this->fail('не удалось поставить маркер запуска ' . $this->claimPath());
            }
        } finally {
            $this->lock->release();
        }

        if (!$this->prepareLog()) {
            $this->clearClaim();

            return $this->fail('не удалось подготовить лог фонового прогона ' . $this->logPath());
        }
        if (!$this->launch($this->command($capability['php_cli']))) {
            $this->clearClaim();

            return $this->fail('командная оболочка не запустила фоновый процесс');
        }

        return self::OUTCOME_SPAWNED;
    }

    /** Снять маркер запуска. Зовёт запущенный процесс, когда проверил замок, и spawn() на своём отказе. */
    public function clearClaim(): void
    {
        $path = $this->claimPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Свежий маркер: поставлен меньше CLAIM_TTL секунд назад. Протухший не блокирует. */
    public function claimIsFresh(): bool
    {
        $path = $this->claimPath();
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return false;
        }
        $mtime = @filemtime($path);

        return $mtime !== false && time() - $mtime < self::CLAIM_TTL;
    }

    private function writeClaim(): bool
    {
        $path = $this->claimPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, (string) time(), LOCK_EX) !== false && @touch($path);
    }

    private function claimPath(): string
    {
        return $this->rootDir() . '/' . self::CLAIM_RELATIVE_PATH;
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
        $detach = escapeshellarg(self::DETACH_SCRIPT) . ' coresync ' . escapeshellarg($phpCli) . ' '
            . escapeshellarg($this->scriptPath()) . ' ' . escapeshellarg(self::COMMAND);

        return 'cd ' . escapeshellarg($this->rootDir())
            . ' && ( if command -v bash >/dev/null 2>&1; then exec bash -c ' . $detach
            . '; else exec sh -c ' . $detach . '; fi )'
            . ' >>' . escapeshellarg($this->logPath()) . ' 2>&1 </dev/null &';
    }

    /** Полный путь лога фоновых процессов (имя со случайным суффиксом из настроек). */
    public function logPath(): string
    {
        return $this->rootDir() . '/' . self::LOG_RELATIVE_DIR . '/' . $this->logFileName();
    }

    /** Путь лога от корня сайта: показывается оператору в админке. */
    public function logRelativePath(): string
    {
        return self::LOG_RELATIVE_DIR . '/' . $this->logFileName();
    }

    private function logFileName(): string
    {
        if ($this->logName === null) {
            $stored = $this->settings !== null ? $this->settings->get(self::SETTINGS_LOG_NAME_KEY) : null;
            if (!is_string($stored) || preg_match('/\A[a-f0-9]{32}\z/', $stored) !== 1) {
                $stored = bin2hex(random_bytes(16));
                if ($this->settings !== null) {
                    $this->settings->set(self::SETTINGS_LOG_NAME_KEY, $stored);
                }
            }
            $this->logName = self::LOG_FILE_PREFIX . $stored . '.log';
        }

        return $this->logName;
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

        if ($configured !== '' && !self::isSafePhpCliPath($configured)) {
            return $this->unavailable('Путь к PHP CLI должен быть абсолютным путём без управляющих символов.');
        }
        $cached = $this->cachedCli($configured);
        if ($cached !== null) {
            return ['available' => true, 'reason' => '', 'php_cli' => $cached];
        }

        if ($configured !== '') {
            if (!$this->isCli($configured)) {
                return $this->unavailable(sprintf(
                    'Указанный путь к PHP CLI %s не отвечает на -v как PHP %s (cli).',
                    $configured,
                    $this->runtimeVersion()
                ));
            }

            return $this->found($configured, $configured);
        }

        $candidates = $this->autodetectCandidates();
        foreach ($candidates as $candidate) {
            if (self::isSafePhpCliPath($candidate) && $this->isCli($candidate)) {
                return $this->found($configured, $candidate);
            }
        }

        return $this->unavailable(sprintf(
            'PHP CLI %s не найден автоматически (проверены: %s). Укажите путь к PHP CLI в настройках модуля.',
            $this->runtimeVersion(),
            implode(', ', $candidates)
        ));
    }

    /**
     * Успешная проба кэшируется в Settings на PROBE_CACHE_TTL: пинки и загрузки админки не запускают
     * `-v` каждый раз. Ключ кэша: версия витрины и настроенный путь; смена пути или версии PHP витрины
     * даёт промах. Неуспешная проба не кэшируется, исправление оператора видно сразу.
     *
     * @return array{available:bool, reason:string, php_cli:string}
     */
    private function found(string $configured, string $phpCli): array
    {
        if ($this->settings !== null) {
            $this->settings->set(self::SETTINGS_PROBE_CACHE_KEY, [
                'runtime' => $this->runtimeVersion(),
                'configured' => $configured,
                'php_cli' => $phpCli,
                'checked_at' => time(),
            ]);
        }

        return ['available' => true, 'reason' => '', 'php_cli' => $phpCli];
    }

    private function cachedCli(string $configured): ?string
    {
        $cache = $this->settings !== null ? $this->settings->get(self::SETTINGS_PROBE_CACHE_KEY) : null;
        if (!is_array($cache)
            || ($cache['runtime'] ?? null) !== $this->runtimeVersion()
            || ($cache['configured'] ?? null) !== $configured
            || !is_string($cache['php_cli'] ?? null)
            || !self::isSafePhpCliPath($cache['php_cli'])
            || !is_int($cache['checked_at'] ?? null)
            || time() - $cache['checked_at'] >= self::PROBE_CACHE_TTL
            || $cache['checked_at'] > time()) {
            return null;
        }

        return $cache['php_cli'];
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

    /** Каталог лога с запретом для Apache и ротация по размеру. */
    protected function prepareLog(): bool
    {
        $path = $this->logPath();
        $dir = dirname($path);
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
            return false;
        }
        $deny = $dir . '/.htaccess';
        if (is_link($deny) || (file_exists($deny) && !is_file($deny))) {
            return false;
        }
        if (!file_exists($deny) && @file_put_contents($deny, "Deny from all\nRequire all denied\n", LOCK_EX) === false) {
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
