<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Psr\Log\LoggerInterface;

/**
 * Самообновление модуля [SECURITY-SENSITIVE], пре-спека фаза 1 (доставка ядром).
 *
 * Инициирует САТЕЛЛИТ своим же тиком (SyncRunner) — входящий доступ к клиентским серверам не нужен.
 * Цепочка гейтов (порядок = порядок отказа, каждый раньше следующего):
 *   1) ядро отдаёт {engine,module,version,url,sha256} (нет релиза → no-op);
 *   2) релиз для НАШЕГО модуля (чужой → игнор);
 *   3) version-гейт «только вверх» (≤ локальной → no-op) ⇒ скомпрометированное ядро не откатит версию;
 *   4) пин канонического префикса url ДО скачивания (чужой хост → отказ, {@see ReleasePin});
 *   5) скачивание под капом объёма ({@see ArtifactDownloader});
 *   6) sha256 НА СКАЧАННОМ файле (TOCTOU: тот же файл, что распакуем и свапнем);
 *   7) безопасная распаковка ({@see TarSafeExtractor}) → атомарный swap с откатом ({@see ModuleSwapper}).
 *
 * Обновление — ФОН: любая ошибка ловится, громко логируется и фиксируется в durable-исход; наружу
 * (в тик sync/витрину) не пробрасывается. Откат при частичном swap гарантирует ModuleSwapper.
 */
class Updater
{
    /** Имя нашего модуля (движко-агностичный контракт describe: engine=okay). Чужой module → игнор. */
    public const MODULE_NAME = 'Format/CoreSync';

    /** Кап объёма скачиваемого артефакта (модуль — десятки КБ; 32 МиБ с большим запасом). */
    public const MAX_ARTIFACT_BYTES = 33554432;

    /** Durable-исход последнего обновления (виден оператору; форма — {status,from,to,at,error}). */
    public const SETTINGS_UPDATE_STATUS_KEY = 'coresync_update_status';

    /** @var SnapshotHttpClient */
    private $http;
    /** @var ArtifactDownloader */
    private $downloader;
    /** @var TarSafeExtractor */
    private $extractor;
    /** @var ModuleSwapper */
    private $swapper;
    /** @var Settings */
    private $settings;
    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        SnapshotHttpClient $http,
        ArtifactDownloader $downloader,
        TarSafeExtractor $extractor,
        ModuleSwapper $swapper,
        Settings $settings,
        Config $config,
        ?LoggerInterface $logger = null
    ) {
        $this->http = $http;
        $this->downloader = $downloader;
        $this->extractor = $extractor;
        $this->swapper = $swapper;
        $this->settings = $settings;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Проверить релиз и, если ядро предлагает НОВЕЕ локальной, выполнить цикл обновления.
     * Зовётся из тика SyncRunner под уже взятым lock и после пройденного стоп-крана.
     */
    public function checkAndUpdate(string $coreUrl, string $channel, string $token): void
    {
        try {
            $release = $this->http->fetchRelease($coreUrl, $channel, $token);
            if ($release === null) {
                $this->info('CoreSync update: релиз не опубликован — обновление не требуется');

                return;
            }

            if ($release['module'] !== self::MODULE_NAME) {
                $this->warning('CoreSync update: релиз для чужого модуля (' . $release['module'] . ') — игнор');

                return;
            }

            $local = $this->localVersion();
            if ($local === null) {
                $this->error('CoreSync update: не читается локальная версия модуля — обновление отменено');

                return;
            }

            // Version-гейт «только вверх»: равное/старое ядро не переустанавливаем (downgrade-защита).
            if (version_compare($release['version'], $local, '<=')) {
                $this->info('CoreSync update: версия ядра ' . $release['version'] . ' ≤ локальной ' . $local . ' — обновление не требуется');

                return;
            }

            if (!ReleasePin::isPinned($release['url'])) {
                // Пин ДО скачивания: внятный отказ раньше сетевого шага (downloader откажет и сам).
                $this->error('CoreSync update: url релиза вне канонического префикса — отказ ДО скачивания: ' . $release['url']);
                $this->recordOutcome('refused_pin', $local, $release['version'], 'url вне пина');

                return;
            }

            $this->runCycle($release, $local);
        } catch (\Throwable $e) {
            // Обновление — фон: не роняем тик sync/витрину.
            $this->error('CoreSync update: непойманная ошибка цикла обновления: ' . $e->getMessage());
        }
    }

    /**
     * @param array{engine:string, module:string, version:string, url:string, sha256:string} $release
     */
    private function runCycle(array $release, string $local): void
    {
        $work = $this->updatesRoot() . '/work-' . substr(uniqid('', true), -10);
        $tarball = $work . '/artifact.tar.gz';
        $stagingParent = $work . '/staging';

        try {
            $this->downloader->download($release['url'], $tarball, self::MAX_ARTIFACT_BYTES);

            // TOCTOU (threat-model §2): хешируем ИМЕННО тот файл, что сейчас распакуем и свапнем.
            $actual = hash_file('sha256', $tarball);
            if (!is_string($actual) || !hash_equals(strtolower($release['sha256']), strtolower($actual))) {
                throw new UpdateException('sha256 скачанного артефакта не совпал с ответом ядра — swap отменён');
            }

            $stagingModuleDir = $this->extractor->extract($tarball, $stagingParent);
            $this->swapper->swap($stagingModuleDir, $release['version']);

            $this->recordOutcome('updated', $local, $release['version'], null);
            $this->info('CoreSync update: обновлено с ' . $local . ' до ' . $release['version']);
        } catch (UpdateException $e) {
            $this->recordOutcome('failed', $local, $release['version'], $e->getMessage());
            $this->error('CoreSync update: цикл обновления провален (откат гарантирован при частичном swap): ' . $e->getMessage());
        } finally {
            $this->rrmdir($work);
        }
    }

    /** Локальная версия из живого Init/module.json (тот же источник, что Describer). */
    private function localVersion(): ?string
    {
        $path = $this->root() . '/Okay/Modules/Format/CoreSync/Init/module.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : null;
    }

    /**
     * Зафиксировать durable-исход обновления (виден оператору; питает будущую карту версий флота).
     * Один Settings::set — без многошаговых БД-мутаций (не усугубляем D-OKAY-DB-NO-TX).
     */
    private function recordOutcome(string $status, string $from, string $to, ?string $error): void
    {
        $this->settings->set(self::SETTINGS_UPDATE_STATUS_KEY, [
            'status' => $status,
            'from'   => $from,
            'to'     => $to,
            'at'     => date('Y-m-d H:i:s'),
            'error'  => $error,
        ]);
    }

    private function root(): string
    {
        return rtrim((string) $this->config->get('root_dir'), '/\\');
    }

    private function updatesRoot(): string
    {
        return $this->root() . '/files/coresync/updates';
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

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }

    private function warning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    private function error(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
