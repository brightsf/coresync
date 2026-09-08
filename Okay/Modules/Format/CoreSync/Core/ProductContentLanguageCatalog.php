<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/**
 * One fail-closed boundary for the local Okay language codes used by describe and apply.
 *
 * Numeric IDs are local implementation details: describe exposes only {@see hrefLangs()},
 * while apply resolves the same codes through {@see localIdsByHrefLang()}.
 */
class ProductContentLanguageCatalog
{
    /** @var Languages */
    private $languages;

    public function __construct(Languages $languages)
    {
        $this->languages = $languages;
    }

    /** @return array<int, string> sorted href_lang codes safe to put on the wire */
    public function hrefLangs(): array
    {
        return array_keys($this->localIdsByHrefLang());
    }

    /**
     * @return array<string, int> sorted href_lang => local language id
     * @throws ManifestException when the local catalog is empty or ambiguous
     */
    public function localIdsByHrefLang(): array
    {
        $resolved = [];
        $seenIds = [];

        foreach ($this->languages->getAllLanguages() as $language) {
            $hrefLang = is_object($language) ? ($language->href_lang ?? null) : null;
            $rawId = is_object($language) ? ($language->id ?? null) : null;

            if (!is_string($hrefLang)
                || preg_match('/\A[a-z][a-z0-9_-]{0,15}\z/', $hrefLang) !== 1) {
                throw new ManifestException('Локальный каталог языков содержит некорректный href_lang');
            }
            if ((!is_int($rawId) && (!is_string($rawId) || preg_match('/\A[1-9][0-9]*\z/', $rawId) !== 1))
                || (int) $rawId < 1) {
                throw new ManifestException('Локальный каталог языков содержит некорректный numeric id');
            }

            $id = (int) $rawId;
            if (isset($resolved[$hrefLang])) {
                throw new ManifestException('Локальный каталог языков содержит дубликат href_lang: ' . $hrefLang);
            }
            if (isset($seenIds[$id])) {
                throw new ManifestException('Локальный каталог языков содержит неоднозначный numeric id');
            }

            $resolved[$hrefLang] = $id;
            $seenIds[$id] = true;
        }

        if ($resolved === []) {
            throw new ManifestException('Локальный каталог языков пуст');
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }
}
