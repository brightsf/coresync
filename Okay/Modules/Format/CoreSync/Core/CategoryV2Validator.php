<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/**
 * Strict category-v2 boundary.  The version is selected by the validated manifest;
 * this class deliberately does not inspect or infer any schema version from a row.
 */
class CategoryV2Validator
{
    const MAX_IMAGE_BYTES = 5242880;

    /** @var string[] */
    private $rowKeys = ['external_id', 'hash', 'data'];

    /** @var string[] */
    private $dataKeys = [
        'parent_external_id',
        'position',
        'is_active',
        'source_identity',
        'translations',
        'image',
    ];

    /** @var string[] */
    private $identityKeys = ['namespace', 'instance', 'entity', 'id'];

    /** @var string[] */
    private $translationKeys = [
        'language',
        'name',
        'slug',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'annotation_html',
        'description_html',
    ];

    /** @var string[] */
    private $imageKeys = ['url', 'sha256', 'mime', 'bytes'];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws ManifestException
     */
    public function validate(array $row, string $expectedSourceInstance, ?string $rawJson = null): array
    {
        if (!Contract::isValidSourceInstance($expectedSourceInstance)) {
            throw new ManifestException('Некорректная настройка source_instance для snapshot v2');
        }

        $this->assertExactKeys($row, $this->rowKeys, 'category row');
        $externalId = $this->requiredString($row, 'external_id', 'category row', 64);
        $hash = $this->requiredString($row, 'hash', 'category row', 64);
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new ManifestException('category row hash не соответствует v2 contract');
        }

        $data = $row['data'] ?? null;
        if (!is_array($data)) {
            throw new ManifestException('category row data не является объектом');
        }
        $this->assertExactKeys($data, $this->dataKeys, 'category row data');
        $this->assertRawTranslationsList($rawJson);

        $parentExternalId = $data['parent_external_id'] ?? null;
        if ($parentExternalId !== null && (!is_string($parentExternalId) || $parentExternalId === '' || strlen($parentExternalId) > 64)) {
            throw new ManifestException('category parent_external_id не соответствует v2 contract');
        }
        if (!is_int($data['position'] ?? null) || !is_bool($data['is_active'] ?? null)) {
            throw new ManifestException('category position/is_active не соответствует v2 contract');
        }

        $identity = $this->validateIdentity($data['source_identity'] ?? null, $expectedSourceInstance);
        list($translations, $slugPresent, $slug) = $this->validateTranslations($data['translations'] ?? null);
        $image = $this->validateImage($data['image'] ?? null);

