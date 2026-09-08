<?php

namespace Okay\Modules\Format\CoreSync\Core;

/**
 * Константы протокола обмена (статусы job'а/файлов, фазы, типы сущностей карты, лимиты).
 * Форма манифеста/строк — vendored-схемы в schema/v1 (истина — b2bCRM docs/contracts/satellite/v1).
 */
class Contract
{
    /** Ceremony/pull остаются на v1; snapshot consumer additionally supports v2/v3. */
    const SCHEMA_MAJOR = 1;
    const SNAPSHOT_SCHEMA_V1 = '1.0.0';
    const SNAPSHOT_SCHEMA_V2 = '2.0.0';
    const SNAPSHOT_SCHEMA_V3 = '3.0.0';
    const SNAPSHOT_SCHEMA_MAJORS = [1, 2, 3];

    /** V3 adds product translations but deliberately preserves every structural v2 branch. */
    public static function isSnapshotStructuralV2Plus(int $schemaMajor): bool
    {
        return in_array($schemaMajor, [2, 3], true);
    }

    /** Runtime/admin setting used to scope v2 source identities to one satellite. */
    const SETTINGS_SOURCE_INSTANCE_FIELD = 'source_instance';
    const SOURCE_IDENTITY_NAMESPACE = 'okay';
    const SOURCE_IDENTITY_ENTITIES = [self::ENTITY_PRODUCT, self::ENTITY_VARIANT];

