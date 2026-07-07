<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\Sha256MismatchException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\UnsupportedSchemaVersionException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Psr\Log\LoggerInterface;

/**
 * Единая точка прогона синхронизации (M1: манифест → валидация → version-гейт → скачивание+sha256).
 * Вызывается (а) Schedule-callback (крон, свежесть «часы»), (б) AJAX «Запустить сейчас».
 * Flock-lock поверх scheduler-overlap: второй запуск при живом lock → тихий пропуск.
 * Применение данных в БД витрины — M2/M3.
 */
class SyncRunner
{
    /** @var Settings */
    private $settings;
    /** @var SnapshotHttpClient */
    private $http;
    /** @var ManifestValidator */
    private $manifestValidator;
    /** @var SnapshotDownloader */
    private $downloader;
    /** @var ReportClient */
    private $reportClient;
    /** @var EntityFactory */
    private $entityFactory;
    /** @var LockHelper */
    private $lockHelper;
    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        Settings $settings,
        SnapshotHttpClient $http,
        ManifestValidator $manifestValidator,
        SnapshotDownloader $downloader,
        ReportClient $reportClient,
        EntityFactory $entityFactory,
        LockHelper $lockHelper,
        Config $config,
        ?LoggerInterface $logger = null
    ) {
        $this->settings = $settings;
        $this->http = $http;
        $this->manifestValidator = $manifestValidator;
        $this->downloader = $downloader;
        $this->reportClient = $reportClient;
        $this->entityFactory = $entityFactory;
        $this->lockHelper = $lockHelper;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Точка входа (крон/AJAX). Ошибки прогона не пробрасываются наружу (витрина не затрагивается) —
     * фиксируются в job'е и логах.
     */
    public function run(): void
    {
        if (!$this->lockHelper->acquire()) {
            $this->info('CoreSync: прогон уже идёт (lock), пропуск');

            return;
        }

        try {
            $this->doRun();
        } catch (\Throwable $e) {
            $this->error('CoreSync: непойманная ошибка прогона: ' . $e->getMessage());
        } finally {
            $this->lockHelper->release();
        }
    }

    private function doRun(): void
    {
        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $this->entityFactory->get(CoreSyncJobsEntity::class);

        $cfg = $this->readConfig();
        if ($cfg === null) {
            $jobsEntity->add([
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_MANIFEST,
                'error_message' => 'Не заданы адрес ядра / канал / токен',
                'started_at'    => $this->now(),
                'finished_at'   => $this->now(),
            ]);
            $this->error('CoreSync: прогон невозможен — неполные настройки (адрес/канал/токен)');

            return;
        }

        // --- Манифест: получение + валидация ---
        try {
            $raw = $this->http->fetchManifest($cfg['core_url'], $cfg['channel_code'], $cfg['token']);
            $manifest = $this->manifestValidator->validate($this->manifestValidator->parse($raw));
        } catch (UnsupportedSchemaVersionException $e) {
            // Fail-closed по мажору: ничего не скачиваем, шлём apply-report failed.
            $jobsEntity->add([
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_MANIFEST,
                'error_message' => $e->getMessage(),
                'started_at'    => $this->now(),
                'finished_at'   => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_FAILED,
                ['phase' => Contract::PHASE_MANIFEST],
                $e->getMessage()
            );
            $this->error('CoreSync fail-closed (schema_version): ' . $e->getMessage());

            return;
        } catch (CoreSyncException $e) {
            // Битый токен/канал/манифест: витрина не затронута, apply-report не шлём.
            $jobsEntity->add([
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_MANIFEST,
                'error_message' => $e->getMessage(),
                'started_at'    => $this->now(),
                'finished_at'   => $this->now(),
            ]);
            $this->error('CoreSync: манифест недоступен/некорректен: ' . $e->getMessage());

            return;
        }

        // --- Version-гейт ---
        $incoming = (int) ($manifest['snapshot_version'] ?? 0);
        $last = $jobsEntity->getLastDownloadedVersion();
        $action = VersionGate::decide($incoming, $last);

        if ($action === VersionGate::ACTION_NOOP) {
            $this->info('CoreSync: версия ' . $incoming . ' уже скачана — no-op');

            return;
        }
        if ($action === VersionGate::ACTION_IGNORE) {
            $this->warning('CoreSync: версия манифеста ' . $incoming . ' < последней ' . $last . ' — игнор');

            return;
        }

        // --- Прогон скачивания (RUN) ---
        $files = is_array($manifest['files']) ? array_values($manifest['files']) : [];
        /** @var CoreSyncJobFilesEntity $jobFilesEntity */
        $jobFilesEntity = $this->entityFactory->get(CoreSyncJobFilesEntity::class);

        $resumable = $jobsEntity->findResumable($incoming);
        if ($resumable !== null) {
            // Resume той же версии: переиспользуем job и его чекпоинты (verified скипнутся).
            $jobId = $resumable->id;
            $jobsEntity->update($jobId, [
                'status'           => Contract::STATUS_RUNNING,
                'phase'            => Contract::PHASE_DOWNLOAD,
                'cancel_requested' => 0,
                'error_message'    => null,
                'finished_at'      => null,
            ]);
            $this->info('CoreSync: докачка версии ' . $incoming . ' (job #' . $jobId . ')');
        } else {
            $jobId = $jobsEntity->add([
                'status'           => Contract::STATUS_RUNNING,
                'snapshot_version' => $incoming,
                'phase'            => Contract::PHASE_DOWNLOAD,
                'files_total'      => count($files),
                'files_done'       => 0,
                'bytes_done'       => 0,
                'cancel_requested' => 0,
                'started_at'       => $this->now(),
            ]);
            $jobFilesEntity->seedFiles($jobId, $files);
        }

        $checkpoints = new JobFilesCheckpointStore($jobFilesEntity, $jobId);
        $stagingDir = $this->stagingDir($incoming);
        $isCancelled = function () use ($jobsEntity, $jobId) {
            return $jobsEntity->isCancelRequested($jobId);
        };

        try {
            $status = $this->downloader->download(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                $files,
                $stagingDir,
                $checkpoints,
                $isCancelled
            );
        } catch (Sha256MismatchException $e) {
            $jobsEntity->update($jobId, [
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_DOWNLOAD,
                'files_done'    => $jobFilesEntity->countVerified($jobId),
                'error_message' => $e->getMessage(),
                'finished_at'   => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_FAILED,
                ['phase' => Contract::PHASE_DOWNLOAD, 'snapshot_version' => $incoming],
                $e->getMessage()
            );
            $this->error('CoreSync: sha256-провал, набор не готов: ' . $e->getMessage());

            return;
        }

        $verified = $jobFilesEntity->countVerified($jobId);

        if ($status === Contract::STATUS_CANCELLED) {
            $jobsEntity->update($jobId, [
                'status'      => Contract::STATUS_CANCELLED,
                'phase'       => Contract::PHASE_DOWNLOAD,
                'files_done'  => $verified,
                'finished_at' => $this->now(),
            ]);
            $this->info('CoreSync: прогон отменён — докачает при следующем запуске');

            return;
        }

        // Полный сверенный набор.
        $jobsEntity->update($jobId, [
            'status'      => Contract::STATUS_DOWNLOADED,
            'phase'       => Contract::PHASE_DONE,
            'files_done'  => $verified,
            'finished_at' => $this->now(),
        ]);
        $this->info('CoreSync: версия ' . $incoming . ' скачана и сверена (' . $verified . ' файлов)');
    }

    /**
     * @return array{core_url: string, channel_code: string, token: string}|null
     */
    private function readConfig(): ?array
    {
        $raw = $this->settings->get(Contract::SETTINGS_KEY);
        $data = is_array($raw) ? $raw : [];

        $coreUrl = trim((string) ($data['core_url'] ?? ''));
        $channel = trim((string) ($data['channel_code'] ?? ''));
        $token = (string) ($data['token'] ?? '');

        if ($coreUrl === '' || $channel === '' || $token === '') {
            return null;
        }

        return ['core_url' => $coreUrl, 'channel_code' => $channel, 'token' => $token];
    }

    private function stagingDir(int $version): string
    {
        $root = rtrim((string) $this->config->get('root_dir'), '/\\');

        return $root . '/files/coresync/' . $version;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
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
