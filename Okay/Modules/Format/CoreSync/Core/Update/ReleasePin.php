<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

/**
 * Пин канонического источника релизов [SECURITY-SENSITIVE] — дешёвое смягчение до подписи
 * (долг D-SAT-UPDATE-SIGNING, пре-спека §«Риск»). Модуль качает артефакт ТОЛЬКО с этого префикса,
 * а от ядра берёт лишь version+sha256+url. Тогда даже скомпрометированное ядро может навязать максимум
 * НАСТОЯЩИЙ релиз с этого адреса (в паре с version-гейтом «только вверх» — новее локального), а не
 * чужой код с произвольного хоста.
 *
 * ⚠ ЗЕРКАЛО КОНТРАКТА. Значение обязано совпадать с
 * `App\Settings\SatelliteReleaseSettings::RELEASE_URL_PREFIX` в ядре (b2bCRM) — там команда публикации
 * релиза проверяет тот же префикс на входе. Это vendored-копия истины (как schema/v1): при смене
 * префикса в ядре его правят и здесь. Расхождение = сателлиты перестанут принимать легитимные релизы.
 */
class ReleasePin
{
    /** Канонический префикс скачивания релизов (GitHub Releases приватного репо brightsf/coresync). */
    public const RELEASE_PREFIX = 'https://github.com/brightsf/coresync/releases/download/';

    /**
     * Пройдёт ли url пин: строгий префикс + без управляющих символов + без `..`-сегментов в пути
     * (оборонительно: `…/download/../../evil` формально начинается с префикса, но уводит выше по пути —
     * GitHub такое не отдаст, но полагаться на это нельзя).
     */
    public static function isPinned(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        // Строгий префикс: ровно канонический хост/путь релизов.
        if (strncmp($url, self::RELEASE_PREFIX, strlen(self::RELEASE_PREFIX)) !== 0) {
            return false;
        }

        // Ни один сегмент пути не смеет быть `..` (path traversal в самом url).
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
