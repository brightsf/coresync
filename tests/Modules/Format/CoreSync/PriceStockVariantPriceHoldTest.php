<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentSynchronizer;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Удержание цены варианта в price_stock (capability price_stock_variant_price_hold, V4).
 *
 * Ядро шлёт вариант без ручной цены строкой `price: null` + `price_action: hold` со своим остатком
 * (t-ind.ru, канал 20: раньше такие варианты не попадали в снимок, и priceStockZeroVanished ставил им
 * stock=0). Витрина применяет остаток, цену/валюту/старую цену не трогает, вариант не обнуляется и
 * не создаётся, если его нет в карте.
 *
 * KILL-ПРОБЫ: Applier пишет цену при hold → красные testHoldAppliesStockOnlyAndKeepsStorefrontPrices,
 * testHoldWithNullStockKeepsAlwaysInStock; hold-вариант не попадает в seenVariants → красный
 * testHoldAppliesStockOnlyAndKeepsStorefrontPrices (обнулён).
 */
class PriceStockVariantPriceHoldTest extends TestCase
{
    use BuildsApplierEnv;

    private const INSTANCE = 'i18n-fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initStaging();
    }

    protected function tearDown(): void
    {
        $this->cleanupStaging();
        parent::tearDown();
    }

    public function testHoldAppliesStockOnlyAndKeepsStorefrontPrices(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([$this->holdVariant('42', 7), $this->pricedVariant('43', '110.00', 4)])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(2, $stats->updated);
        $this->assertSame(1, $stats->variantsPriceHeld);
        $this->assertSame(1, $stats->toArray()['variants_price_held'], 'отдельный счётчик в apply-report');
        $this->assertSame(0, $stats->stockZeroed, 'hold-вариант в снимке, его не обнуляют');

        $this->assertSame([[42, ['stock' => 7]]], $this->updatesFor($env, 42), 'у hold-варианта пишется только остаток');
        $this->assertSame('500.00', $env->var->rows[42]['price'], 'цена витрины не тронута');
        $this->assertSame('650.00', $env->var->rows[42]['compare_price'], 'старая цена витрины не тронута');
        $this->assertSame(3, $env->var->rows[42]['currency_id']);
        $this->assertSame(7, $env->var->rows[42]['stock']);

        $this->assertSame(
            [[43, ['price' => '110.00', 'stock' => 4, 'currency_id' => 7]]],
            $this->updatesFor($env, 43),
            'соседний вариант с ценой как раньше'
        );
        $this->assertSame([], $env->var->addCalls);
        $this->assertSame([], $env->prod->updateCalls);
    }

    public function testHoldWithNullStockKeepsAlwaysInStock(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([$this->holdVariant('42', null), $this->pricedVariant('43', '100.00', 1)])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([[42, ['stock' => null]]], $this->updatesFor($env, 42), '"всегда в наличии" = stock NULL');
        $this->assertNull($env->var->rows[42]['stock']);
        $this->assertSame('500.00', $env->var->rows[42]['price']);
        $this->assertSame(1, $stats->variantsPriceHeld);
    }

    public function testRepeatHoldIsIdempotentAndPricedRowRestoresPriceWrite(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([$this->holdVariant('42', 7), $this->pricedVariant('43', '100.00', 1)])]);
        $this->runApply($env, $this->manifest('price_stock'));
        $updatesAfterFirst = count($env->var->updateCalls);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(2, $stats->skipped, 'тот же hold-снимок: skip по applied_hash');
        $this->assertSame(0, $stats->updated);
        $this->assertSame(1, $stats->variantsPriceHeld, 'цена удержана и на skip-прогоне');
        $this->assertSame($updatesAfterFirst, count($env->var->updateCalls));

        // Цена вернулась в ядро: hash hold-строки отличается от строки с ценой, цена пишется снова.
        $this->putRows([$this->row([$this->pricedVariant('42', '510.00', 7), $this->pricedVariant('43', '100.00', 1)])]);
        [, $priced] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(1, $priced->updated);
        $this->assertSame(0, $priced->variantsPriceHeld);
        $this->assertSame('510.00', $env->var->rows[42]['price']);
    }

    public function testHoldForUnmappedVariantIsNotCreated(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([
            $this->holdVariant('42', 7),
            $this->pricedVariant('43', '100.00', 1),
            $this->holdVariant('44', 2),
        ])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->skippedNewVariants, 'новый вариант в price_stock не создаётся');
        $this->assertSame(1, $stats->variantsPriceHeld, 'удержан только связанный вариант');
        $this->assertSame([], $env->var->addCalls);
    }

    public function testHoldWithForeignVariantIdentityWritesNothingAndIsNotZeroed(): void
    {
        $env = $this->linkedEnv();
        $hold = $this->holdVariant('42', 7);
        $hold['source_identity']['id'] = '99'; // карта говорит 42
        $this->putRows([$this->row([$hold, $this->pricedVariant('43', '100.00', 1)])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->errors);
        $this->assertSame(0, $stats->variantsPriceHeld);
        $this->assertSame([], $this->updatesFor($env, 42), 'ни остаток, ни цена, ни обнуление');
    }

    public function testWithoutHoldPriceStockWritesPricesAsBefore(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([$this->pricedVariant('42', '520.00', 3), $this->pricedVariant('43', '100.00', 1)])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->variantsPriceHeld);
        $this->assertSame([[42, ['price' => '520.00', 'stock' => 3, 'currency_id' => 7]]], $this->updatesFor($env, 42));
    }

    public function testVanishedVariantIsStillZeroedNextToHold(): void
    {
        $env = $this->linkedEnv();
        $this->putRows([$this->row([$this->holdVariant('42', 7)])]); // 43 пропал из снимка

        [$status, $stats] = $this->runApply($env, $this->manifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->stockZeroed);
        $this->assertSame([[43, ['stock' => 0]]], $this->updatesFor($env, 43));
        $this->assertSame([[42, ['stock' => 7]]], $this->updatesFor($env, 42));
    }

    /** Full-путь не менялся: hold в full по-прежнему держит цену, а счётчик price_stock в нём не растёт. */
    public function testFullModeHoldUnchanged(): void
    {
        $env = $this->linkedEnv();
        // Full V4 сверяет документы товара; их тут нет, синхронизатор только не должен отсутствовать.
        $documents = new \ReflectionProperty($env->applier, 'productDocumentSynchronizer');
        $documents->setAccessible(true);
        $documents->setValue($env->applier, $this->createMock(ProductDocumentSynchronizer::class));
        $this->putRows([$this->row([$this->holdVariant('42', 7), $this->pricedVariant('43', '110.00', 4)])]);

        [$status, $stats] = $this->runApply($env, $this->manifest('full'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(0, $stats->variantsPriceHeld);
        $this->assertSame('500.00', $env->var->rows[42]['price']);
        $this->assertSame('650.00', $env->var->rows[42]['compare_price']);
        $this->assertSame(7, $env->var->rows[42]['stock']);
        foreach ($this->updatesFor($env, 42) as $call) {
            $this->assertArrayNotHasKey('price', $call[1]);
            $this->assertArrayNotHasKey('currency_id', $call[1]);
        }
        $this->assertSame('110.00', $env->var->rows[43]['price']);
    }

    public function testHoldWithoutProductSourceIdentityFailsPreflightBeforeAnyWrite(): void
    {
        $env = $this->linkedEnv();
        $row = $this->row([$this->holdVariant('42', 7), $this->pricedVariant('43', '100.00', 1)]);
        unset($row['data']['source_identity']);
        $this->putRows([$row]);
        $variantsBefore = $env->var->rows;

        try {
            $this->runApply($env, $this->manifest('price_stock'));
            $this->fail('hold без source_identity товара должен падать на preflight');
        } catch (ManifestException $e) {
            $this->assertStringContainsString('price', $e->getMessage());
        }
        $this->assertSame($variantsBefore, $env->var->rows);
        $this->assertSame([], $env->var->updateCalls);
    }

    private function linkedEnv(): object
    {
        $currentLanguageId = 777;
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn([
            (object) ['id' => 91, 'href_lang' => 'ru'],
            (object) ['id' => 17, 'href_lang' => 'uk'],
        ]);
        $languages->method('getLangId')->willReturnCallback(static function () use (&$currentLanguageId): int {
            return $currentLanguageId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use (&$currentLanguageId): void {
            $currentLanguageId = (int) $id;
        });

        $env = $this->buildEnv(['UAH' => 7], $languages, null, true, null, self::INSTANCE);
        $env->prod->rows[42] = ['id' => 42, 'external_id' => '42', 'url' => 'truba-42', 'visible' => 1];
        $env->var->rows[42] = [
            'id' => 42, 'product_id' => 42, 'sku' => 'SKU-42', 'external_id' => '42',
            'price' => '500.00', 'compare_price' => '650.00', 'currency_id' => 3, 'stock' => 0,
        ];
        $env->var->rows[43] = [
            'id' => 43, 'product_id' => 42, 'sku' => 'SKU-43', 'external_id' => '43',
            'price' => '100.00', 'compare_price' => '120.00', 'currency_id' => 3, 'stock' => 1,
        ];
        $env->map->add(['entity_type' => 'product', 'external_id' => '42', 'local_id' => 42, 'applied_hash' => str_repeat('a', 64), 'image_state' => null]);
        $env->map->add(['entity_type' => 'variant', 'external_id' => '42', 'local_id' => 42, 'applied_hash' => 'old42', 'image_state' => null]);
        $env->map->add(['entity_type' => 'variant', 'external_id' => '43', 'local_id' => 43, 'applied_hash' => 'old43', 'image_state' => null]);

        return $env;
    }

    /** @return array<string, mixed> */
    private function manifest(string $mode): array
    {
        return $this->productsManifest($mode, [
            'schema_version' => Contract::SNAPSHOT_SCHEMA_V4,
            'language' => 'ru',
            'product_content_languages' => ['ru', 'uk'],
        ]);
    }

    /**
     * V4-строка товара '42' в каноническом порядке ключей ядра.
     *
     * @param list<array<string, mixed>> $variants
     * @return array<string, mixed>
     */
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

    /**
     * Строка hold побайтно как у ядра: {"external_id","price":null,"price_action":"hold","sku","source_identity","stock"}.
     *
     * @param int|null $stock
     * @return array<string, mixed>
     */
    private function holdVariant(string $id, $stock): array
    {
        return [
            'external_id' => $id,
            'price' => null,
            'price_action' => Contract::FULL_VARIANT_PRICE_HOLD,
            'sku' => 'ZERO',
            'source_identity' => $this->identity('variant', $id),
            'stock' => $stock,
        ];
    }

    /** @return array<string, mixed> */
    private function pricedVariant(string $id, string $amount, int $stock): array
    {
        return [
            'external_id' => $id,
            'price' => ['amount' => $amount, 'currency' => 'UAH'],
            'sku' => 'SKU-' . $id,
            'source_identity' => $this->identity('variant', $id),
            'stock' => $stock,
        ];
    }

    /** @return array<string, string> */
    private function identity(string $entity, string $id): array
    {
        return ['entity' => $entity, 'id' => $id, 'instance' => self::INSTANCE, 'namespace' => 'okay'];
    }

    /** @param list<array<string, mixed>> $rows */
    private function putRows(array $rows): void
    {
        $this->gz('products-0001.ndjson.gz', array_map(static function (array $row): string {
            return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $rows));
    }

    /** @return list<array{0:int,1:array<string,mixed>}> */
    private function updatesFor(object $env, int $id): array
    {
        $calls = [];
        foreach ($env->var->updateCalls as $call) {
            if ((int) $call[0] === $id) {
                $calls[] = [(int) $call[0], $call[1]];
            }
        }

        return $calls;
    }
}
