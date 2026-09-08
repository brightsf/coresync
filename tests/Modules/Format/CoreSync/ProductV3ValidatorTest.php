<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ProductV3Validator;
use PHPUnit\Framework\TestCase;

class ProductV3ValidatorTest extends TestCase
{
    public function testSharedFixtureIsAcceptedAndTranslationKeysAreClosed(): void
    {
        $fixture = $this->sharedFixture();
        $row = $fixture['product_row'];
        $manifestLanguages = $fixture['product_content_languages'];
        $localLanguages = [];
        foreach ($fixture['satellite_languages'] as $language) {
            $localLanguages[$language['href_lang']] = $language['id'];
        }
        $validator = new ProductV3Validator();

        $validated = $validator->validate(
            $row,
            $manifestLanguages,
            $localLanguages,
            (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame($row['data']['translations'], $validated['data']['translations']);

        $forbidden = $row;
        $forbiddenKey = $fixture['forbidden_translation_keys'][0];
        $forbidden['data']['translations'][0][$forbiddenKey] = 'must fail closed';

        $this->expectException(ManifestException::class);
        $validator->validate(
            $forbidden,
            $manifestLanguages,
            $localLanguages,
            (string) json_encode($forbidden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function testEntireProductRowIsRecursivelyClosedAndLanguagesAreExact(): void
    {
        $fixture = $this->sharedFixture();
        $validator = new ProductV3Validator();
        $manifestLanguages = $fixture['product_content_languages'];
        $localLanguages = $this->localLanguages($fixture);
        $base = $fixture['product_row'];

        $cases = [];

        $row = $base;
        $row['unexpected'] = true;
        $cases['unknown row key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        unset($row['hash']);
        $cases['missing row key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['unexpected'] = true;
        $cases['unknown data key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['categories']['unexpected'] = true;
        $cases['unknown categories key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['seo']['unexpected'] = true;
        $cases['unknown seo key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['images'][] = [
            'url' => 'https://cdn.example.test/p.jpg',
            'url_hash' => str_repeat('a', 64),
            'sort' => 0,
            'unexpected' => true,
        ];
        $cases['unknown image key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['feature_values'][] = ['feature_external_id' => 'f1', 'value' => 'v', 'unexpected' => true];
        $cases['unknown feature value key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['variants'][0]['unexpected'] = true;
        $cases['unknown variant key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['variants'][0]['price']['unexpected'] = true;
        $cases['unknown price key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['source_identity']['unexpected'] = true;
        $cases['unknown product identity key'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['translations'][0]['name'] = null;
        $cases['translation null'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['translations'][] = $row['data']['translations'][0];
        $cases['duplicate translation language'] = [$row, $manifestLanguages, $localLanguages, null];

        $row = $base;
        $row['data']['translations'][1] = ['language' => 'uk'];
        $cases['translation without text'] = [$row, $manifestLanguages, $localLanguages, null];

        $withoutUkManifest = ['ru'];
        $cases['translation outside manifest'] = [$base, $withoutUkManifest, $localLanguages, null];

        $withoutUkLocal = ['ru' => $localLanguages['ru']];
        $cases['translation outside local catalog'] = [$base, $manifestLanguages, $withoutUkLocal, null];

        $raw = json_decode((string) json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw->data->translations = (object) $raw->data->translations;
        $rawJson = (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cases['translations JSON object'] = [json_decode($rawJson, true), $manifestLanguages, $localLanguages, $rawJson];

        $raw = json_decode((string) json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw->data->categories->additional = (object) $raw->data->categories->additional;
        $rawJson = (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cases['category ids JSON object'] = [json_decode($rawJson, true), $manifestLanguages, $localLanguages, $rawJson];

        foreach ($cases as $label => $case) {
            try {
                $validator->validate($case[0], $case[1], $case[2], $case[3] ?? $this->encode($case[0]));
                $this->fail('Product v3 validator accepted invalid case: ' . $label);
            } catch (ManifestException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    /** @return array<string, mixed> */
    private function sharedFixture(): array
    {
        $path = getenv('SATELLITE_I18N_CONTRACT');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new \RuntimeException('Shared satellite i18n contract is not mounted');
        }
        $fixture = json_decode((string) file_get_contents($path), true);
        if (!is_array($fixture)) {
            throw new \RuntimeException('Shared satellite i18n contract is invalid');
        }

        return $fixture;
    }

    /** @param array<string, mixed> $fixture @return array<string, int> */
    private function localLanguages(array $fixture): array
    {
        $languages = [];
        foreach ($fixture['satellite_languages'] as $language) {
            $languages[$language['href_lang']] = $language['id'];
        }

        return $languages;
    }

    /** @param array<string, mixed> $row */
    private function encode(array $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
