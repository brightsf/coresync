<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

final class VariantStockReadbackTest extends TestCase
{
    use BuildsApplierEnv;

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

    public function testV3FullApplyAcceptsNativeUnlimitedProjectionAndFinalizesHashOnce(): void
    {
        [$env, $fixture, $row, $manifest] = $this->existingVariantScenario(null);

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertNull($env->var->rows[1]['stock'], 'storage must remain unlimited');
        $this->assertSame($row['hash'], $this->productMap($env)->applied_hash);
        $this->assertCount(1, $env->prod->rows);
        $this->assertCount(1, $env->var->rows);

        $productMutations = $env->prod->mutations();
        $variantMutations = $env->var->mutations();
        [$repeatStatus, $repeatStats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $repeatStatus);
        $this->assertSame(1, $repeatStats->skipped);
        $this->assertSame($productMutations, $env->prod->mutations());
        $this->assertSame($variantMutations, $env->var->mutations());
        $this->assertCount(1, $env->prod->rows);
        $this->assertCount(1, $env->var->rows);
    }

    /** @dataProvider finiteStockValues */
    public function testV3FullApplyKeepsStrictFiniteStockReadback(int $stock): void
    {
        [$env, , $row, $manifest] = $this->existingVariantScenario($stock);

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame($stock, $env->var->rows[1]['stock']);
        $this->assertSame($row['hash'], $this->productMap($env)->applied_hash);
    }

    /** @return array<string, array{int}> */
    public function finiteStockValues(): array
    {
        return [
            'zero' => [0],
            'positive' => [7],
            'display limit is still finite' => [50],
        ];
    }

    /** @dataProvider rejectedStockReadbacks */
    public function testV3FullApplyRejectsStockStorageMismatchBeforeProductHash(
        $desiredStock,
        $storedStock,
        string $infinityMode
    ): void {
        [$env, , , $manifest] = $this->existingVariantScenario($desiredStock);
        $env->var->forceStoredStockAfterWrite = true;
        $env->var->storedStockAfterWrite = $storedStock;
        $env->var->stockInfinityReadMode = $infinityMode;
        $oldHash = $this->productMap($env)->applied_hash;

        try {
            $this->runApply($env, $manifest);
            $this->fail('Stock storage mismatch must abort v3 apply');
        } catch (CoreSyncException $e) {
            $this->assertSame(
                'CoreSync apply: write readback mismatch for variant v3.stock',
                $e->getMessage()
            );
        }

        $this->assertSame($oldHash, $this->productMap($env)->applied_hash);
    }

    /** @return array<string, array{mixed,mixed,string}> */
    public function rejectedStockReadbacks(): array
    {
        return [
            'desired unlimited but stored zero' => [null, 0, 'native'],
            'finite display limit must not accept stored null' => [50, null, 'native'],
            'wrong finite value' => [7, 6, 'native'],
            'missing infinity cannot bless projected stock' => [null, null, 'missing'],
            'false infinity cannot bless projected stock' => [null, null, 'false'],
        ];
    }

    /**
     * @param int|null $desiredStock
     * @return array{0:object,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
     */
    private function existingVariantScenario($desiredStock): array
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $env->var->projectStockLikeOkay = true;
        $env->prod->rows[1] = ['id' => 1, 'external_id' => '42', 'url' => 'truba-42'];
        $env->var->rows[1] = [
            'id' => 1,
            'product_id' => 1,
            'external_id' => '42',
            'sku' => 'SKU-42',
            'price' => '1.00',
            'stock' => 3,
            'currency_id' => 7,
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => '42',
            'local_id' => 1,
            'applied_hash' => str_repeat('0', 64),
            'image_state' => null,
        ]);
        $env->map->add([
            'entity_type' => Contract::ENTITY_VARIANT,
            'external_id' => '42',
            'local_id' => 1,
            'applied_hash' => str_repeat('0', 64),
            'image_state' => null,
        ]);

        $row = $fixture['product_row'];
        $row['hash'] = str_repeat('9', 64);
        $row['data']['variants'][0]['stock'] = $desiredStock;
        $this->putProductRows([$row]);

        return [$env, $fixture, $row, $this->productsManifest('full', [
            'schema_version' => $fixture['schema_version'],
            'language' => $fixture['channel_language'],
            'product_content_languages' => $fixture['product_content_languages'],
        ])];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function putProductRows(array $rows): void
    {
        $this->gz('products-0001.ndjson.gz', array_map(static function (array $row): string {
            return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $rows));
    }

    /** @param array<string, mixed> $fixture */
    private function languages(array $fixture, int &$currentLanguageId): Languages
    {
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn(array_map(static function (array $language): object {
            return (object) ['id' => $language['id'], 'href_lang' => $language['href_lang']];
        }, $fixture['satellite_languages']));
        $languages->method('getLangId')->willReturnCallback(static function () use (&$currentLanguageId): int {
            return $currentLanguageId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use (&$currentLanguageId): void {
            $currentLanguageId = (int) $id;
        });

        return $languages;
    }

    /** @return array<string, mixed> */
    private function sharedFixture(): array
    {
        $path = getenv('SATELLITE_I18N_CONTRACT');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new \RuntimeException('Shared satellite i18n contract is not mounted');
        }
        $fixture = json_decode((string) file_get_contents($path), true);
        if (!is_array($fixture)) {
            throw new \RuntimeException('Shared satellite i18n contract is invalid');
        }

        return $fixture;
    }

    /** @return object */
    private function productMap(object $env)
    {
        return $env->map->findOne([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => '42',
        ]);
    }
}
