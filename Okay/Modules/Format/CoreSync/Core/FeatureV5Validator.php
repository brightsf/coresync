<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Strict, side-effect-free v5 feature row boundary. */
class FeatureV5Validator
{
    /** @param array<string, mixed> $line @param array<int, string> $languages */
    public function validate(array $line, array $languages, string $defaultLanguage, ?string $rawJson = null): array
    {
        $this->keys($line, ['external_id', 'hash', 'data']);
        $externalId = $line['external_id'];
        if (!is_string($externalId) || preg_match('/\A(?:[1-9][0-9]*|attr-.+)\z/', $externalId) !== 1
            || !is_string($line['hash']) || preg_match('/\A[0-9a-f]{64}\z/', $line['hash']) !== 1
            || !is_array($line['data'])) {
            throw new ManifestException('feature v5 row invalid');
        }
        $data = $line['data'];
        $this->keys($data, ['name', 'type', 'unit', 'filterable', 'options', 'translations', 'option_records']);
        if (!is_string($data['name']) || !is_string($data['type']) || !is_bool($data['filterable'])
            || ($data['unit'] !== null && !is_string($data['unit']))
            || !is_array($data['options']) || !Contract::isList($data['options'])
            || !is_array($data['translations']) || !Contract::isList($data['translations'])
            || !is_array($data['option_records']) || !Contract::isList($data['option_records'])) {
            throw new ManifestException('feature v5 data invalid');
        }
        foreach ($data['options'] as $option) {
            if (!is_string($option) || strpos($option, "\0") !== false) {
                throw new ManifestException('feature v5 options invalid');
            }
        }
        if ($rawJson !== null) {
            $native = json_decode($rawJson);
            if (!is_object($native) || !isset($native->data) || !is_object($native->data)
                || !isset($native->data->options, $native->data->translations, $native->data->option_records)
                || !is_array($native->data->options) || !is_array($native->data->translations)
                || !is_array($native->data->option_records)) {
                throw new ManifestException('feature v5 JSON list/object shape invalid');
            }
        }
        $synthetic = strpos($externalId, 'attr-') === 0;
        if ($synthetic) {
            if ($data['translations'] !== [] || $data['option_records'] !== []) {
                throw new ManifestException('synthetic feature v5 has curated identities');
            }
            return $line;
        }
        $this->translations($data['translations'], $languages, 'name', $defaultLanguage, $data['name']);
        if ($data['option_records'] === []) {
            throw new ManifestException('feature v5 curated option set empty');
        }
        if (count($data['options']) !== count($data['option_records'])) {
            throw new ManifestException('feature v5 option list mismatch');
        }
        $seen = [];
        foreach ($data['option_records'] as $index => $record) {
            if (!is_array($record)) { throw new ManifestException('feature v5 option record invalid'); }
            $this->keys($record, ['external_id', 'value', 'translations']);
            $optionId = $record['external_id'];
            if (!is_string($optionId) || preg_match('/\A[1-9][0-9]*\z/', $optionId) !== 1
                || isset($seen[$optionId]) || strlen($externalId . ':' . $optionId) > 64
                || !is_string($record['value']) || $record['value'] === ''
                || $record['value'] !== $data['options'][$index]
                || !is_array($record['translations']) || !Contract::isList($record['translations'])) {
                throw new ManifestException('feature v5 option identity/value invalid');
            }
            $seen[$optionId] = true;
            $this->translations($record['translations'], $languages, 'value', $defaultLanguage, $record['value']);
        }
        return $line;
    }

    /** @param array<string, mixed> $data @param array<int, string> $expected */
    private function keys(array $data, array $expected): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) { throw new ManifestException('feature v5 keys invalid'); }
    }

    /** @param array<int, mixed> $rows @param array<int, string> $languages */
    private function translations(array $rows, array $languages, string $field, string $defaultLanguage, string $defaultValue): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new ManifestException('feature v5 translation invalid'); }
            $this->keys($row, ['language', $field]);
            $language = $row['language'];
            $value = $row[$field];
            if (!is_string($language) || !in_array($language, $languages, true) || isset($seen[$language])
                || !is_string($value) || trim($value) === '' || strpos($value, "\0") !== false) {
                throw new ManifestException('feature v5 translation invalid');
            }
            $seen[$language] = $value;
        }
        if (count($seen) !== count($languages) || ($seen[$defaultLanguage] ?? null) !== $defaultValue) {
            throw new ManifestException('feature v5 translation coverage/default mismatch');
        }
    }
}
