<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ProductV4Validator;
use Okay\Modules\Format\CoreSync\Core\ProductV5Validator;
use PHPUnit\Framework\TestCase;

/**
 * Строка товара V4/V5 в форме product_documents_store 1.0.0: state ready|pending, ready несёт
 * file/sha256/bytes/original_filename, pending все четыре null, до 8 на товар. Golden ядра.
 */
class ProductDocumentsStoreValidatorTest extends TestCase
{
    public function testStoreGoldenRowsAreAccepted(): void
    {
        foreach ($this->goldenRows('store') as $row) {
            $validated = $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_STORE);

            $this->assertSame($row['data']['documents'], $validated['data']['documents']);
        }
    }

    public function testStoreGoldenRowsAreAcceptedByV5(): void
    {
        foreach ($this->goldenRows('store') as $row) {
            $validated = (new ProductV5Validator())->validate(
                $row,
                ['ru'],
                ['ru' => 1],
                $this->manifestDocuments(),
                (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                Contract::PRODUCT_DOCUMENTS_MODE_STORE
            );

            $this->assertSame($row['data']['documents'], $validated['data']['documents']);
        }
    }

    /** Без режима (старый путь) store-форма отвергается прежней строгой проверкой. */
    public function testStoreShapeIsRejectedOnLegacyPath(): void
    {
        $this->expectException(ManifestException::class);
        $this->validateV4($this->goldenRows('store')[0], null);
    }

    /** И наоборот: старая форма без state в режиме store не принимается. */
    public function testLegacyShapeIsRejectedInStoreMode(): void
    {
        $row = $this->goldenRows('store')[0];
        $document = $row['data']['documents'][0];
        unset($document['state']);
        $row['data']['documents'] = [$document];

        $this->expectException(ManifestException::class);
        $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_STORE);
    }

    /**
     * @dataProvider brokenDocuments
     * @param callable(array<string,mixed>):array<string,mixed> $mutate
     */
    public function testBrokenStoreDocumentIsRejected(int $index, callable $mutate): void
    {
        $row = $this->goldenRows('store')[0];
        $row['data']['documents'][$index] = $mutate($row['data']['documents'][$index]);

        $this->expectException(ManifestException::class);
        $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_STORE);
    }

    /** @return array<string, array{0:int,1:callable}> */
    public function brokenDocuments(): array
    {
        return [
            'ready without sha256' => [0, static function (array $d): array { $d['sha256'] = null; return $d; }],
            'ready without original_filename' => [0, static function (array $d): array { $d['original_filename'] = null; return $d; }],
            'ready file not by sha' => [0, static function (array $d): array { $d['file'] = 'document-' . str_repeat('a', 64) . '.pdf'; return $d; }],
            'ready bytes differ from manifest' => [0, static function (array $d): array { $d['bytes'] = 73; return $d; }],
            'ready sha not in package' => [0, static function (array $d): array {
                $sha = str_repeat('c', 64);
                $d['sha256'] = $sha; $d['file'] = 'document-' . $sha . '.pdf';
                return $d;
            }],
            'ready path in original_filename' => [0, static function (array $d): array { $d['original_filename'] = '../x.pdf'; return $d; }],
            'pending with sha256' => [1, static function (array $d): array { $d['sha256'] = str_repeat('8', 64); return $d; }],
            'pending with bytes' => [1, static function (array $d): array { $d['bytes'] = 72; return $d; }],
            'pending with original_filename' => [1, static function (array $d): array { $d['original_filename'] = 'x.pdf'; return $d; }],
            'unknown state' => [1, static function (array $d): array { $d['state'] = 'deleted'; return $d; }],
            'empty title' => [1, static function (array $d): array { $d['title'] = ''; return $d; }],
            'negative position' => [1, static function (array $d): array { $d['position'] = -1; return $d; }],
            'extra key' => [1, static function (array $d): array { $d['url'] = 'x'; return $d; }],
            'empty external_id' => [1, static function (array $d): array { $d['external_id'] = ''; return $d; }],
        ];
    }

    public function testDuplicateExternalIdIsRejected(): void
    {
        $row = $this->goldenRows('store')[0];
        $row['data']['documents'][1]['external_id'] = $row['data']['documents'][0]['external_id'];

        $this->expectException(ManifestException::class);
        $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_STORE);
    }

    public function testMoreThanEightDocumentsAreRejected(): void
    {
        $row = $this->goldenRows('store')[0];
        $pending = $row['data']['documents'][1];
        $row['data']['documents'] = [];
        for ($i = 1; $i <= 9; $i++) {
            $pending['external_id'] = (string) (9100 + $i);
            $row['data']['documents'][] = $pending;
        }

        $this->expectException(ManifestException::class);
        $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_STORE);
    }

    public function testDisabledGoldenRowsAreAcceptedWithEmptyDocuments(): void
    {
        foreach ($this->goldenRows('disabled') as $row) {
            $validated = $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_DISABLED);

            $this->assertSame([], $validated['data']['documents']);
        }
    }

    /** disabled: списки строк игнорируются (модуль удаляет всё пришедшее из ядра), форма списка обязательна. */
    public function testDisabledIgnoresRowListContent(): void
    {
        $row = $this->goldenRows('store')[0];

        $validated = $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_DISABLED, []);

        $this->assertSame([], $validated['data']['documents']);
    }

    public function testDisabledStillRequiresList(): void
    {
        $row = $this->goldenRows('disabled')[0];
        $row['data']['documents'] = ['a' => 1];

        $this->expectException(ManifestException::class);
        $this->validateV4($row, Contract::PRODUCT_DOCUMENTS_MODE_DISABLED);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int>|null $documents
     * @return array<string, mixed>
     */
    private function validateV4(array $row, ?string $mode, ?array $documents = null): array
    {
        return (new ProductV4Validator())->validate(
            $row,
            ['ru'],
            ['ru' => 1],
            $documents ?? $this->manifestDocuments(),
            (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            false,
            $mode
        );
    }

    /** @return array<string, int> */
    private function manifestDocuments(): array
    {
        $manifest = json_decode((string) file_get_contents(
            __DIR__ . '/fixtures/golden-documents-store/store/manifest.json'
        ), true);
        $documents = [];
        foreach ($manifest['files'] as $file) {
            if (($file['role'] ?? null) === 'product_document') {
                $documents[$file['sha256']] = $file['bytes'];
            }
        }

        return $documents;
    }

    /** @return list<array<string, mixed>> */
    private function goldenRows(string $mode): array
    {
        $rows = [];
        $lines = file(__DIR__ . '/fixtures/golden-documents-store/' . $mode . '/products.ndjson', FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $rows[] = json_decode($line, true);
        }

        return $rows;
    }
}
