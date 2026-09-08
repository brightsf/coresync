<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Entity\Entity;
use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Apply\MapGateway;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\MapEntityStub;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

class ProductV3ApplyTest extends TestCase
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

    public function testCreateRetryAfterSecondLanguageFailureReusesPendingReservationAndFinalizesLast(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn(array_map(static function (array $language): object {
            return (object) [
                'id' => $language['id'],
                'href_lang' => $language['href_lang'],
            ];
        }, $fixture['satellite_languages']));
        $languages->method('getLangId')->willReturnCallback(static function () use (&$currentLanguageId): int {
            return $currentLanguageId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use (&$currentLanguageId): void {
            $currentLanguageId = (int) $id;
        });

        $env = $this->buildEnv(['UAH' => 7], $languages);
        $env->prod->throwOnLanguageId = 17;
        $this->gz('products-0001.ndjson.gz', [
            (string) json_encode($fixture['product_row'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $manifest = $this->productsManifest('full', [
            'schema_version' => $fixture['schema_version'],
            'language' => $fixture['channel_language'],
            'product_content_languages' => $fixture['product_content_languages'],
        ]);

        try {
            $this->runApply($env, $manifest);
            $this->fail('Injected UK write failure must abort the first apply');
        } catch (\Throwable $e) {
            $this->assertSame('injected product language write failure: 17', $e->getMessage());
        }

        $pending = $env->map->findOne([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => $fixture['product_row']['external_id'],
        ]);
        $this->assertNotFalse($pending, 'reservation is durable before product creation');
        $this->assertSame(1, (int) $pending->local_id, 'created product is attached before translations');
        $this->assertNull($pending->applied_hash, 'desired hash is not visible after a translation failure');
        $this->assertCount(1, $env->prod->rows);
        $this->assertSame('Труба RU', $env->prod->languageRows[1][91]['name']);
        $this->assertArrayNotHasKey(17, $env->prod->languageRows[1]);
        $this->assertSame(777, $currentLanguageId, 'Okay language context is restored after failure');

        [$retryStatus, $retryStats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $retryStatus);
        $this->assertSame(0, $retryStats->errors);
        $this->assertCount(1, $env->prod->rows, 'same snapshot retry must not create another product');
        $this->assertCount(1, $env->prod->addCalls, 'same snapshot retry reuses the attached local id');
        $this->assertSame('Труба RU', $env->prod->languageRows[1][91]['name']);
        $this->assertSame('Труба UK', $env->prod->languageRows[1][17]['name']);
        $this->assertSame('', $env->prod->languageRows[1][17]['annotation']);
        $this->assertSame(777, $currentLanguageId, 'Okay language context is restored after success');

        $final = $env->map->findOne([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => $fixture['product_row']['external_id'],
        ]);
        $this->assertSame($fixture['product_row']['hash'], $final->applied_hash, 'desired hash is finalized last');

        $mutationsBeforeNoop = $env->prod->mutations();
        [$noopStatus, $noopStats] = $this->runApply($env, $manifest);
        $this->assertSame(Contract::STATUS_APPLIED, $noopStatus);
        $this->assertSame(1, $noopStats->skipped);
        $this->assertSame($mutationsBeforeNoop, $env->prod->mutations(), 'completed same-hash retry is a NOOP');
    }

    public function testCreateOmitsAllTextsFromStructuralAddAndWritesOnlySparseLocaleFields(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $languages = $this->languages($fixture, $currentLanguageId);
        $env = $this->buildEnv(['UAH' => 7], $languages);
        $row = $fixture['product_row'];
        $row['hash'] = str_repeat('b', 64);
        $row['data']['name'] = 'TOP LEVEL MUST NOT WRITE';
        $row['data']['description_html'] = 'TOP LEVEL MUST NOT WRITE';
        $row['data']['seo'] = [
            'title' => 'TOP LEVEL MUST NOT WRITE',
            'description' => 'TOP LEVEL MUST NOT WRITE',
            'keywords' => 'TOP LEVEL MUST NOT WRITE',
        ];
        $row['data']['translations'][1] = [
            'language' => 'uk',
            'description_html' => '<p>Only explicit UK description</p>',
        ];
        $this->putProductRows([$row]);

        [$status, $stats] = $this->runApply($env, $this->v3Manifest($fixture));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        foreach (['name', 'annotation', 'description', 'meta_title', 'meta_description', 'meta_keywords'] as $field) {
            $this->assertArrayNotHasKey($field, $env->prod->addCalls[0], 'structural add leaked ' . $field);
        }
        $this->assertSame('Труба RU', $env->prod->languageRows[1][91]['name']);
        $this->assertSame('', $env->prod->languageRows[1][91]['meta_keywords']);
        $this->assertArrayNotHasKey('meta_description', $env->prod->languageRows[1][91]);
        $this->assertSame(
            ['description' => '<p>Only explicit UK description</p>'],
            $env->prod->languageRows[1][17]
        );
        $this->assertSame(777, $currentLanguageId);
    }

    public function testUpdatePreservesAbsentFieldsClearsExplicitEmptyAndIgnoresTopLevelTexts(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $this->putProductRows([$fixture['product_row']]);
        $this->runApply($env, $this->v3Manifest($fixture));
        $before = $env->prod->languageRows[1];

        $row = $fixture['product_row'];
        $row['hash'] = str_repeat('c', 64);
        $row['data']['name'] = 'TOP LEVEL UPDATE POISON';
        $row['data']['description_html'] = 'TOP LEVEL UPDATE POISON';
        $row['data']['translations'] = [
            ['language' => 'ru', 'name' => ''],
            ['language' => 'uk', 'seo_keywords' => ''],
        ];
        $this->putProductRows([$row]);

        [$status, $stats] = $this->runApply($env, $this->v3Manifest($fixture));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame('', $env->prod->languageRows[1][91]['name']);
        $this->assertSame($before[91]['description'], $env->prod->languageRows[1][91]['description']);
        $this->assertSame($before[17]['name'], $env->prod->languageRows[1][17]['name']);
        $this->assertSame('', $env->prod->languageRows[1][17]['meta_keywords']);
        $this->assertSame(777, $currentLanguageId);
        $map = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        $this->assertSame(str_repeat('c', 64), $map->applied_hash);
    }

    public function testPriceStockPreflightsV3ButNeverWritesProductTexts(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $this->putProductRows([$fixture['product_row']]);
        $this->runApply($env, $this->v3Manifest($fixture));
        $textRows = $env->prod->languageRows;
        $productMutations = $env->prod->mutations();

        $row = $fixture['product_row'];
        $row['hash'] = str_repeat('d', 64);
        $row['data']['translations'][0]['name'] = 'MUST NOT WRITE IN PRICE STOCK';
        $row['data']['variants'][0]['price']['amount'] = '999.99';
        $this->putProductRows([$row]);

        [$status, $stats] = $this->runApply($env, $this->v3Manifest($fixture, 'price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame($productMutations, $env->prod->mutations());
        $this->assertSame($textRows, $env->prod->languageRows);
        $this->assertSame('999.99', $env->var->rows[1]['price']);
        $this->assertSame(777, $currentLanguageId);
    }

    public function testInvalidSecondRowPreflightPreventsFullBindAndPriceStockMutation(): void
    {
        $fixture = $this->sharedFixture();
        foreach (['full', 'bind', 'price_stock'] as $scenario) {
            $currentLanguageId = 777;
            $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
            if ($scenario === 'bind') {
                $env->prod->rows[99] = ['id' => 99, 'external_id' => 'local-only', 'url' => 'local-only'];
            }
            if ($scenario === 'price_stock') {
                $env->prod->rows[1] = ['id' => 1, 'external_id' => '42', 'url' => 'truba-42'];
                $env->var->rows[1] = ['id' => 1, 'product_id' => 1, 'external_id' => '42', 'price' => '1.00'];
                $env->map->rows[1] = [
                    'id' => 1, 'entity_type' => 'product', 'external_id' => '42',
                    'local_id' => 1, 'applied_hash' => str_repeat('a', 64), 'image_state' => null,
                ];
                $env->map->rows[2] = [
                    'id' => 2, 'entity_type' => 'variant', 'external_id' => '42',
                    'local_id' => 1, 'applied_hash' => str_repeat('a', 64), 'image_state' => null,
                ];
            }
            $invalid = $fixture['product_row'];
            $invalid['external_id'] = '43';
            $invalid['hash'] = str_repeat('e', 64);
            $invalid['data']['source_identity']['id'] = '43';
            $invalid['data']['variants'][0]['external_id'] = '43';
            $invalid['data']['variants'][0]['source_identity']['id'] = '43';
            $invalid['data']['translations'][0]['token'] = 'forbidden';
            $this->putProductRows([$fixture['product_row'], $invalid]);

            $mapBefore = $env->map->rows;
            $productsBefore = $env->prod->rows;
            $variantsBefore = $env->var->rows;
            try {
                $this->runApply($env, $this->v3Manifest(
                    $fixture,
                    $scenario === 'price_stock' ? 'price_stock' : 'full'
                ));
                $this->fail('Invalid v3 second row must fail preflight for ' . $scenario);
            } catch (ManifestException $e) {
                $this->assertStringContainsString('token', $e->getMessage());
            }
            $this->assertSame($mapBefore, $env->map->rows, $scenario . ' map mutated before preflight');
            $this->assertSame($productsBefore, $env->prod->rows, $scenario . ' products mutated before preflight');
            $this->assertSame($variantsBefore, $env->var->rows, $scenario . ' variants mutated before preflight');
            $this->assertSame(777, $currentLanguageId);
        }
    }

    public function testManifestLanguageIntersectionAllowsUnusedLocalLocaleAndLeavesItUntouched(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $languages = $this->createMock(Languages::class);
        $localLanguages = $fixture['satellite_languages'];
        $localLanguages[] = ['id' => 23, 'href_lang' => 'en'];
        $languages->method('getAllLanguages')->willReturn(array_map(static function (array $language): object {
            return (object) ['id' => $language['id'], 'href_lang' => $language['href_lang']];
        }, $localLanguages));
        $languages->method('getLangId')->willReturnCallback(static function () use (&$currentLanguageId): int {
            return $currentLanguageId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use (&$currentLanguageId): void {
            $currentLanguageId = (int) $id;
        });
        $env = $this->buildEnv(['UAH' => 7], $languages);
        $env->prod->rows[1] = ['id' => 1, 'external_id' => '42', 'url' => 'truba-42'];
        $env->prod->languageRows[1][23] = ['name' => 'Keep EN', 'description' => 'Keep EN body'];
        $env->var->rows[1] = [
            'id' => 1,
            'product_id' => 1,
            'external_id' => '42',
            'sku' => 'SKU-42',
            'price' => '100.00',
            'stock' => 1,
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => '42',
            'local_id' => 1,
            'applied_hash' => str_repeat('0', 64),
            'image_state' => null,
        ]);
        $this->putProductRows([$fixture['product_row']]);

        [$status, $stats] = $this->runApply($env, $this->v3Manifest($fixture));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertSame(
            ['name' => 'Keep EN', 'description' => 'Keep EN body'],
            $env->prod->languageRows[1][23]
        );
        $this->assertSame(777, $currentLanguageId);
    }

    public function testV3BindUsesPinnedProductAndVariantSourceIdentityBeforeSkuFallback(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $env->prod->rows[42] = [
            'id' => 42,
            'external_id' => '',
            'url' => 'existing-product',
        ];
        $env->var->rows[42] = [
            'id' => 42,
            'product_id' => 42,
            'external_id' => '',
            'sku' => 'LOCAL-SKU-DIFFERS',
        ];
        $this->putProductRows([$fixture['product_row']]);

        [$status, $stats] = $this->runApply($env, $this->v3Manifest($fixture));

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(2, $stats->bound);
        $this->assertSame(0, $stats->conflicts);
        $this->assertSame(42, $env->map->findOne([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => '42',
        ])->local_id);
        $this->assertSame(42, $env->map->findOne([
            'entity_type' => Contract::ENTITY_VARIANT,
            'external_id' => '42',
        ])->local_id);
        $this->assertSame(777, $currentLanguageId);
    }

    /** @dataProvider createFailurePhases */
    public function testEveryCreateCheckpointFailureLeavesHashPending(
        string $phase,
        int $expectedProducts,
        bool $expectReservation
    ): void {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        if (in_array($phase, ['reserve', 'attach', 'finalize'], true)) {
            $env->map->returnFalseOnPhase = $phase;
        } elseif ($phase === 'product_add') {
            $env->prod->returnFalseOnAdd = true;
        } elseif ($phase === 'ru') {
            $env->prod->returnFalseOnLanguageId = 91;
        } elseif ($phase === 'variant') {
            $env->var->returnFalseOnAdd = true;
        }
        $this->putProductRows([$fixture['product_row']]);

        try {
            $this->runApply($env, $this->v3Manifest($fixture));
            $this->fail('Injected ' . $phase . ' failure must abort apply');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $map = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        if ($expectReservation) {
            $this->assertNotFalse($map);
            $this->assertNull($map->applied_hash, $phase . ' exposed desired product hash');
        } else {
            $this->assertFalse($map);
        }
        $this->assertCount($expectedProducts, $env->prod->rows);
        $this->assertSame(777, $currentLanguageId);
    }

    /** @return array<string, array{string,int,bool}> */
    public function createFailurePhases(): array
    {
        return [
            'reserve false' => ['reserve', 0, false],
            'product add false' => ['product_add', 0, true],
            'attach false' => ['attach', 1, true],
            'RU false' => ['ru', 1, true],
            'variant false' => ['variant', 1, true],
            'finalize false' => ['finalize', 1, true],
        ];
    }

    public function testCrashAfterPersistedAddRecoversExactCandidateWithoutDuplicate(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $env->prod->throwAfterPersistedAdd = true;
        $this->putProductRows([$fixture['product_row']]);

        try {
            $this->runApply($env, $this->v3Manifest($fixture));
            $this->fail('Injected post-add crash must abort apply');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected crash after persisted product add', $e->getMessage());
        }
        $pending = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        $this->assertNull($pending->local_id);
        $this->assertCount(1, $env->prod->rows);

        [$status] = $this->runApply($env, $this->v3Manifest($fixture));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertCount(1, $env->prod->rows);
        $this->assertCount(1, $env->prod->addCalls);
        $final = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        $this->assertSame(1, (int) $final->local_id);
        $this->assertSame($fixture['product_row']['hash'], $final->applied_hash);
    }

    public function testPendingReservationRejectsAmbiguousExactProductCandidates(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $env->map->rows[1] = [
            'id' => 1, 'entity_type' => 'product', 'external_id' => '42',
            'local_id' => null, 'applied_hash' => null, 'image_state' => null,
        ];
        $env->prod->rows = [
            1 => ['id' => 1, 'external_id' => '42', 'url' => 'truba-42'],
            2 => ['id' => 2, 'external_id' => '42', 'url' => 'truba-42-copy'],
        ];
        $this->putProductRows([$fixture['product_row']]);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('ambiguous external_id');
        $this->runApply($env, $this->v3Manifest($fixture));
    }

    public function testUpdateFailureKeepsOldHashAndRetryUsesSameProduct(): void
    {
        $fixture = $this->sharedFixture();
        $currentLanguageId = 777;
        $env = $this->buildEnv(['UAH' => 7], $this->languages($fixture, $currentLanguageId));
        $this->putProductRows([$fixture['product_row']]);
        $this->runApply($env, $this->v3Manifest($fixture));
        $oldHash = $fixture['product_row']['hash'];

        $updated = $fixture['product_row'];
        $updated['hash'] = str_repeat('f', 64);
        $updated['data']['translations'][1]['name'] = 'Оновлена UK';
        $env->prod->returnFalseOnLanguageId = 17;
        $this->putProductRows([$updated]);

        try {
            $this->runApply($env, $this->v3Manifest($fixture));
            $this->fail('Injected UK update failure must abort apply');
        } catch (CoreSyncException $e) {
            $this->assertStringContainsString('update failed', $e->getMessage());
        }
        $pending = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        $this->assertSame($oldHash, $pending->applied_hash);
        $this->assertCount(1, $env->prod->rows);
        $this->assertSame(777, $currentLanguageId);

        [$status] = $this->runApply($env, $this->v3Manifest($fixture));
        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertCount(1, $env->prod->rows);
        $final = $env->map->findOne(['entity_type' => 'product', 'external_id' => '42']);
        $this->assertSame(str_repeat('f', 64), $final->applied_hash);
    }

    /** @dataProvider checkedDatabaseFailureLocations */
    public function testCheckedWriteDetectsActualEntityAndLanguagesDatabaseFalse(
        bool $entityQuerySucceeds,
        bool $languageQuerySucceeds
    ): void {
        $languages = (new \ReflectionClass(Languages::class))->newInstanceWithoutConstructor();
        $languageDb = new CheckedWriteDatabaseProbe($languageQuerySucceeds);
        $languageDbProperty = new \ReflectionProperty(Languages::class, 'db');
        $languageDbProperty->setAccessible(true);
        $languageDbProperty->setValue($languages, $languageDb);
        $entity = new CheckedWriteEntityProbe(
            new CheckedWriteDatabaseProbe($entityQuerySucceeds),
            $languages
        );
        $gateway = new MapGateway(new MapEntityStub());

        $this->expectException(CoreSyncException::class);
        $gateway->updateEntityChecked($entity, 1, ['name' => 'already desired'], 'probe');
    }

    /** @return array<string, array{bool,bool}> */
    public function checkedDatabaseFailureLocations(): array
    {
        return [
            'entity db false while readback would match' => [false, true],
            'languages db false while readback would match' => [true, false],
        ];
    }

    public function testPendingRecoveryLookupDetectsActualEntityDatabaseFalse(): void
    {
        $map = new class {
            public function findEntityChecked($entity, array $filter): array
            {
                throw new CoreSyncException('injected checked product recovery lookup failure');
            }
        };
        $gateway = new MapGateway($map);

        $this->expectException(CoreSyncException::class);
        $this->expectExceptionMessage('checked product recovery lookup failure');
        $gateway->findEntityRows(new \stdClass(), ['external_id' => '42']);
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

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function v3Manifest(array $fixture, string $mode = 'full'): array
    {
        return $this->productsManifest($mode, [
            'schema_version' => $fixture['schema_version'],
            'language' => $fixture['channel_language'],
            'product_content_languages' => $fixture['product_content_languages'],
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function putProductRows(array $rows): void
    {
        $this->gz('products-0001.ndjson.gz', array_map(static function (array $row): string {
            return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $rows));
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
}

/** Real Entity marker used to exercise MapGateway's reflection-based checked write seam. */
final class CheckedWriteEntityProbe extends Entity
{
    /** @param object $database */
    public function __construct($database, Languages $languages)
    {
        $this->db = $database;
        $this->lang = $languages;
    }

    public function update($ids, $object)
    {
        $this->db->query('entity update');
        $property = new \ReflectionProperty(Languages::class, 'db');
        $property->setAccessible(true);
        $property->getValue($this->lang)->query('language update');

        return true;
    }

    public function findOne(array $filter = [])
    {
        return (object) ['id' => 1, 'name' => 'already desired'];
    }

}

final class CheckedWriteDatabaseProbe
{
    /** @var bool */
    private $succeeds;

    public function __construct(bool $succeeds)
    {
        $this->succeeds = $succeeds;
    }

    /** @param mixed $query */
    public function query($query, $debug = false): bool
    {
        return $this->succeeds;
    }
}
