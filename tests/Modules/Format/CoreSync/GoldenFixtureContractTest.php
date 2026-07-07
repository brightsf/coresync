<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

/**
 * «Контракт с двух сторон на одних данных»: golden-фикстуры (копия из ядра b2bCRM) валидируются
 * против vendored-схем модуля по ИМЕНАМ полей. Ловит рассинхрон схемы, фикстуры и парсера.
 */
class GoldenFixtureContractTest extends TestCase
{
    private function goldenDir(): string
    {
        return __DIR__ . '/fixtures/golden';
    }

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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readNdjson(string $file): array
    {
        $path = $this->goldenDir() . '/' . $file;
        $this->assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $lines = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'строка ndjson не парсится в ' . $file);
            $lines[] = $decoded;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $schema
     */
    private function assertLineMatchesSchema(array $line, array $schema, string $where): void
    {
        // Обязательные поля строки (external_id/hash/data) — из схемы, не хардкод.
        foreach ((array) ($schema['required'] ?? []) as $key) {
            $this->assertArrayHasKey($key, $line, $where . ': нет поля "' . $key . '"');
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $line['hash'], $where . ': hash не sha256');
        $this->assertIsString($line['external_id'], $where . ': external_id не строка');

        $dataRequired = (array) ($schema['properties']['data']['required'] ?? []);
        $this->assertIsArray($line['data'], $where . ': data не объект');
        foreach ($dataRequired as $key) {
            $this->assertArrayHasKey($key, $line['data'], $where . ': в data нет "' . $key . '"');
        }
    }

    public function testGoldenLinesConformToEntitySchemas(): void
    {
        $map = [
            'categories.ndjson'    => 'category.schema.json',
            'brands.ndjson'        => 'brand.schema.json',
            'features.ndjson'      => 'feature.schema.json',
            'products-0001.ndjson' => 'product.schema.json',
        ];

        foreach ($map as $ndjson => $schemaFile) {
            $schema = $this->loadSchema($schemaFile);
            $lines = $this->readNdjson($ndjson);
            $this->assertNotEmpty($lines, $ndjson . ' пуст');
            foreach ($lines as $i => $line) {
                $this->assertLineMatchesSchema($line, $schema, $ndjson . '[' . $i . ']');
            }
        }
    }

    public function testManifestCountsMatchGoldenNdjsonRows(): void
    {
        $manifest = json_decode((string) file_get_contents($this->goldenDir() . '/manifest.json'), true);
        $this->assertIsArray($manifest);
        $counts = $manifest['counts'];

        $this->assertSame($counts['categories'], count($this->readNdjson('categories.ndjson')));
        $this->assertSame($counts['brands'], count($this->readNdjson('brands.ndjson')));
        $this->assertSame($counts['features'], count($this->readNdjson('features.ndjson')));

        $products = $this->readNdjson('products-0001.ndjson');
        $this->assertSame($counts['products'], count($products));

        $variants = 0;
        foreach ($products as $p) {
            $variants += count($p['data']['variants']);
        }
        $this->assertSame($counts['variants'], $variants, 'counts.variants должен равняться сумме вариантов');

        $this->assertSame(0, $counts['redirects']);
        $this->assertSame(0, count($this->readNdjson('redirects.ndjson')));
    }

    public function testRedirectsGoldenIsEmpty(): void
    {
        // v1: redirects.ndjson — валидный ПУСТОЙ файл (наполнение SAT-A2).
        $this->assertSame('', trim((string) file_get_contents($this->goldenDir() . '/redirects.ndjson')));
    }
}
