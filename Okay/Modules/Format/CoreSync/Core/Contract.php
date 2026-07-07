<?php

namespace Okay\Modules\Format\CoreSync\Core;

/**
 * Константы протокола обмена (статусы job'а/файлов, фазы, типы сущностей карты, лимиты).
 * Форма манифеста/строк — vendored-схемы в schema/v1 (истина — b2bCRM docs/contracts/satellite/v1).
 */
class Contract
{
    /** Поддерживаемый мажор schema_version. Незнакомый мажор → fail-closed. */
    const SCHEMA_MAJOR = 1;

    /** Ключ настроек модуля в Okay\Core\Settings (значение — ассоц-массив полей). */
    const SETTINGS_KEY = 'coresync_settings';

    /** Флаг «полного перепринятия»: следующий прогон переприменяет всё той же версией (обход VersionGate). */
    const SETTINGS_FORCE_REAPPLY_KEY = 'coresync_force_reapply';

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
}
