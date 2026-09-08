<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/**
 * Closed runtime boundary for multilingual product rows selected by a validated v3 manifest.
 */
class ProductV3Validator
{
    /** @var string[] */
    private $rowKeys = ['external_id', 'hash', 'data'];

    /** @var string[] */
    private $dataKeys = [
        'name',
        'slug',
        'visible',
        'brand_external_id',
        'categories',
        'description_html',
        'seo',
        'images',
        'feature_values',
        'variants',
        'source_identity',
        'translations',
    ];

    /** @var string[] */
    private $translationKeys = [
        'language',
        'name',
        'annotation_html',
        'description_html',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $manifestLanguages
     * @param array<string, int> $localLanguages
     * @return array<string, mixed>
     * @throws ManifestException
     */
    public function validate(
        array $row,
        array $manifestLanguages,
        array $localLanguages,
        ?string $rawJson = null
    ): array {
        $this->assertExactKeys($row, $this->rowKeys, $this->rowKeys, 'product v3 row');
        $this->requiredString($row, 'external_id', 'product v3 row', 128);
        $hash = $this->requiredString($row, 'hash', 'product v3 row', 64);
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new ManifestException('product v3 hash не соответствует contract');
        }

        $data = $row['data'] ?? null;
        if (!is_array($data)) {
            throw new ManifestException('product v3 data не является объектом');
        }
        $requiredData = [
            'name', 'slug', 'visible', 'brand_external_id', 'categories',
            'description_html', 'seo', 'images', 'feature_values', 'variants', 'translations',
        ];
        $this->assertExactKeys($data, $this->dataKeys, $requiredData, 'product v3 data');
        $this->nullableString($data['name'], 'product name', 65535);
        $this->nullableString($data['slug'], 'product slug', 255);
        if (!is_bool($data['visible'])) {
            throw new ManifestException('product visible должен быть boolean');
        }
        $this->nullableString($data['brand_external_id'], 'product brand_external_id', 128);
        $this->nullableString($data['description_html'], 'product description_html', 16777215);

        $this->validateCategories($data['categories']);
        $this->validateSeo($data['seo']);
        $this->validateImages($data['images']);
        $this->validateFeatureValues($data['feature_values']);
        $this->validateVariants($data['variants']);
        if (array_key_exists('source_identity', $data) && $data['source_identity'] !== null) {
            $this->validateIdentity($data['source_identity'], Contract::ENTITY_PRODUCT);
        }

        $translations = $data['translations'] ?? null;
        if (!is_array($translations) || !Contract::isList($translations)) {
            throw new ManifestException('product v3 translations не является списком');
        }
        $this->assertRawTranslationsList($rawJson);

        $manifestSet = [];
        if (!Contract::isList($manifestLanguages) || $manifestLanguages === []) {
            throw new ManifestException('product_content_languages должен быть непустым списком');
        }
        foreach ($manifestLanguages as $language) {
            if (!is_string($language) || $language === '' || isset($manifestSet[$language])) {
                throw new ManifestException('product_content_languages некорректен или содержит дубликат');
            }
            $manifestSet[$language] = true;
        }

        $seen = [];
        foreach ($translations as $index => $translation) {
            if (!is_array($translation)) {
                throw new ManifestException(sprintf('product translations[%s] не является объектом', $index));
            }
            foreach (array_keys($translation) as $key) {
                if (!in_array($key, $this->translationKeys, true)) {
                    throw new ManifestException(sprintf(
                        'product translations[%s]: запрещено поле %s',
                        $index,
                        $key
                    ));
                }
            }

            $language = $translation['language'] ?? null;
            if (!is_string($language)
                || preg_match('/\A[a-z][a-z0-9_-]{0,15}\z/', $language) !== 1
                || isset($seen[$language])) {
                throw new ManifestException('product translation language некорректен или повторяется');
            }
            if (!isset($manifestSet[$language]) || !isset($localLanguages[$language])) {
                throw new ManifestException('product translation language отсутствует в manifest/local catalog');
            }
            $seen[$language] = true;

            $textFields = 0;
            foreach ($this->translationKeys as $field) {
                if ($field === 'language' || !array_key_exists($field, $translation)) {
                    continue;
                }
                if (!is_string($translation[$field]) || strpos($translation[$field], "\0") !== false) {
                    throw new ManifestException('product translation field должен быть строкой без NUL');
                }
                $textFields++;
            }
            if ($textFields === 0) {
                throw new ManifestException('product translation не содержит текстовых полей');
            }
        }

        return $row;
    }

