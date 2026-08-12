<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

class ProductV2SchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        $path = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v2/product.schema.json';
        $this->assertFileExists($path);
        $schema = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($schema);

        return $schema;
    }

    public function testProductAndVariantSourceIdentitiesAreOptionalNullableStrictObjects(): void
    {
        $schema = $this->schema();
        $data = $schema['properties']['data'];
        $variant = $data['properties']['variants']['items'];

        $this->assertNotContains('source_identity', $data['required']);
        $this->assertNotContains('source_identity', $variant['required']);
        $this->assertIdentity($data['properties']['source_identity'], 'product');
        $this->assertIdentity($variant['properties']['source_identity'], 'variant');
    }

    /** @param array<string, mixed> $identity */
    private function assertIdentity(array $identity, string $entity): void
    {
        $this->assertSame(['object', 'null'], $identity['type']);
        $this->assertFalse($identity['additionalProperties']);
        $this->assertSame(['namespace', 'instance', 'entity', 'id'], $identity['required']);
        $this->assertSame('okay', $identity['properties']['namespace']['const']);
        $this->assertSame('^[a-z0-9][a-z0-9_-]{0,63}$', $identity['properties']['instance']['pattern']);
        $this->assertSame($entity, $identity['properties']['entity']['const']);
        $this->assertSame('^[1-9][0-9]*$', $identity['properties']['id']['pattern']);
    }
}
