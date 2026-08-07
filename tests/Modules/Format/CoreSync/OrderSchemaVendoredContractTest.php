<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

/**
 * Контракт-канон (FEAT-ORD-M §A): vendored order/request-схемы модуля обязаны быть 1:1 с истиной ядра
 * (b2bCRM `docs/contracts/satellite/v1/`). Идентичность пинуется sha256 фактического файла: любая
 * ручная правка завендоренной копии (drift от ядра) красит тест. Плюс структурная сверка — форма,
 * против которой пишутся провайдеры, не должна тихо разойтись со схемой.
 */
class OrderSchemaVendoredContractTest extends TestCase
{
    /** sha256 order.schema.json ядра на момент вендоринга (обнови ОБЕ стороны при бампе контракта). */
    private const ORDER_SCHEMA_SHA256 = '61e8efa1a2b1a5033d97db5bcdcad1e0efa29add81ca57271600237d7a1c67c8';
    /** sha256 request.schema.json ядра на момент вендоринга. */
    private const REQUEST_SCHEMA_SHA256 = '2ee84401e44c2afc603fdf657400f5c387c405c36dfc6c5ff51ca24af797bd42';

    private function schemaDir(): string
    {
        return dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v1';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(string $file): array
    {
        $path = $this->schemaDir() . '/' . $file;
        $this->assertFileExists($path, 'vendored-схема отсутствует: ' . $file);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'схема не парсится: ' . $file);

        return $decoded;
    }

    public function testOrderSchemaByteIdenticalToCore(): void
    {
        $this->assertSame(
            self::ORDER_SCHEMA_SHA256,
            hash_file('sha256', $this->schemaDir() . '/order.schema.json'),
            'order.schema.json разошёлся с истиной ядра (не 1:1) — пересними vendored-копию'
        );
    }

    public function testRequestSchemaByteIdenticalToCore(): void
    {
        $this->assertSame(
            self::REQUEST_SCHEMA_SHA256,
            hash_file('sha256', $this->schemaDir() . '/request.schema.json'),
            'request.schema.json разошёлся с истиной ядра (не 1:1) — пересними vendored-копию'
        );
    }

    public function testOrderSchemaStructure(): void
    {
        $schema = $this->loadSchema('order.schema.json');
        $this->assertSame(['external_id', 'items'], $schema['required']);
        $this->assertArrayHasKey('external_variant_id', $schema['properties']['items']['items']['properties']);
        $this->assertSame(['external_variant_id', 'name', 'qty'], $schema['properties']['items']['items']['required']);
    }

    public function testRequestSchemaStructure(): void
    {
        $schema = $this->loadSchema('request.schema.json');
        $this->assertSame(['external_id', 'type'], $schema['required']);
        $this->assertSame(
            ['callback', 'price_request', 'form', 'other'],
            $schema['properties']['type']['enum'],
            'enum типов заявки — зеркало ядра'
        );
    }
}