        return [
            'external_id' => $externalId,
            'hash' => $hash,
            'parent_external_id' => $parentExternalId,
            'position' => $data['position'],
            'is_active' => $data['is_active'],
            'source_instance' => $identity['instance'],
            'source_id' => $identity['id'],
            'translations' => $translations,
            'slug_present' => $slugPresent,
            'slug' => $slug,
            'image' => $image,
        ];
    }

    /** @param mixed $value @return array<string, string> */
    private function validateIdentity($value, string $expectedSourceInstance): array
    {
        if (!is_array($value)) {
            throw new ManifestException('category source_identity обязателен для snapshot v2');
        }
        $this->assertExactKeys($value, $this->identityKeys, 'category source_identity');

        foreach ($this->identityKeys as $key) {
            if (!is_string($value[$key] ?? null)) {
                throw new ManifestException('category source_identity не соответствует v2 contract');
            }
        }
        if ($value['namespace'] !== 'okaysat'
            || $value['instance'] !== $expectedSourceInstance
            || $value['entity'] !== 'category'
            || preg_match('/\A[1-9][0-9]*\z/', $value['id']) !== 1
            || strlen($value['id']) > 128) {
            throw new ManifestException('category source_identity не соответствует configured source_instance');
        }

        return $value;
    }

    /** @param mixed $value @return array{array<int, array<string, string>>, bool, ?string} */
    private function validateTranslations($value): array
    {
        if (!is_array($value) || !Contract::isList($value)) {
            throw new ManifestException('category translations не является массивом');
        }

        $normalized = [];
        $seenLanguages = [];
        $nonEmptySlugs = [];
        $slugPresent = false;
        foreach ($value as $index => $translation) {
            if (!is_array($translation)) {
                throw new ManifestException(sprintf('category translations[%s] не является объектом', $index));
            }
            $this->assertAllowedKeys($translation, $this->translationKeys, sprintf('category translations[%s]', $index));
            $language = $this->requiredString($translation, 'language', sprintf('category translations[%s]', $index), 16);
            if (preg_match('/\A[a-z][a-z0-9_-]{0,15}\z/', $language) !== 1 || isset($seenLanguages[$language])) {
                throw new ManifestException('category translation language некорректен или повторяется');
            }
            $seenLanguages[$language] = true;

            $item = ['language' => $language];
            foreach ($this->translationKeys as $field) {
                if ($field === 'language' || !array_key_exists($field, $translation)) {
                    continue;
                }
                if (!is_string($translation[$field])) {
                    throw new ManifestException('category translation field должен быть строкой');
                }
                if (strpos($translation[$field], "\0") !== false) {
                    throw new ManifestException('category translation field содержит запрещённые байты');
                }
                $item[$field] = $translation[$field];
            }

            if (array_key_exists('slug', $item)) {
                $slugPresent = true;
                if ($item['slug'] !== '') {
                    if (!$this->isSafeSlug($item['slug'])) {
                        throw new ManifestException('category translation slug небезопасен');
                    }
                    $nonEmptySlugs[$item['slug']] = true;
                }
            }
            $normalized[] = $item;
        }

        if (count($nonEmptySlugs) > 1) {
            throw new ManifestException('category translation slugs конфликтуют');
        }

        $slug = null;
        if (!empty($nonEmptySlugs)) {
            $slug = (string) array_key_first($nonEmptySlugs);
        } elseif ($slugPresent) {
            $slug = '';
        }

        return [$normalized, $slugPresent, $slug];
    }

    /** Preserve the exact JSON array-vs-object distinction lost by json_decode(..., true). */
    private function assertRawTranslationsList(?string $rawJson): void
    {
        if ($rawJson === null) {
            return;
        }
        $native = json_decode($rawJson);
        if (!is_object($native)
            || !property_exists($native, 'data') || !is_object($native->data)
            || !property_exists($native->data, 'translations') || !is_array($native->data->translations)) {
            throw new ManifestException('category translations должна быть JSON-массивом');
        }
    }

    /** @param mixed $value @return array<string, mixed>|null */
    private function validateImage($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new ManifestException('category image не соответствует v2 contract');
        }
        $this->assertExactKeys($value, $this->imageKeys, 'category image');

        $url = $this->requiredString($value, 'url', 'category image', 2048);
        $sha256 = $this->requiredString($value, 'sha256', 'category image', 64);
        $mime = $this->requiredString($value, 'mime', 'category image', 32);
        $bytes = $value['bytes'] ?? null;
        if (!$this->isSafeImageUrl($url)
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
            || !is_int($bytes) || $bytes < 1 || $bytes > self::MAX_IMAGE_BYTES) {
            throw new ManifestException('category image не соответствует v2 contract');
        }

        return ['url' => $url, 'sha256' => $sha256, 'mime' => $mime, 'bytes' => $bytes];
    }

    /** @param array<string, mixed> $data @param string[] $keys */
    private function assertExactKeys(array $data, array $keys, string $where): void
    {
        $this->assertAllowedKeys($data, $keys, $where);
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManifestException(sprintf('%s: отсутствует обязательное поле %s', $where, $key));
            }
        }
    }

    /** @param array<string, mixed> $data @param string[] $keys */
    private function assertAllowedKeys(array $data, array $keys, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new ManifestException(sprintf('%s: запрещено поле %s', $where, $key));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $where, int $maxLength): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength || strpos($value, "\0") !== false) {
            throw new ManifestException(sprintf('%s: поле %s должно быть непустой строкой', $where, $key));
        }

        return $value;
    }

    private function isSafeSlug(string $slug): bool
    {
        return strlen($slug) <= 255
            && preg_match('/[\x00-\x20\x7f\\\\\/]/', $slug) !== 1;
    }

    private function isSafeImageUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }
}
