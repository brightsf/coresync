<?php

namespace Okay\Modules\Format\CoreSync\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Admin\Helpers\BackendCurrenciesHelper;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\UnsupportedSchemaVersionException;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;

/**
 * Admin-страница модуля: форма настроек + статус последнего/текущего прогона +
 * «Проверить связь» (GET manifest) и «Запустить сейчас» (создаёт/гонит прогон).
 * Токен маскируется хвостом — целиком в шаблон не попадает.
 */
class CoreSyncAdmin extends IndexAdmin
{
    public function fetch(
        Settings $settings,
        EntityFactory $entityFactory,
        BackendCurrenciesHelper $backendCurrenciesHelper,
        Languages $languages
    ) {
        if ($this->request->method('POST') && $this->request->post('settings') !== null) {
            $this->saveSettings($settings);
        }

        $data = $this->currentSettings($settings);

        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);

        // Реверс currency_map (manifestCode => currencyId) → (currencyId => manifestCode) для префилла формы.
        $currencyMapById = [];
        foreach ((array) ($data['currency_map'] ?? []) as $manifestCode => $currencyId) {
            $currencyMapById[(int) $currencyId] = $manifestCode;
        }

        $this->design->assign('coresync', [
            'core_url'          => $data['core_url'] ?? '',
            'storefront_base_url' => $data[Describer::SETTING_BASE_URL] ?? '',
            'channel_code'      => $data['channel_code'] ?? '',
            'token_masked'      => $this->maskToken((string) ($data['token'] ?? '')),
            'has_token'         => !empty($data['token']),
            'lang_id'           => $data['lang_id'] ?? null,
            'currency_map_by_id' => $currencyMapById,
            'image_concurrency' => $data['image_concurrency'] ?? 4,
            'enabled'           => !empty($data['enabled']),
        ]);
        // URL приёмника HMAC-пинка — оператор прописывает его как satellite_url канала в ядре.
        $this->design->assign('ping_url', rtrim(Request::getRootUrl(), '/') . '/coresync/ping');
        // Подсказка для поля «Адрес витрины»: адрес ТЕКУЩЕГО запроса админки. Именно подсказка, а не
        // значение по умолчанию — в описание церемонии едет только то, что оператор сохранил сам
        // (Host подделывается, а значение уезжает в <url> боевого фида ядра).
        $this->design->assign('storefront_base_url_hint', rtrim(Request::getRootUrl(), '/'));
        $this->design->assign('last_job', $jobsEntity->findLatest());
        $this->design->assign('currencies', $backendCurrenciesHelper->findAllCurrencies());
        $this->design->assign('langs', $languages->getAllLanguages());

