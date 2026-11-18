<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use LogicException;
use Okay\Core\Translit;

/** Projects OkayCMS base and language rows without booting its session-backed entity layer. */
class PreviewLocalizedRows extends PreviewRows
{
    private $entity;
    private $languages;
    private $translations;
    private $localized;

    public function __construct(
        string $entity,
        iterable $rows,
        array $fields,
        ?PreviewLanguages $languages = null,
        ?PreviewRows $translations = null,
        array $localized = []
    ) {
        if (($languages === null) !== ($translations === null)) {
            throw new \InvalidArgumentException('Offline translation state is incomplete');
        }
        parent::__construct($entity, $rows, array_merge($fields, $localized));
        $this->entity = $entity;
        $this->languages = $languages;
        $this->translations = $translations;
        $this->localized = array_fill_keys($localized, true);
    }

    public function add(array $fields): int
    {
        if (in_array($this->entity, ['product', 'category', 'brand'], true)) {
            $fields['url'] = $this->creationUrl($fields);
        }
        $id = parent::add($fields);
        if ($this->languages !== null) {
            // Okay creates a language row for every installed locale from the insert values.
            foreach ($this->languages->getAllLanguages() as $language) {
                $this->writeTranslation($id, (int) $language->id, $fields);
            }
        }

        return $id;
    }

    public function update(int $id, array $fields): int
    {
        if ($this->languages === null) {
            return parent::update($id, $fields);
        }

        $lang = $this->languages->getLangId();
        if ($lang === (int) $this->languages->getMainLanguage()->id) {
            parent::update($id, $fields);
        } else {
            parent::update($id, array_diff_key($fields, $this->localized));
        }
        $this->writeTranslation($id, $lang, $fields);

        return $id;
    }

    public function get(int $id)
    {
        $row = parent::get($id);

        return $row === false ? false : $this->project($row);
    }

    public function find(array $filters = []): array
    {
        $localizedFilters = array_intersect_key($filters, $this->localized);
        $structuralFilters = array_diff_key($filters, $this->localized);
        $rows = parent::find($this->languages === null ? $filters : $structuralFilters);
        if ($this->languages === null) {
            return $rows;
        }

        $matched = [];
        foreach ($rows as $row) {
            $row = $this->project($row);
            foreach ($localizedFilters as $field => $expected) {
                if ($row->$field !== $expected) {
                    continue 2;
                }
            }
            $matched[] = $row;
        }

        return $matched;
    }

    public function transaction(callable $apply): void
    {
        if ($this->translations === null) {
            parent::transaction($apply);
            return;
        }
        $this->translations->transaction(function () use ($apply): void {
            parent::transaction($apply);
        });
    }

    public function baselineDigest(): string
    {
        return $this->translations === null
            ? parent::baselineDigest()
            : hash('sha256', parent::baselineDigest() . $this->translations->baselineDigest());
    }

    public function changes(): array
    {
        return $this->translations === null
            ? parent::changes()
            : array_merge(parent::changes(), $this->translations->changes());
    }

    private function creationUrl(array $fields): string
    {
        $url = (string) ($fields['url'] ?? '');
        $generated = $url === '';
        if ($generated) {
            $url = str_replace('.', '', Translit::translit((string) ($fields['name'] ?? '')));
        }
        if ($this->entity !== 'product') {
            $url = preg_replace('/[\s]+/ui', '', $url);
        }
        if (!is_string($url) || $url === '') {
            throw new LogicException('Offline preview cannot project an empty generated catalogue URL');
        }
        if ($this->entity === 'product' && !$generated) {
            return $url;
        }
        while ($this->findOne(['url' => $url])) {
            if ($this->entity === 'product') {
                $url = preg_match('/(.+)?_([0-9]+)$/', $url, $parts)
                    ? $parts[1] . '_' . ((int) $parts[2] + 1) : $url . '_1';
            } else {
                $url = preg_match('/(.+)([0-9]+)$/', $url, $parts)
                    ? $parts[1] . ((int) $parts[2] + 1) : $url . '2';
            }
        }

        return $url;
    }

    private function project(object $row): object
    {
        if ($this->translations !== null) {
            $language = $this->translations->findOne([
                'owner_id' => (int) $row->id, 'lang_id' => $this->languages->getLangId(),
            ]);
            foreach ($this->localized as $field => $unused) {
                $row->$field = $language->$field ?? null;
            }
        }

        return $row;
    }

    private function writeTranslation(int $id, int $lang, array $fields): void
    {
        // Okay's getDescription copies values for which isset is true.
        $localized = array_filter(array_intersect_key($fields, $this->localized), static function ($value): bool {
            return $value !== null;
        });
        if ($localized === []) {
            return;
        }
        $key = ['owner_id' => $id, 'lang_id' => $lang];
        $row = $this->translations->findOne($key);
        if ($row) {
            $this->translations->update((int) $row->id, $localized);
        } else {
            $this->translations->add($key + $localized);
        }
    }
}
