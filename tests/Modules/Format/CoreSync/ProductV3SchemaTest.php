<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

class ProductV3SchemaTest extends TestCase
{
    /** @var array<string, string> */
    private $pinnedHashes = [
        'manifest.schema.json' => '52ef1c1256c37f3673fb5518715bd3163aea2a8ff8f3afb706967447a6b43c7e',
        'product.schema.json' => 'b3493d26f97207056ec103e143f6e24ccb69598434ce620403564df862edc1c2',
        'category.schema.json' => '6fbe985504bb4c1ad8692400121aa95e163f891f9bd7a2a4af7309225a05b323',
    ];

    public function testVendoredSchemasAreExactBytesFromPinnedProducerCommit(): void
    {
        foreach ($this->pinnedHashes as $name => $hash) {
            $path = $this->schemaDir() . '/' . $name;
            $this->assertFileExists($path);
            $this->assertSame($hash, hash_file('sha256', $path), $name);
        }
    }

    public function testManifestAndProductSchemasExposeTheAgreedClosedV3Boundary(): void
    {
        $manifest = $this->schema('manifest.schema.json');
        $product = $this->schema('product.schema.json');

        $this->assertSame('3.0.0', $manifest['properties']['schema_version']['const']);
        $this->assertContains('product_content_languages', $manifest['required']);
        $this->assertFalse($manifest['additionalProperties']);

        $data = $product['properties']['data'];
        $this->assertContains('translations', $data['required']);
        $this->assertFalse($data['additionalProperties']);
        $translation = $data['properties']['translations']['items'];
        $this->assertFalse($translation['additionalProperties']);
        $this->assertSame([
            'language',
            'name',
            'annotation_html',
            'description_html',
            'seo_title',
            'seo_description',
            'seo_keywords',
        ], array_keys($translation['properties']));
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->schemaDir() . '/' . $name), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function schemaDir(): string
    {
        return dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v3';
    }
}
