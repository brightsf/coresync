<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ProductV4Validator;
use PHPUnit\Framework\TestCase;

/**
 * Граница строки варианта с удержанием цены (V4). Режим решает Applier (флаг allowVariantPriceHold,
 * в 1.8.4 он поднят и для price_stock); сама строка в full и price_stock одинакова.
 */
class VariantPriceHoldValidatorTest extends TestCase
{
    public function testHoldRowAcceptedWhenAllowed(): void
    {
        $row = $this->row([$this->hold('42', 7), $this->priced('43')]);

        $validated = $this->validate($row, true);

        $variant = $validated['data']['variants'][0];
        $this->assertNull($variant['price']);
        $this->assertSame(Contract::FULL_VARIANT_PRICE_HOLD, $variant['price_action']);
        $this->assertSame(7, $variant['stock']);
    }

    public function testHoldRowWithNullStockAccepted(): void
    {
        $validated = $this->validate($this->row([$this->hold('42', null)]), true);

        $this->assertNull($validated['data']['variants'][0]['stock']);
    }

    public function testHoldRowRejectedWhenNotAllowed(): void
    {
        $this->expectException(ManifestException::class);

        $this->validate($this->row([$this->hold('42', 7)]), false);
    }

    public function testHoldRequiresProductSourceIdentity(): void
    {
        $row = $this->row([$this->hold('42', 7)]);
        unset($row['data']['source_identity']);

        $this->expectException(ManifestException::class);
        $this->validate($row, true);
    }

    public function testHoldRequiresSourceIdentityOnEverySibling(): void
    {
        $sibling = $this->priced('43');
        unset($sibling['source_identity']);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('source_identity');
        $this->validate($this->row([$this->hold('42', 7), $sibling]), true);
    }

    public function testHoldWithPriceRejected(): void
    {
        $hold = $this->hold('42', 7);
        $hold['price'] = ['amount' => '1.00', 'currency' => 'UAH'];

        $this->expectException(ManifestException::class);
        $this->validate($this->row([$hold]), true);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function validate(array $row, bool $allowHold): array
    {
        return (new ProductV4Validator())->validate(
            $row,
            ['ru', 'uk'],
            ['ru' => 91, 'uk' => 17],
            [],
            (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $allowHold
        );
    }

    /** @param list<array<string, mixed>> $variants @return array<string, mixed> */
    private function row(array $variants): array
    {
        return [
            'external_id' => '42',
            'hash' => str_repeat('b', 64),
            'data' => [
                'name' => 'Труба RU',
                'slug' => 'truba-42',
                'visible' => true,
                'brand_external_id' => null,
                'categories' => ['primary' => null, 'additional' => []],
                'description_html' => '',
                'seo' => ['title' => null, 'description' => null, 'keywords' => null],
                'images' => [],
                'feature_values' => [],
                'variants' => $variants,
                'source_identity' => $this->identity('product', '42'),
                'translations' => [['language' => 'ru', 'name' => 'Труба RU']],
                'documents' => [],
            ],
        ];
    }

    /** @param int|null $stock @return array<string, mixed> */
    private function hold(string $id, $stock): array
    {
        return [
            'external_id' => $id,
            'price' => null,
            'price_action' => 'hold',
            'sku' => 'ZERO',
            'source_identity' => $this->identity('variant', $id),
            'stock' => $stock,
        ];
    }

    /** @return array<string, mixed> */
    private function priced(string $id): array
    {
        return [
            'external_id' => $id,
            'price' => ['amount' => '100.00', 'currency' => 'UAH'],
            'sku' => 'SKU-' . $id,
            'source_identity' => $this->identity('variant', $id),
            'stock' => 1,
        ];
    }

    /** @return array<string, string> */
    private function identity(string $entity, string $id): array
    {
        return ['entity' => $entity, 'id' => $id, 'instance' => 'pilot', 'namespace' => 'okay'];
    }
}
