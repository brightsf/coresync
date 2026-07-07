<?php

namespace Okay\Modules\Format\CoreSync\Controllers;

use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Psr\Log\LoggerInterface;

/**
 * Приёмник HMAC-пинка ядра (SAT-RT §0.2, контракт SAT-B «Пинок»). Публичный фронтовый роут БЕЗ
 * сессии/CSRF (bare-контроллер — не наследует AbstractController, поэтому не запускает cart/onInit).
 *
 * Транспорт (контракт): `POST /coresync/ping`, тело `{"channel_code":"…","snapshot_version":N}`,
 * заголовок `X-Satellite-Signature: hex hash_hmac('sha256', <raw body>, <token>)`. Подпись сверяется
 * тем же токеном, что и pull, constant-time (`hash_equals`). ЛЮБОЙ отказ — неразличимая 404 (тело НЕ
 * логируется). Валидно: прогон не идёт → запустить; идёт → пометить «обслужить следующим» (§5 —
 * пинок лишь ускорение, истина за pull-манифестом/кроном).
 */
class PingController
{
    /**
     * @return Response
     */
    public function ping(
        Request $request,
        Response $response,
        Settings $settings,
        SyncRunner $syncRunner,
        EntityFactory $entityFactory,
        ?LoggerInterface $logger = null
    ) {
        if (!$request->isPost()) {
            return $this->deny($response);
        }

        $cfg = $settings->get(Contract::SETTINGS_KEY);
        $cfg = is_array($cfg) ? $cfg : [];
        $token = (string) ($cfg['token'] ?? '');
        if ($token === '') {
            // Нечем проверять подпись — неразличимая 404 (не пробируем состояние настроек).
            return $this->deny($response);
        }

        $rawBody = (string) $request->post(); // php://input целиком (без фильтров — байты для подписи)
        $provided = $this->signatureHeader();
        $expected = hash_hmac('sha256', $rawBody, $token);

        // Constant-time сверка; тело НЕ логируем ни при каком исходе (KI-06). Подпись HMAC ключом
        // per-channel access_token — единственная аутентификация (как pull). Тело channel_code НЕ
        // сверяем: в URL-настройке модуля лежит channel_id ядра (сегмент пути `/api/satellite/{id}/`),
        // а в теле пинка едет строковый channel_code (`main-site`) — идентификаторы разные, валидная
        // подпись сама доказывает адресность канала (стык SAT-RT: {channel} в маршруте ядра = [0-9]+).
        if ($provided === '' || !hash_equals($expected, $provided)) {
            $this->log($logger, 'warning', 'CoreSync ping: невалидная подпись — отклонён (404)');

            return $this->deny($response);
        }

        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);

        if ($jobsEntity->hasActiveRun()) {
            // Прогон уже идёт → не плодим второй, помечаем «обслужить следующим».
            $settings->set(Contract::SETTINGS_PING_PENDING_KEY, 1);
            $this->log($logger, 'info', 'CoreSync ping: валиден, прогон идёт — запланирован следующий');

            return $response->setContent(['ok' => true, 'action' => 'scheduled'], RESPONSE_JSON);
        }

        // Простой → запускаем прогон (lock внутри SyncRunner защищает от гонок).
        $this->log($logger, 'info', 'CoreSync ping: валиден — запуск прогона');
        $syncRunner->run();

        return $response->setContent(['ok' => true, 'action' => 'started'], RESPONSE_JSON);
    }

    /**
     * Неразличимая 404 (отказ по подписи/методу/каналу/настройке не пробируется).
     *
     * @return Response
     */
    protected function deny(Response $response)
    {
        return $response->setStatusCode(404)->setContent(['error' => 'not_found'], RESPONSE_JSON);
    }

    /**
     * Заголовок подписи из запроса. Вынесен в seam-метод для тестируемости.
     */
    protected function signatureHeader(): string
    {
        return (string) ($_SERVER['HTTP_X_SATELLITE_SIGNATURE'] ?? '');
    }

    private function log(?LoggerInterface $logger, string $level, string $message): void
    {
        if ($logger !== null) {
            $logger->{$level}($message);
        }
    }
}