    private function assertRawTranslationsList(?string $rawJson): void
    {
        if ($rawJson === null) {
            return;
        }
        $native = json_decode($rawJson);
        if (!is_object($native) || !property_exists($native, 'data') || !is_object($native->data)) {
            throw new ManifestException('product v3 row/data должны быть JSON-объектами');
        }
        $data = $native->data;
        foreach (['images', 'feature_values', 'variants', 'translations'] as $list) {
            if (!property_exists($data, $list) || !is_array($data->$list)) {
                throw new ManifestException('product v3 ' . $list . ' должен быть JSON-массивом');
            }
        }
        if (!property_exists($data, 'categories') || !is_object($data->categories)
            || !property_exists($data->categories, 'additional') || !is_array($data->categories->additional)) {
            throw new ManifestException('product v3 categories/additional имеют неверную JSON-форму');
        }
        if (!property_exists($data, 'seo') || !is_object($data->seo)) {
            throw new ManifestException('product v3 seo должен быть JSON-объектом');
        }
        foreach ($data->images as $item) {
            if (!is_object($item)) {
                throw new ManifestException('product v3 image должен быть JSON-объектом');
            }
        }
        foreach ($data->feature_values as $item) {
            if (!is_object($item)) {
                throw new ManifestException('product v3 feature value должен быть JSON-объектом');
            }
        }
        foreach ($data->variants as $item) {
            if (!is_object($item) || !property_exists($item, 'price') || !is_object($item->price)) {
                throw new ManifestException('product v3 variant/price должны быть JSON-объектами');
            }
            if (property_exists($item, 'source_identity')
                && $item->source_identity !== null && !is_object($item->source_identity)) {
                throw new ManifestException('product v3 variant source_identity должен быть JSON-объектом');
            }
        }
        foreach ($data->translations as $item) {
            if (!is_object($item)) {
                throw new ManifestException('product v3 translation должен быть JSON-объектом');
            }
        }
        if (property_exists($data, 'source_identity')
            && $data->source_identity !== null && !is_object($data->source_identity)) {
            throw new ManifestException('product v3 source_identity должен быть JSON-объектом');
        }
    }

    /** @param mixed $value */
    private function validateCategories($value): void
    {
        if (!is_array($value)) {
            throw new ManifestException('product categories не является объектом');
        }
        $this->assertExactKeys($value, ['primary', 'additional'], ['primary', 'additional'], 'product categories');
        $this->nullableString($value['primary'], 'product categories.primary', 128);
        $additional = $value['additional'];
        if (!is_array($additional) || !Contract::isList($additional)) {
            throw new ManifestException('product categories.additional не является списком');
        }
        foreach ($additional as $externalId) {
            if (!is_string($externalId) || $externalId === '' || strpos($externalId, "\0") !== false) {
                throw new ManifestException('product categories.additional содержит неверный external_id');
            }
        }
    }

    /** @param mixed $value */
    private function validateSeo($value): void
    {
        if (!is_array($value)) {
            throw new ManifestException('product seo не является объектом');
        }
        $keys = ['title', 'description', 'keywords'];
        $this->assertExactKeys($value, $keys, $keys, 'product seo');
        foreach ($keys as $key) {
            $this->nullableString($value[$key], 'product seo.' . $key, 65535);
        }
    }

    /** @param mixed $value */
    private function validateImages($value): void
    {
        if (!is_array($value) || !Contract::isList($value)) {
            throw new ManifestException('product images не является списком');
        }
        foreach ($value as $index => $image) {
            if (!is_array($image)) {
                throw new ManifestException('product image не является объектом');
            }
            $this->assertExactKeys(
                $image,
                ['url', 'url_hash', 'sort', Contract::IMAGE_CONTENT_SHA256_KEY],
                ['url', 'url_hash', 'sort'],
                sprintf('product images[%s]', $index)
            );
            $this->requiredString($image, 'url', 'product image', 2048);
            $urlHash = $this->requiredString($image, 'url_hash', 'product image', 64);
            if (preg_match('/\A[0-9a-f]{64}\z/', $urlHash) !== 1 || !is_int($image['sort'])) {
                throw new ManifestException('product image url_hash/sort не соответствует contract');
            }
            if (array_key_exists(Contract::IMAGE_CONTENT_SHA256_KEY, $image)
                && !Contract::isValidContentSha256($image[Contract::IMAGE_CONTENT_SHA256_KEY])) {
                throw new ManifestException('product image sha256 не соответствует contract');
            }
        }
    }