        $this->response->setContent($this->design->fetch('coresync.tpl'));
    }

    /**
     * «Проверить связь»: GET manifest, показать version/counts/generated_at. Токен не возвращается.
     */
    public function checkConnection(Settings $settings, SnapshotHttpClient $http, ManifestValidator $validator)
    {
        $cfg = $this->currentSettings($settings);
        if (empty($cfg['core_url']) || empty($cfg['channel_code']) || empty($cfg['token'])) {
            return $this->json(['success' => false, 'error' => 'Не заданы адрес ядра, канал или токен']);
        }

        try {
            $raw = $http->fetchManifest($cfg['core_url'], $cfg['channel_code'], $cfg['token']);
            $manifest = $validator->validate($validator->parse($raw));
        } catch (UnsupportedSchemaVersionException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        } catch (CoreSyncException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->json(['success' => true, 'summary' => $validator->summary($manifest)]);
    }

    /**
     * «Запустить сейчас»: гонит прогон (lock защищает от наложений), возвращает текущий статус.
     */
    public function runNow(SyncRunner $syncRunner, EntityFactory $entityFactory)
    {
        $syncRunner->run();

        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);

        return $this->json(['success' => true, 'job' => $this->jobPayload($jobsEntity->findLatest())]);
    }

    /**
     * Статус последнего прогона (reconnect/поллинг из UI).
     */
    public function status(EntityFactory $entityFactory)
    {
        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);

        return $this->json(['success' => true, 'job' => $this->jobPayload($jobsEntity->findLatest())]);
    }

    /**
     * Кооперативная отмена текущего прогона.
     */
    public function cancel(EntityFactory $entityFactory)
    {
        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);
        $job = $jobsEntity->findLatest();
        if (!empty($job) && $job->status === Contract::STATUS_RUNNING) {
            $jobsEntity->requestCancel($job->id);
        }

        return $this->json(['success' => true]);
    }

    /**
     * «Полное перепринятие» (лечение дрифта): сброс applied_hash всех строк карты + image_state в
     * pending + durable-картинки в pending + выставление force-флага. Следующий прогон переприменит
     * всё той же версией (обход VersionGate). Каталог вне карты не трогается.
     */
    public function reapply(Settings $settings, EntityFactory $entityFactory)
    {
        /** @var CoreSyncMapEntity $mapEntity */
        $mapEntity = $entityFactory->get(CoreSyncMapEntity::class);
        $mapEntity->resetForReapply();

        /** @var CoreSyncImagesEntity $imagesEntity */
        $imagesEntity = $entityFactory->get(CoreSyncImagesEntity::class);
        $imagesEntity->resetStatesToPending();

        // Обход VersionGate: следующий прогон переприменит текущую (уже применённую) версию.
        $settings->set(Contract::SETTINGS_FORCE_REAPPLY_KEY, 1);

        return $this->json(['success' => true]);
    }

    private function saveSettings(Settings $settings): void
    {
        $post = $this->request->post('settings', 'array');
        $current = $this->currentSettings($settings);

        $token = isset($post['token']) ? (string) $post['token'] : '';
        // Пустой токен в форме = «не менять» (маскированное поле не перезаписывает секрет).
        if ($token === '') {
            $token = (string) ($current['token'] ?? '');
        }

        $currencyMap = [];
        if (!empty($post['currency_map']) && is_array($post['currency_map'])) {
            foreach ($post['currency_map'] as $currencyId => $manifestCode) {
                $manifestCode = trim((string) $manifestCode);
                if ($manifestCode !== '') {
                    $currencyMap[$manifestCode] = (int) $currencyId;
                }
            }
        }

        $settings->set(Contract::SETTINGS_KEY, [
            'core_url'          => trim((string) ($post['core_url'] ?? '')),
            // Канонический адрес витрины для церемонии подключения: у Okay своего источника site-URL
            // нет, а брать Host из запроса нельзя — значение уезжает в <url> боевого фида ядра.
            Describer::SETTING_BASE_URL => trim((string) ($post[Describer::SETTING_BASE_URL] ?? '')),
            'channel_code'      => trim((string) ($post['channel_code'] ?? '')),
            'token'             => $token,
            'lang_id'           => isset($post['lang_id']) ? (int) $post['lang_id'] : null,
            'currency_map'      => $currencyMap,
            'image_concurrency' => isset($post['image_concurrency']) ? (int) $post['image_concurrency'] : 4,
            'enabled'           => !empty($post['enabled']) ? 1 : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSettings(Settings $settings): array
    {
        $raw = $settings->get(Contract::SETTINGS_KEY);

        return is_array($raw) ? $raw : [];
    }

    private function maskToken(string $token): string
    {
        if ($token === '') {
            return '';
        }
        $tail = strlen($token) > 4 ? substr($token, -4) : $token;

        return '••••' . $tail;
    }

    /**
     * @param object|null $job
     * @return array<string, mixed>|null
     */
    private function jobPayload($job): ?array
    {
        if (empty($job)) {
            return null;
        }

        return [
            'id'               => (int) $job->id,
            'status'           => $job->status,
            'phase'            => $job->phase,
            'snapshot_version' => $job->snapshot_version !== null ? (int) $job->snapshot_version : null,
            'files_total'      => (int) $job->files_total,
            'files_done'       => (int) $job->files_done,
            'cancel_requested' => (int) $job->cancel_requested,
            'error_message'    => $job->error_message,
            'started_at'       => $job->started_at,
            'finished_at'      => $job->finished_at,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload)
    {
        $this->response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }
}
