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

    /** Статусы job'а (applied-статусы добавит M2). */
    const STATUS_CREATED    = 'created';
    const STATUS_RUNNING    = 'running';
    const STATUS_DOWNLOADED = 'downloaded';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';

    const JOB_STATUSES = [
        self::STATUS_CREATED,
        self::STATUS_RUNNING,
        self::STATUS_DOWNLOADED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Фазы прогона. */
    const PHASE_MANIFEST = 'manifest';
    const PHASE_DOWNLOAD = 'download';
    const PHASE_DONE     = 'done';

    /** Статусы чекпоинта файла (resume-инвариант: verified скипается, pending/failed докачивается). */
    const FILE_PENDING    = 'pending';
    const FILE_DOWNLOADED = 'downloaded';
    const FILE_VERIFIED   = 'verified';
    const FILE_FAILED     = 'failed';

    const FILE_STATUSES = [
        self::FILE_PENDING,
        self::FILE_DOWNLOADED,
        self::FILE_VERIFIED,
        self::FILE_FAILED,
    ];

    /** Типы сущностей карты владения sync'а. */
    const ENTITY_TYPES = ['category', 'brand', 'feature', 'product', 'variant', 'redirect'];

    /** Статусы apply-report (в M1 шлётся только failed). */
    const REPORT_FAILED = 'failed';

    /** Число перекачек одного файла при sha256-несовпадении/сбое. */
    const MAX_FILE_RETRIES = 3;
}
