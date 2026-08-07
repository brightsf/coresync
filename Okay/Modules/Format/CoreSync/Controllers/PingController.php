<?php

namespace Okay\Modules\Format\CoreSync\Controllers;

use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Core\Orders\OrdersSyncGateway;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Psr\Log\LoggerInterface;

/**
 * Приёмник HMAC-запросов ядра (SAT-RT §0.2, контракт SAT-B «Пинок»; SATFEED-M — церемония
 * подключения). Публичный фронтовый роут БЕЗ сессии/CSRF (bare-контроллер — не наследует
 * AbstractController, поэтому не запускает cart/onInit).
 *
 * Транспорт (контракт): `POST /coresync/ping`, заголовок
 * `X-Satellite-Signature: hex hash_hmac('sha256', <raw body>, <token>)`. Подпись сверяется тем же
 * токеном, что и pull, constant-time (`hash_equals`), и остаётся ПЕРВЫМ гейтом. ЛЮБОЙ отказ —
 * неразличимая 404 (тело НЕ логируется, KI-06).
 *
 * Один адрес — два действия, различаются ТОЛЬКО телом (ядро хранит один `satellite_url`):
 *  - `{"channel_code":"…","snapshot_version":N}` — пинок: прогон не идёт → запустить; идёт →
 *    пометить «обслужить следующим» (§5 — пинок лишь ускорение, истина за pull-манифестом/кроном);
 *  - `{"action":"describe"}` — церемония подключения: отдать описание себя, прогон НЕ трогать.
 *
 * **Ветвление по телу — ДО всякой работы (SATFEED-M §A).** Раньше приёмник звал `syncRunner->run()`
 * по факту валидной подписи, не глядя в тело: церемония в такой модуль случайно запускала полную
 * синхронизацию каталога. Поэтому тяжёлая работа стартует только на теле ОЖИДАЕМОЙ формы, а тело
 * неизвестной формы отбивается той же 404 (threat-model §2: и совместимость, и DoS-вектор).
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
        Describer $describer,
        ?LoggerInterface $logger = null,
        ?OrdersSyncGateway $ordersGateway = null
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

        // --- Подпись пройдена. Дальше — ветвление по телу, ДО любой работы (§A). ---

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];

        // Церемония подключения: чтение, а не запись. Прогон не трогаем вообще.
        if (($payload['action'] ?? null) === 'describe') {
            try {
                $description = $describer->describe();
            } catch (CoreSyncException $e) {
                // Описать себя честно нельзя (нет адреса витрины / не вывелся шаблон). Наружу — та
                // же неразличимая 404, причина — оператору в лог (без тела запроса).
                $this->log($logger, 'warning', 'CoreSync describe: описание не собрано — ' . $e->getMessage());

                return $this->deny($response);
            }

            $this->log($logger, 'info', 'CoreSync describe: валиден — отдано описание модуля');

            return $this->json($response, $description);
        }

        // Заказы/заявки: pull/ack тем же HMAC-каналом (README ядра §«Заказы и заявки: pull + ack»).
        // Отдача данных = РАБОТА → за стоп-краном (§F, в отличие от describe): выключенный модуль
        // отвечает той же НЕРАЗЛИЧИМОЙ 404. Ветвление по action ДО ping-body-проверки: order-тело
        // не является ping-телом и иначе улетело бы в 404 «неизвестной формы».
        $action = $payload['action'] ?? null;
        if (is_string($action) && in_array($action, Contract::ORDER_ACTIONS, true)) {
            if (!Contract::isEnabled($cfg) || $ordersGateway === null) {
                $this->log($logger, 'info', 'CoreSync ' . $action . ': модуль выключен/не сконфигурирован — 404');

                return $this->deny($response);
            }

            try {
                $result = $ordersGateway->handle($action, $payload);
            } catch (\Throwable $e) {
                // Наружу — та же неразличимая 404 (деталей не палим), причина — оператору в лог.
                $this->log($logger, 'error', 'CoreSync ' . $action . ': сбой обработки — ' . $e->getMessage());

                return $this->deny($response);
            }

            $this->log($logger, 'info', 'CoreSync ' . $action . ': обработан');

            return $this->json($response, $result);
        }

        // Пинок — только на теле ОЖИДАЕМОЙ формы. Неизвестная форма тяжёлую работу не запускает:
        // сегодня валидной подписи достаточно, но проверять форму всё равно надо (threat-model §2).
        if (!$this->isPingBody($payload)) {
            $this->log($logger, 'warning', 'CoreSync ping: тело неизвестной формы — отклонён (404)');

            return $this->deny($response);
        }

        // Стоп-кран (SATGO-1 §A), вход «пинок». Семантика отказа — та же НЕРАЗЛИЧИМАЯ 404, что и на
        // прочих отказах: снаружи «модуль выключен» не должно отличаться от «нет такого роута»
        // (иначе выключенный модуль детектируется анонимом). Гейт стоит ДО отметки «обслужить
        // следующим»: выключенный модуль не копит отложенный пинок, иначе включение задним числом
        // выстрелило бы прогоном за прошлое (зона D-SAT-PING-PENDING-UNREAD — не усугубляем).
        // Церемония describe гейтом НЕ закрыта (см. ветку выше): она ничего не синхронизирует, а
        // ядру нужно уметь подключить ещё не включённый сателлит.
        if (!Contract::isEnabled($cfg)) {
            $this->log($logger, 'info', 'CoreSync ping: модуль выключен в настройках — прогон не запущен (404)');

            return $this->deny($response);
        }

        /** @var CoreSyncJobsEntity $jobsEntity */
        $jobsEntity = $entityFactory->get(CoreSyncJobsEntity::class);

        if ($jobsEntity->hasActiveRun()) {
            // Прогон уже идёт → не плодим второй, помечаем «обслужить следующим».
            $settings->set(Contract::SETTINGS_PING_PENDING_KEY, 1);
            $this->log($logger, 'info', 'CoreSync ping: валиден, прогон идёт — запланирован следующий');

            return $this->json($response, ['ok' => true, 'action' => 'scheduled']);
        }

        // Простой → запускаем прогон (lock внутри SyncRunner защищает от гонок).
        $this->log($logger, 'info', 'CoreSync ping: валиден — запуск прогона');
        $syncRunner->run();

        return $this->json($response, ['ok' => true, 'action' => 'started']);
    }

    /**
     * Тело пинка узнаётся по ожидаемым полям контракта (SendSatellitePingJob ядра шлёт ровно
     * `{"channel_code":"…","snapshot_version":N}`). Значения НЕ сверяются — адресность канала
     * доказывает подпись per-channel токеном (см. комментарий к сверке выше); проверяется только
     * форма, чтобы тело неизвестной формы не запускало прогон.
     *
     * @param array<string, mixed> $payload
     */
    private function isPingBody(array $payload): bool
    {
        return array_key_exists('channel_code', $payload) && array_key_exists('snapshot_version', $payload);
    }

    /**
     * Неразличимая 404 (отказ по подписи/методу/каналу/настройке/форме тела не пробируется).
     *
     * @return Response
     */
    protected function deny(Response $response)
    {
        return $this->json($response->setStatusCode(404), ['error' => 'not_found']);
    }

    /**
     * JSON-ответ: тело json_encode'ится ДО setContent (адаптер Okay Json ждёт строку, не массив).
     *
     * @param array<string, mixed> $payload
     * @return Response
     */
    private function json(Response $response, array $payload)
    {
        return $response->setContent((string) json_encode($payload, JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
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