    public static function isValidSourceInstance(string $instance): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $instance) === 1;
    }

    /** PHP 7.4-compatible exact list check (array_is_list is PHP 8.1+). */
    public static function isList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /** Ключ настроек модуля в Okay\Core\Settings (значение — ассоц-массив полей). */
    const SETTINGS_KEY = 'coresync_settings';

    /** Поле настроек «Модуль включён» (стоп-кран оператора) внутри SETTINGS_KEY. */
    const SETTINGS_ENABLED_FIELD = 'enabled';

    /**
     * Стоп-кран: читает ли модуль ядро и трогает ли витрину. ЕДИНСТВЕННЫЙ источник этого решения —
     * три точки входа (крон `SyncRunner::run()`, приёмник пинка, кнопка «Запустить сейчас») зовут
     * ровно его, каждая со своей семантикой отказа. Чистая функция над уже прочитанными настройками:
     * зовущему не нужен лишний поход в Settings.
     *
     * **Отсутствующий ключ = ВКЛЮЧЕНО (осознанный дефолт).** До этой ветки настройку никто не читал,
     * поэтому на всех уже настроенных установках (стенд, боевой сателлит) ключа в `coresync_settings`
     * просто нет. Дефолт «выключено» молча остановил бы там обмен в момент выката — ровно тот
     * молчаливый отказ, который этот гейт и чинит. Выключение обязано быть ЯВНЫМ действием оператора.
     *
     * @param mixed $rawSettings значение Settings::get(self::SETTINGS_KEY) (обычно массив)
     */
    public static function isEnabled($rawSettings): bool
    {
        $data = is_array($rawSettings) ? $rawSettings : [];

        if (!array_key_exists(self::SETTINGS_ENABLED_FIELD, $data)) {
            return true;
        }

        return !empty($data[self::SETTINGS_ENABLED_FIELD]);
    }

    /**
     * C. Чек-лист готовности подключения (stage-coresync-ui-diag): чистая функция над уже
     * прочитанными настройками — без сети, без БД, тестируется массивом на входе/выходе. Каждый
     * незакрытый пункт называет ПОСЛЕДСТВИЕ незаполненного поля, а не имя поля — оператор узнаёт
     * «модуль не отвечает на церемонию подключения» ДО того, как упрётся в отказ, а не постфактум из
     * текста подсказки под полем формы. Пусто — вернём [] (всё заполнено, блок не рисуется).
     *
     * @param array<string, mixed> $cfg то же значение, что Settings::get(self::SETTINGS_KEY)
     * @return array<int, array{field:string, message:string}>
     */
    public static function readiness(array $cfg): array
    {
        $items = [];

        if (trim((string) ($cfg['core_url'] ?? '')) === '') {
            $items[] = [
                'field'   => 'core_url',
                'message' => 'Пока не задан адрес ядра, модуль не сможет получить манифест синхронизации.',
            ];
        }
        if (trim((string) ($cfg['channel_code'] ?? '')) === '') {
            $items[] = [
                'field'   => 'channel_code',
                'message' => 'Пока не задан ID канала в ядре, модуль не знает, к какому каналу '
                    . 'обращаться — синхронизация не запустится.',
            ];
        }
        if ((string) ($cfg['token'] ?? '') === '') {
            $items[] = [
                'field'   => 'token',
                'message' => 'Пока не задан токен, ядро откажет модулю в доступе к манифесту.',
            ];
        }
        if (trim((string) ($cfg[self::SETTINGS_SOURCE_INSTANCE_FIELD] ?? '')) === '') {
            $items[] = [
                'field'   => self::SETTINGS_SOURCE_INSTANCE_FIELD,
                'message' => 'Пока не задан идентификатор сателлита (source_instance), снапшоты v2 '
                    . 'не применятся — прогон встанет с ошибкой на манифесте.',
            ];
        }
        if (trim((string) ($cfg['storefront_base_url'] ?? '')) === '') {
            $items[] = [
                'field'   => 'storefront_base_url',
                'message' => 'Пока адрес витрины пуст, модуль не отвечает на церемонию подключения ядра.',
            ];
        }
        if (empty($cfg['currency_map']) || !is_array($cfg['currency_map'])) {
            $items[] = [
                'field'   => 'currency_map',
                'message' => 'Пока нет ни одного соответствия валют, применение снапшота остановится '
                    . 'fail-closed на первой встреченной валюте манифеста.',
            ];
        }
        if (!self::isEnabled($cfg)) {
            $items[] = [
                'field'   => self::SETTINGS_ENABLED_FIELD,
                'message' => 'Модуль выключен — ни крон, ни кнопка «Запустить сейчас» не запустят '
                    . 'синхронизацию, пока галка «Модуль включён» не будет установлена и настройки сохранены.',
            ];
        }

        return $items;
    }

    /**
     * Поле «ID канала в ядре» (ключ настроек исторически зовётся `channel_code`) подставляется
     * СЕГМЕНТОМ ПУТИ в `{core_url}/api/satellite/{channel}/…`, где маршрут ядра — `[0-9]+`.
     * Значит витрина обязана хранить там ЧИСЛОВОЙ id канала: словесный код даёт молчаливый 404.
     * Пустое значение здесь не рассматривается («ещё не настроено» ловит SyncRunner::readConfig).
     */
    public static function isValidChannelId(string $channel): bool
    {
        return (bool) preg_match('/^[0-9]+$/', $channel);
    }

    /** Флаг «полного перепринятия»: следующий прогон переприменяет всё той же версией (обход VersionGate). */
    const SETTINGS_FORCE_REAPPLY_KEY = 'coresync_force_reapply';

    /** Флаг «пинок пришёл во время прогона» (SAT-RT §0.2): следующий прогон обслужит отложенный пинок. */
    const SETTINGS_PING_PENDING_KEY = 'coresync_ping_pending';

    /** Статусы job'а (M2 добавил applying/applied/held; M3 — bound для bind-фазы). */
    const STATUS_CREATED    = 'created';
    const STATUS_RUNNING    = 'running';
    const STATUS_DOWNLOADED = 'downloaded';
    const STATUS_APPLYING   = 'applying';
    const STATUS_APPLIED    = 'applied';
    const STATUS_HELD       = 'held';
    const STATUS_BOUND      = 'bound';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';

    const JOB_STATUSES = [
        self::STATUS_CREATED,
        self::STATUS_RUNNING,
        self::STATUS_DOWNLOADED,
        self::STATUS_APPLYING,
        self::STATUS_APPLIED,
        self::STATUS_HELD,
        self::STATUS_BOUND,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Режимы синхронизации (манифест). M3 включает price_stock (снят fail-closed гейт M2). */
    const SYNC_MODE_FULL        = 'full';
    const SYNC_MODE_PRICE_STOCK = 'price_stock';

    /** Фазы прогона. */
    const PHASE_MANIFEST = 'manifest';
    const PHASE_DOWNLOAD = 'download';
    const PHASE_APPLY    = 'apply';
    const PHASE_DONE     = 'done';

    /** Статусы чекпоинта файла (resume-инвариант: verified скипается; applied — файл применён в БД). */
    const FILE_PENDING    = 'pending';
    const FILE_DOWNLOADED = 'downloaded';
    const FILE_VERIFIED   = 'verified';
    const FILE_APPLIED    = 'applied';
    const FILE_FAILED     = 'failed';

    const FILE_STATUSES = [
        self::FILE_PENDING,
        self::FILE_DOWNLOADED,
        self::FILE_VERIFIED,
        self::FILE_APPLIED,
        self::FILE_FAILED,
    ];

    /** Типы сущностей карты владения sync'а. */
    const ENTITY_CATEGORY = 'category';
    const ENTITY_BRAND    = 'brand';
    const ENTITY_FEATURE  = 'feature';
    const ENTITY_PRODUCT  = 'product';
    const ENTITY_VARIANT  = 'variant';
    const ENTITY_REDIRECT = 'redirect';

    const ENTITY_TYPES = [
        self::ENTITY_CATEGORY,
        self::ENTITY_BRAND,
        self::ENTITY_FEATURE,
        self::ENTITY_PRODUCT,
        self::ENTITY_VARIANT,
        self::ENTITY_REDIRECT,
    ];

    /**
     * Служебная метка карты «bind в процессе» (RISK(v) M3, SAT-RT §0.1). Sticky-флаг переживает
     * interrupt/resume и держит shouldBind() истинным до ПОЛНОГО завершения bind — иначе частичная
     * карта (первый bind-файл прошёл) молча флипает следующий прогон в full/price_stock → дубли
     * каталога. НАМЕРЕННО вне ENTITY_TYPES: изолирована от подсчётов product/variant, absent и
     * FK-разрешения (никто не итерирует этот тип). Строка: applied_hash=BIND_MARKER_ACTIVE — активна,
     * NULL — снята (bind завершён). Живёт в собственной таблице модуля __format__coresync_map.
     */
    const ENTITY_BIND_MARKER      = 'bind_marker';
    const BIND_MARKER_EXTERNAL_ID = 'in_progress';
    const BIND_MARKER_ACTIVE      = 'active';

    /**
     * Служебная метка карты «bind по этой витрине УЖЕ отработал» (D-SAT-BIND-LOOP-NEVER-APPLIES).
     * Вторая строка того же служебного типа (пара entity_type+external_id уникальна) — DDL не трогаем,
     * изоляция от ENTITY_TYPES наследуется от bind_marker даром.
     *
     * ЗАЧЕМ. shouldBind() судил по одному наблюдаемому состоянию («карта пуста + каталог непуст») и не
     * помнил прошлых попыток. Витрина с полностью ЧУЖИМ каталогом (0 совпадений SKU — типовой случай
     * подключения нового клиента) даёт bind, который НЕ пишет ни строки карты ⇒ условие остаётся
     * истинным ⇒ bind→bound→bind→… вечно, и каталог ядра не приезжает никогда. Отказ тихий: job
     * status=bound, ошибок нет. Отметка делает «bind выполнен» ФАКТОМ, а не следствием непустой карты:
     * 0 совпадений — легальный результат («у витрины нет ни одного нашего SKU»), после него прогон
     * уходит в full и витрина получает каталог как новый.
     *
     * Взводится в конце ПОЛНОГО прохода bind (до снятия in_progress), переживает interrupt: приоритет
     * у in_progress — прерванный bind добивается bind'ом, а не улетает в full по этой отметке.
     */
    const BIND_MARKER_DONE_EXTERNAL_ID = 'completed';

    /** Исход bind в stats apply-report (свободный массив контракта; статусы job'а не расширяем). */
    const BIND_OUTCOME_LINKED         = 'linked';
    const BIND_OUTCOME_NOTHING_LINKED = 'nothing_linked';

    /** Решение map-гейта по строке (сердце идемпотентности). */
    const MAP_SKIP   = 'skip';
    const MAP_UPDATE = 'update';
    const MAP_CREATE = 'create';

    /** Состояние картинки в durable-списке (M3 качает в догоняющей фазе). */
    const IMAGE_STATE_PENDING = 'pending';
    const IMAGE_STATE_DONE    = 'done';
    const IMAGE_STATE_FAILED  = 'failed';

    const IMAGE_STATES = [
        self::IMAGE_STATE_PENDING,
        self::IMAGE_STATE_DONE,
        self::IMAGE_STATE_FAILED,
    ];

    /** Число попыток скачивания одной картинки внутри прогона (backoff между ними). */
    const IMAGE_DOWNLOAD_RETRIES = 3;

    /**
     * Кап попыток на пути ТИКА (добор хвоста картинок стабильной версии).
     *
     * Строка `failed` считается retryable, пока `attempts < капа`, и тик её переигрывает; при
     * `attempts >= капа` она exhausted — тик её не трогает вовсе (не качает, не считает попыткой,
     * не пишет строку). Без капа мёртвая ссылка донора (404) переигрывалась бы каждым тиком крона
     * вечно; без добора failed-хвост ждал бы новой версии снапшота или полного «перепринять».
     *
     * Кап действует ТОЛЬКО на пути тика: полный apply (новая версия, force-reapply) переигрывает
     * exhausted-строки как и раньше, семантика `attempts` не меняется.
     */
    const IMAGE_TICK_RETRY_MAX_ATTEMPTS = 5;

    /**
     * Ключ строки `images[]` снапшота с sha256 СОДЕРЖИМОГО объекта (ядро, этап media-content-sha256).
     *
     * Контракт дословно: строка в нижнем регистре, ровно 64 hex. Ключ ОТСУТСТВУЕТ, если ядро не может
     * поручиться за байты. Семантика отсутствия — «усыновлять нельзя, качать», и никогда «усыновить
     * что угодно»: пустое значение не совпадает ни с одним хешем реального файла ({@see isValidContentSha256}).
     *
     * НЕ путать с `url_hash` (sha256 публичного URL) — тот привязан к окружению (AWS_URL) и остаётся
     * идентичностью durable-строки; content-хеш от окружения не зависит и служит коротким замыканием
     * ПЕРЕД скачиванием.
     */
    const IMAGE_CONTENT_SHA256_KEY = 'sha256';

    /** Колонка durable-таблицы картинок под {@see IMAGE_CONTENT_SHA256_KEY} (имя не `sha256`: рядом живёт url_hash). */
    const IMAGE_CONTENT_SHA256_FIELD = 'content_sha256';

    /** Форма content-хеша: ровно 64 hex в нижнем регистре. */
    const IMAGE_CONTENT_SHA256_PATTERN = '/\A[a-f0-9]{64}\z/';

    /**
     * Годен ли content-хеш для усыновления. Fail-closed: пусто/не 64 hex/верхний регистр → false,
     * то есть строка уходит в скачивание, а не усыновляет случайный файл.
     *
     * @param mixed $value
     */
    public static function isValidContentSha256($value): bool
    {
        return is_string($value) && preg_match(self::IMAGE_CONTENT_SHA256_PATTERN, $value) === 1;
    }

    /** Статусы apply-report. */
    const REPORT_FAILED  = 'failed';
    const REPORT_STARTED = 'started';
    const REPORT_APPLIED = 'applied';
    const REPORT_HELD    = 'held';
    const REPORT_BOUND   = 'bound';

    /** Сколько SKU-сэмплов класть в error-детали bind-конфликтов. */
    const BIND_CONFLICT_SAMPLE_MAX = 20;

    /** Обязательные ключи строки NDJSON (форма контракта). */
    const NDJSON_REQUIRED_KEYS = ['external_id', 'hash', 'data'];

    /** Пороги-предохранители. */
    const NDJSON_MAX_BROKEN_RATIO = 0.05; // >5% битых строк файла → fail файла
    const PHASE_MAX_ERROR_RATIO   = 0.10; // >10% ошибок строк фазы → job failed
    const ABSENT_MAX_RATIO        = 0.20; // absent >20% товаров карты → фаза absent пропущена (held)

    /** Число перекачек одного файла при sha256-несовпадении/сбое. */
    const MAX_FILE_RETRIES = 3;

    // ------------------------------------------------------------------
    // Отдача заказов/заявок ядру (FEAT-ORD-M, спека §5; контракт v1 order/request.schema.json)
    // ------------------------------------------------------------------

    /**
     * Строка `schema_version` в конверте pull-ответа (order/request). Аддитивно к контракту v1 —
     * тот же мажор, что снапшот/церемония (README ядра: `schema_version` остаётся `1.0.0`). Ядро
     * отвергает пачку с чужим мажором ({@see \App\Channels\Satellite\Orders\SatellitePullClient}).
     */
    const SCHEMA_VERSION = '1.0.0';

    /** action-и обмена заказами/заявками (приходят тем же HMAC-каналом, что пинок/церемония). */
    const ACTION_PULL_ORDERS   = 'pull_orders';
    const ACTION_PULL_REQUESTS = 'pull_requests';
    const ACTION_ACK_ORDERS    = 'ack_orders';
    const ACTION_ACK_REQUESTS  = 'ack_requests';

    const ORDER_ACTIONS = [
        self::ACTION_PULL_ORDERS,
        self::ACTION_PULL_REQUESTS,
        self::ACTION_ACK_ORDERS,
        self::ACTION_ACK_REQUESTS,
    ];

    /** Тип строки в таблице доставки `__format__coresync_orders_out` (заказ | заявка). */
    const OUT_ENTITY_ORDER   = 'order';
    const OUT_ENTITY_REQUEST = 'request';

    /** Кэп пачки pull (спека §5: ядро запрашивает ≤ 100; модуль всё равно клампит на своей стороне). */
    const ORDER_PULL_LIMIT_MAX = 100;

    /** Тип заявки в request.schema.json для Okay-обратного звонка (`__callbacks`). */
    const REQUEST_TYPE_CALLBACK = 'callback';

    /** Тело пинка «есть новые заказы» на токен-URL ядра (`POST …/events`). */
    const EVENT_ORDERS   = 'orders';
    const EVENT_REQUESTS = 'requests';

    /** Ключ настроек: id строки-маркера статуса «принят в обработку» в `__orders_status`. */
    const SETTINGS_ACCEPTED_STATUS_ID_KEY = 'coresync_accepted_status_id';

    /** Имя строки-маркера статуса, которую модуль заводит в справочнике оператора (идемпотентно). */
    const ACCEPTED_STATUS_NAME = 'Принят в обработку (CoreSync)';
}
