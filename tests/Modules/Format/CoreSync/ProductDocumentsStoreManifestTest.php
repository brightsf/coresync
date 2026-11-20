<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Ключ манифеста product_documents (product_documents_store 1.0.0). Golden-пакеты store/disabled
 * скопированы из b2bCRM docs/contracts/satellite/product-documents-store/v1/golden (ядро 4bfd9569a).
 */
class ProductDocumentsStoreManifestTest extends TestCase
{
    public function testStoreGoldenManifestIsAcceptedAndModeIsStore(): void
    {
        $manifest = $this->validate($this->goldenJson('store'));

        $this->assertSame(Contract::PRODUCT_DOCUMENTS_MODE_STORE, Contract::productDocumentsMode($manifest));
    }

    public function testDisabledGoldenManifestIsAcceptedAndModeIsDisabled(): void
    {
        $manifest = $this->validate($this->goldenJson('disabled'));

        $this->assertSame(Contract::PRODUCT_DOCUMENTS_MODE_DISABLED, Contract::productDocumentsMode($manifest));
    }

    /** Без ключа манифест V4 прежний, режим null: работает старый путь документов. */
    public function testManifestWithoutKeyKeepsLegacyPath(): void
    {
        $manifest = json_decode($this->goldenJson('store'), true);
        unset($manifest['product_documents']);

        $validated = $this->validate((string) json_encode($manifest));

        $this->assertNull(Contract::productDocumentsMode($validated));
    }

    /**
     * @dataProvider invalidModeValues
     * @param mixed $value
     */
    public function testInvalidProductDocumentsValueIsRejected($value): void
    {
        $manifest = json_decode($this->goldenJson('store'), true);
        $manifest['product_documents'] = $value;

        $this->expectException(ManifestException::class);
        $this->validate((string) json_encode($manifest));
    }

    /** @return array<string, array{0:mixed}> */
    public function invalidModeValues(): array
    {
        return [
            'unknown mode' => [['mode' => 'delete']],
            'extra key' => [['mode' => 'store', 'limit' => 1]],
            'missing mode' => [new \stdClass()],
            'scalar' => ['store'],
            'null' => [null],
            'list' => [['store']],
        ];
    }

    public function testKeyIsRejectedBeforeV4(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/fixtures/golden/manifest.json'), true);
        $manifest['schema_version'] = '3.0.0';
        $manifest['language'] = 'ru';
        $manifest['product_content_languages'] = ['ru', 'uk'];
        $manifest['product_documents'] = ['mode' => 'store'];

        $this->expectException(ManifestException::class);
        $this->validate((string) json_encode($manifest));
    }

    /** disabled: в пакете нет ни одного PDF (README контракта, правило 4). */
    public function testDisabledModeRejectsDocumentFiles(): void
    {
        $store = json_decode($this->goldenJson('store'), true);
        $manifest = json_decode($this->goldenJson('disabled'), true);
        $manifest['files'][] = $store['files'][5];

        $this->expectException(ManifestException::class);
        $this->validate((string) json_encode($manifest));
    }

    public function testV5ManifestAcceptsKey(): void
    {
        $manifest = json_decode($this->goldenJson('store'), true);
        $manifest['schema_version'] = '5.0.0';

        $validated = $this->validate((string) json_encode($manifest));

        $this->assertSame(Contract::PRODUCT_DOCUMENTS_MODE_STORE, Contract::productDocumentsMode($validated));
    }

    /** @return array<string, mixed> */
    private function validate(string $json): array
    {
        $validator = new ManifestValidator();

        return $validator->validate($validator->parse($json));
    }

    private function goldenJson(string $mode): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/golden-documents-store/' . $mode . '/manifest.json');
    }
}
