<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\LockHelper;

/**
 * BackgroundRun без настоящих процессов: доступность функций, ответы `<php> -v` и запуск задаются
 * тестом, каждая командная строка записывается. Сборка команды и проверки остаются боевыми.
 */
class RecordingBackgroundRun extends BackgroundRun
{
    /** @var array<string, bool> имя функции => доступна; отсутствующее имя = доступна */
    public $functions = [];

    /** @var array<string, string> путь PHP => вывод `-v`; отсутствующий путь = нет вывода */
    public $versions = [];

    /** @var string[] команды проб `-v` */
    public $probes = [];

    /** @var string[] команды запуска */
    public $launched = [];

    /** @var bool исход запуска */
    public $launchResult = true;

    /** @var string[]|null кандидаты автоопределения; null = боевые */
    public $candidates;

    /** @var string корень сайта; по умолчанию свежий временный каталог (там живёт маркер запуска) */
    public $root;

    public function __construct(Config $config, LockHelper $lock, ?Settings $settings = null)
    {
        parent::__construct($config, $lock, $settings);
        $this->root = sys_get_temp_dir() . '/coresync_rbr_' . str_replace('.', '', uniqid('', true));
        mkdir($this->root, 0775, true);
    }

    /** Боевая подготовка лога (каталог, .htaccess, ротация), в обычных тестах заглушена. */
    public function realPrepareLog(): bool
    {
        return parent::prepareLog();
    }

    protected function functionUsable(string $name): bool
    {
        return $this->functions[$name] ?? true;
    }

    protected function capture(string $command): ?string
    {
        $this->probes[] = $command;
        foreach ($this->versions as $path => $output) {
            if ($command === escapeshellarg($path) . ' -v') {
                return $output;
            }
        }

        return null;
    }

    protected function launch(string $command): bool
    {
        $this->launched[] = $command;

        return $this->launchResult;
    }

    protected function autodetectCandidates(): array
    {
        return $this->candidates ?? parent::autodetectCandidates();
    }

    protected function rootDir(): string
    {
        return $this->root;
    }

    protected function prepareLog(): bool
    {
        return true;
    }

    /** Ответ `php -v` настоящего CLI той же версии, что исполняет тест. */
    public static function cliBanner(?string $version = null): string
    {
        $version = $version ?? (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION);

        return 'PHP ' . $version . ' (cli) (built: Nov  1 2026 00:00:00) ( NTS )' . "\n"
            . 'Copyright (c) The PHP Group' . "\n" . 'Zend Engine v4.0.0';
    }
}