    /** @param mixed $value */
    private function validateFeatureValues($value): void
    {
        if (!is_array($value) || !Contract::isList($value)) {
            throw new ManifestException('product feature_values не является списком');
        }
        foreach ($value as $index => $pair) {
            if (!is_array($pair)) {
                throw new ManifestException('product feature value не является объектом');
            }
            $keys = ['feature_external_id', 'value'];
            $this->assertExactKeys($pair, $keys, $keys, sprintf('product feature_values[%s]', $index));
            $this->requiredString($pair, 'feature_external_id', 'product feature value', 128);
            if (!is_string($pair['value']) || strpos($pair['value'], "\0") !== false) {
                throw new ManifestException('product feature value должен быть строкой');
            }
        }
    }

    /** @param mixed $value */
    private function validateVariants($value): void
    {
        if (!is_array($value) || !Contract::isList($value)) {
            throw new ManifestException('product variants не является списком');
        }
        foreach ($value as $index => $variant) {
            if (!is_array($variant)) {
                throw new ManifestException('product variant не является объектом');
            }
            $this->assertExactKeys(
                $variant,
                ['external_id', 'sku', 'price', 'stock', 'source_identity'],
                ['external_id', 'sku', 'price', 'stock'],
                sprintf('product variants[%s]', $index)
            );
            $this->requiredString($variant, 'external_id', 'product variant', 128);
            $this->nullableString($variant['sku'], 'product variant sku', 65535);
            if (!is_int($variant['stock']) && $variant['stock'] !== null) {
                throw new ManifestException('product variant stock должен быть integer или null');
            }
            $price = $variant['price'];
            if (!is_array($price)) {
                throw new ManifestException('product variant price не является объектом');
            }
            $this->assertExactKeys($price, ['amount', 'currency'], ['amount', 'currency'], 'product variant price');
            $this->requiredString($price, 'amount', 'product variant price', 128);
            $this->requiredString($price, 'currency', 'product variant price', 16);
            if (array_key_exists('source_identity', $variant) && $variant['source_identity'] !== null) {
                $this->validateIdentity($variant['source_identity'], Contract::ENTITY_VARIANT);
            }
        }
    }

    /** @param mixed $value */
    private function validateIdentity($value, string $entity): void
    {
        if (!is_array($value)) {
            throw new ManifestException('product source_identity не является объектом');
        }
        $keys = ['namespace', 'instance', 'entity', 'id'];
        $this->assertExactKeys($value, $keys, $keys, 'product source_identity');
        foreach ($keys as $key) {
            if (!is_string($value[$key])) {
                throw new ManifestException('product source_identity fields должны быть строками');
            }
        }
        if ($value['namespace'] !== Contract::SOURCE_IDENTITY_NAMESPACE
            || !Contract::isValidSourceInstance($value['instance'])
            || $value['entity'] !== $entity
            || preg_match('/\A[1-9][0-9]*\z/', $value['id']) !== 1) {
            throw new ManifestException('product source_identity не соответствует contract');
        }
    }

    /** @param array<string, mixed> $data @param string[] $allowed @param string[] $required */
    private function assertExactKeys(array $data, array $allowed, array $required, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ManifestException(sprintf('%s: запрещено поле %s', $where, $key));
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManifestException(sprintf('%s: отсутствует поле %s', $where, $key));
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

    /** @param mixed $value */
    private function nullableString($value, string $where, int $maxLength): void
    {
        if ($value !== null
            && (!is_string($value) || strlen($value) > $maxLength || strpos($value, "\0") !== false)) {
            throw new ManifestException($where . ' должен быть строкой или null');
        }
    }
}
