<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
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
    /** @var Applier */
    private $applier;
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
        Applier $applier,
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
        $this->applier = $applier;
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
        // Стоп-кран (SATGO-1 §A), вход «крон» + бэкстоп для любого будущего звонящего. Стоит ДО
        // lock: выключенный модуль не трогает ни lock, ни job'ы, ни ядро. Уровень info, не warning —
        // «выключено» это норма оператора, а не сбой; failed-job тут не заводим по той же причине
        // (он врал бы о поломке). Уже БЕГУЩИЙ прогон гейт не убивает: он на входе, а не в цикле —
        // выключение останавливает СЛЕДУЮЩИЕ прогоны.
        if (!Contract::isEnabled($this->settings->get(Contract::SETTINGS_KEY))) {
            $this->info('CoreSync: модуль выключен в настройках — прогон пропущен');

            return;
        }

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

        // --- Version-гейт (применённой считается только версия applied). Force-reapply обходит NOOP. ---
        $incoming = (int) ($manifest['snapshot_version'] ?? 0);
        $last = $jobsEntity->getLastAppliedVersion();
        $forceReapply = $this->isForceReapply();
        $action = VersionGate::decide($incoming, $last);

        if ($action === VersionGate::ACTION_NOOP && !$forceReapply) {
            $this->info('CoreSync: версия ' . $incoming . ' уже применена — no-op');

            return;
        }
        if ($action === VersionGate::ACTION_IGNORE) {
            // Откат назад не переприменяем даже по force-флагу.
            $this->warning('CoreSync: версия манифеста ' . $incoming . ' < последней применённой ' . $last . ' — игнор');

            return;
        }
        if ($forceReapply) {
            $this->info('CoreSync: полное перепринятие версии ' . $incoming . ' (сброс applied_hash)');
        }

        // --- sync_mode-гейт: поддерживаются full и price_stock; иначе fail-closed (не молчаливый full) ---
        $syncMode = (string) ($manifest['sync_mode'] ?? '');
        if ($syncMode !== Contract::SYNC_MODE_FULL && $syncMode !== Contract::SYNC_MODE_PRICE_STOCK) {
            $message = 'sync_mode "' . $syncMode . '" не поддерживается модулем';
            $jobsEntity->add([
                'status'           => Contract::STATUS_FAILED,
                'snapshot_version' => $incoming,
                'phase'            => Contract::PHASE_MANIFEST,
                'error_message'    => $message,
                'started_at'       => $this->now(),
                'finished_at'      => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_FAILED,
                ['phase' => Contract::PHASE_MANIFEST, 'snapshot_version' => $incoming, 'sync_mode' => $syncMode],
                $message
            );
            $this->error('CoreSync fail-closed (sync_mode): ' . $message);

            return;
        }

        if ($forceReapply) {
            $this->clearForceReapply();
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

        // Полный сверенный набор → фаза применения в БД витрины.
        $this->applyPhase($jobsEntity, $checkpoints, $isCancelled, $jobId, $incoming, $manifest, $stagingDir, $cfg);
    }

    /**
     * Фаза применения снапшота: applying → applied | held | failed | cancelled + apply-report.
     *
     * @param CoreSyncJobsEntity $jobsEntity сущность прогонов (без строгого типа — как принято в Okay)
     * @param array<string, mixed>                       $manifest
     * @param array{core_url:string,channel_code:string,token:string} $cfg
     */
    private function applyPhase(
        $jobsEntity,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        $jobId,
        int $incoming,
        array $manifest,
        string $stagingDir,
        array $cfg
    ): void {
        $this->reportClient->send(
            $cfg['core_url'],
            $cfg['channel_code'],
            $cfg['token'],
            Contract::REPORT_STARTED,
            ['snapshot_version' => $incoming],
            null
        );

        $jobsEntity->update($jobId, [
            'status' => Contract::STATUS_APPLYING,
            'phase'  => Contract::PHASE_APPLY,
        ]);

        $stats = new ApplyStats();
        try {
            $result = $this->applier->apply($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
        } catch (\Throwable $e) {
            $jobsEntity->update($jobId, [
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_APPLY,
                'error_message' => $e->getMessage(),
                'finished_at'   => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_FAILED,
                ['phase' => Contract::PHASE_APPLY, 'snapshot_version' => $incoming],
                $e->getMessage()
            );
            $this->error('CoreSync apply: непойманная ошибка применения: ' . $e->getMessage());

            return;
        }

        if ($result === Contract::STATUS_CANCELLED) {
            $jobsEntity->update($jobId, [
                'status'      => Contract::STATUS_CANCELLED,
                'phase'       => Contract::PHASE_APPLY,
                'finished_at' => $this->now(),
            ]);
            $this->info('CoreSync apply: отменён — продолжит при следующем запуске');

            return;
        }

        if ($result === Contract::STATUS_FAILED) {
            $jobsEntity->update($jobId, [
                'status'        => Contract::STATUS_FAILED,
                'phase'         => Contract::PHASE_APPLY,
                'error_message' => 'применение остановлено порогом ошибок/валюты/битого файла',
                'finished_at'   => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_FAILED,
                array_merge(['snapshot_version' => $incoming], $stats->toArray()),
                'apply failed'
            );
            $this->error('CoreSync apply: версия ' . $incoming . ' не применена (fail)');

            return;
        }

        if ($result === Contract::STATUS_BOUND) {
            // Bind-фаза: карта связана по SKU, каталог не писался. Full/price_stock применит связанное.
            $jobsEntity->update($jobId, [
                'status'      => Contract::STATUS_BOUND,
                'phase'       => Contract::PHASE_DONE,
                'finished_at' => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_BOUND,
                array_merge(['snapshot_version' => $incoming], $stats->bindToArray()),
                null
            );
            $this->info(sprintf(
                'CoreSync bind: версия %d — связано %d, не найдено %d, конфликтов %d',
                $incoming,
                $stats->bound,
                $stats->unmatched,
                $stats->conflicts
            ));

            return;
        }

        if ($result === Contract::STATUS_HELD) {
            $jobsEntity->update($jobId, [
                'status'      => Contract::STATUS_HELD,
                'phase'       => Contract::PHASE_DONE,
                'finished_at' => $this->now(),
            ]);
            $this->reportClient->send(
                $cfg['core_url'],
                $cfg['channel_code'],
                $cfg['token'],
                Contract::REPORT_HELD,
                [
                    'snapshot_version' => $incoming,
                    'absent_count'     => $stats->absentCount,
                    'threshold'        => Contract::ABSENT_MAX_RATIO,
                ],
                null
            );
            $this->warning('CoreSync apply: версия ' . $incoming . ' применена частично (held: absent > порога)');

            return;
        }

        // applied
        $jobsEntity->update($jobId, [
            'status'      => Contract::STATUS_APPLIED,
            'phase'       => Contract::PHASE_DONE,
            'finished_at' => $this->now(),
        ]);
        $this->reportClient->send(
            $cfg['core_url'],
            $cfg['channel_code'],
            $cfg['token'],
            Contract::REPORT_APPLIED,
            array_merge(['snapshot_version' => $incoming], $stats->toArray()),
            null
        );
        $this->info('CoreSync apply: версия ' . $incoming . ' применена');
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

    private function isForceReapply(): bool
    {
        return !empty($this->settings->get(Contract::SETTINGS_FORCE_REAPPLY_KEY));
    }

    private function clearForceReapply(): void
    {
        $this->settings->set(Contract::SETTINGS_FORCE_REAPPLY_KEY, 0);
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
