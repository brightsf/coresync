<?php

namespace Okay\Modules\Format\CoreSync\Core;

/**
 * Решение по версии снапшота относительно последней успешно скачанной (M1) /
 * применённой (M2). Защита от отката и дублей пинга.
 */
class VersionGate
{
    /** Новее последней — качать/применять. */
    const ACTION_RUN = 'run';
    /** Ровно последняя — ничего не делать. */
    const ACTION_NOOP = 'noop';
    /** Старее последней — игнор с warning (защита от отката). */
    const ACTION_IGNORE = 'ignore';

    /**
     * @param int      $incoming    версия из манифеста
     * @param int|null $lastApplied последняя скачанная/применённая версия (null — ещё нет)
     */
    public static function decide(int $incoming, ?int $lastApplied): string
    {
        if ($lastApplied === null) {
            return self::ACTION_RUN;
        }
        if ($incoming === $lastApplied) {
            return self::ACTION_NOOP;
        }
        if ($incoming < $lastApplied) {
            return self::ACTION_IGNORE;
        }

        return self::ACTION_RUN;
    }
}
