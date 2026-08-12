<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

/**
 * Product snapshot schema must preserve OkayCMS' three-state stock contract:
 * explicit null means unlimited, while integers distinguish zero from finite stock.
 */
class ProductSchemaVendoredContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function loadProductSchema(): array
    {
        $path = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v1/product.schema.json';
        $this->assertFileExists($path, 'vendored product schema is missing');

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'vendored product schema is not valid JSON');

        return $decoded;
    }

    public function testVariantStockIsRequiredAndAllowsExactlyIntegerOrNull(): void
    {
        $schema = $this->loadProductSchema();
        $variantSchema = $schema['properties']['data']['properties']['variants']['items'];

        $this->assertContains('stock', $variantSchema['required']);
        $this->assertSame(
            ['integer', 'null'],
            $variantSchema['properties']['stock']['type'],
            'explicit null is the OkayCMS unlimited-stock state and must remain contract-valid'
        );
    }
}
