<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\CategoryV2Validator;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;

class CategoryV2ValidatorTest extends TestCase
{
    public function testValidRowIsNormalizedWithoutInferringVersionFromShape(): void
    {
        $row = $this->row();

        $validated = (new CategoryV2Validator())->validate($row, 'grundfos');

        $this->assertSame('42', $validated['external_id']);
        $this->assertSame('77', $validated['source_id']);
        $this->assertSame('nasosy', $validated['slug']);
        $this->assertSame(['ru', 'uk'], array_column($validated['translations'], 'language'));
        $this->assertSame('', $validated['translations'][0]['annotation_html']);
    }

    /** @dataProvider invalidIdentityRows */
    public function testIdentityIsStrictAndExactInstanceIsRequired($identity, string $instance): void
    {
        $row = $this->row();
        $row['data']['source_identity'] = $identity;

        $this->expectException(ManifestException::class);
        (new CategoryV2Validator())->validate($row, $instance);
    }

    /** @return array<string, array{mixed,string}> */
    public function invalidIdentityRows(): array
    {
        return [
            'null identity' => [null, 'grundfos'],
            'wrong namespace' => [['namespace' => 'core', 'instance' => 'grundfos', 'entity' => 'category', 'id' => '77'], 'grundfos'],
            'wrong instance' => [['namespace' => 'okaysat', 'instance' => 'other', 'entity' => 'category', 'id' => '77'], 'grundfos'],
            'zero id' => [['namespace' => 'okaysat', 'instance' => 'grundfos', 'entity' => 'category', 'id' => '0'], 'grundfos'],
            'path id' => [['namespace' => 'okaysat', 'instance' => 'grundfos', 'entity' => 'category', 'id' => '../77'], 'grundfos'],
            'unsafe configured instance' => [['namespace' => 'okaysat', 'instance' => 'grundfos', 'entity' => 'category', 'id' => '77'], '../grundfos'],
        ];
    }

    public function testConflictingNonEmptyTranslationSlugsRejectWholeRow(): void
    {
        $row = $this->row();
        $row['data']['translations'][1]['slug'] = 'nasosi';

        $this->expectException(ManifestException::class);
        (new CategoryV2Validator())->validate($row, 'grundfos');
    }

    public function testRecursiveAllowListRejectsNestedUnknownKey(): void
    {
        $row = $this->row();
        $row['data']['translations'][0]['token'] = 'secret';

        $this->expectException(ManifestException::class);
        (new CategoryV2Validator())->validate($row, 'grundfos');
    }

    /** @dataProvider nonListTranslations */
    public function testTranslationsMustBeAnExactList(array $translations): void
    {
        $row = $this->row();
        $row['data']['translations'] = $translations;

        $this->expectException(ManifestException::class);
        (new CategoryV2Validator())->validate($row, 'grundfos');
    }

    /** @return array<string, array{array<mixed>}> */
    public function nonListTranslations(): array
    {
        $translation = ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy'];

        return [
            'object-shaped associative array' => [['ru' => $translation]],
            'sparse numeric array' => [[1 => $translation]],
            'mixed keys array' => [[
                0 => $translation,
                'uk' => ['language' => 'uk', 'name' => 'Насоси', 'slug' => 'nasosy'],
            ]],
        ];
    }

    public function testImageDescriptorCapsExpectedBytesAtFiveMib(): void
    {
        $row = $this->row();
        $row['data']['image']['bytes'] = 5 * 1024 * 1024 + 1;

        $this->expectException(ManifestException::class);
        (new CategoryV2Validator())->validate($row, 'grundfos');
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'external_id' => '42',
            'hash' => str_repeat('a', 64),
            'data' => [
                'parent_external_id' => null,
                'position' => 7,
                'is_active' => true,
                'source_identity' => [
                    'namespace' => 'okaysat',
                    'instance' => 'grundfos',
                    'entity' => 'category',
                    'id' => '77',
                ],
                'translations' => [
                    ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy', 'annotation_html' => ''],
                    ['language' => 'uk', 'name' => 'Насоси', 'slug' => 'nasosy'],
                ],
                'image' => [
                    'url' => 'https://cdn.example/category.jpg',
                    'sha256' => str_repeat('b', 64),
                    'mime' => 'image/jpeg',
                    'bytes' => 123,
                ],
            ],
        ];
    }
}
