<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Entity\Entity;
use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Bind-фаза (M3 §1): пустая карта + непустой каталог → связывание по SKU варианта, каталог НЕ пишется.
 * Конфликты (дубль SKU / варианты разъехались / SKU не найден) в счётчики. После bind: full обновляет
 * связанное, price_stock — только связанное.
 */
class BindTest extends TestCase
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

    /** Заселить «живой» каталог Okay: товар 100 с вариантами SKU-A/SKU-B (без external_id ядра). */
    private function seedCatalog(object $env): void
    {
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 5];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 100, 'sku' => 'SKU-B', 'external_id' => '', 'stock' => 3];
    }

    private function catalogMutations(object $env): int
    {
        return $env->prod->mutations() + $env->var->mutations() + $env->cat->mutations()
            + $env->brand->mutations() + $env->feat->mutations()
            + count($env->fv->addCalls) + $env->redir->mutations()
            + count($env->img->addCalls) + count($env->img->updateCalls) + count($env->img->deleteCalls);
    }

    public function testBindFillsMapBySkuWithZeroCatalogMutations(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status, 'пустая карта + непустой каталог → bind');
        // Каталог НЕ мутирован (нетавтологичная считалка реальных вызовов записи).
        $this->assertSame(0, $this->catalogMutations($env), 'bind не пишет в каталог');

        // Карта заполнена: product '1' → 100, variants v1→1, v2→2, applied_hash=NULL.
        $productRow = $env->map->findOne(['entity_type' => 'product', 'external_id' => '1']);
        $this->assertNotFalse($productRow);
        $this->assertSame(100, (int) $productRow->local_id);
        $this->assertNull($productRow->applied_hash, 'bind: applied_hash=NULL (связано, не применялось)');
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1'])->local_id);
        $this->assertSame(2, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v2'])->local_id);

        $this->assertSame(3, $stats->bound, '1 товар + 2 варианта связаны');
        $this->assertSame(0, $stats->unmatched);
        $this->assertSame(0, $stats->conflicts);
    }

    public function testExactIdentityBindsEmptyAndDuplicateSkuAtomicallyThenFullUpdatesWithoutDuplicates(): void
    {
        $env = $this->buildIdentityEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'legacy', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => '', 'external_id' => '', 'stock' => 5];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 100, 'sku' => 'DUP', 'external_id' => '', 'stock' => 3];
        $env->var->rows[3] = ['id' => 3, 'product_id' => 200, 'sku' => 'DUP', 'external_id' => '', 'stock' => 9];
        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('core-product', '100', [
                $this->identifiedVariant('core-v1', '', '1'),
                $this->identifiedVariant('core-v2', 'DUP', '2'),
            ]),
        ]);

        [$bindStatus, $bindStats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $bindStatus);
        $this->assertSame(3, $bindStats->bound);
        $this->assertSame(0, $bindStats->conflicts);
        $this->assertSame(0, $this->catalogMutations($env));
        $this->assertSame(100, (int) $env->map->findOne(['entity_type' => 'product', 'external_id' => 'core-product'])->local_id);
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'core-v1'])->local_id);
        $this->assertSame(2, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'core-v2'])->local_id);

        [$fullStatus] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_APPLIED, $fullStatus);
        $this->assertSame([], $env->prod->addCalls, 'exactly bound legacy product is updated, not duplicated');
        $this->assertSame([], $env->var->addCalls, 'exactly bound legacy variants are updated, not duplicated');
        $this->assertTrue($this->hasUpdateFor($env->var, 1));
        $this->assertTrue($this->hasUpdateFor($env->var, 2));
    }

    public function testExactIdentityAcceptsCanonicalProducerSortedObjectKeyOrder(): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $productIdentity = [
            'entity' => 'product',
            'id' => '100',
            'instance' => 'artaz',
            'namespace' => 'okay',
        ];
        $variant = $this->variant('v1', 'SKU-A', '10.00', 1);
        $variant['source_identity'] = [
            'entity' => 'variant',
            'id' => '1',
            'instance' => 'artaz',
            'namespace' => 'okay',
        ];
        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('1', '100', [$variant], $productIdentity),
        ]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(2, $stats->bound);
        $this->assertSame(0, $stats->conflicts);
        $this->assertSame(100, (int) $env->map->findOne(['entity_type' => 'product', 'external_id' => '1'])->local_id);
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1'])->local_id);
    }

    /** @dataProvider invalidIdentityCases */
    public function testInvalidIdentifiedRowConflictsWithoutAnyMapOrSkuFallback(string $case): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $productIdentity = $this->identity('product', '100');
        $variants = [
            $this->identifiedVariant('v1', 'SKU-A', '1'),
            $this->identifiedVariant('v2', 'SKU-B', '2'),
        ];

        if ($case === 'wrong_instance') {
            $productIdentity['instance'] = 'other';
        } elseif ($case === 'wrong_namespace') {
            $productIdentity['namespace'] = 'okaysat';
        } elseif ($case === 'wrong_entity') {
            $productIdentity['entity'] = 'variant';
        } elseif ($case === 'malformed_id') {
            $productIdentity['id'] = '100x';
        } elseif ($case === 'non_positive_id') {
            $productIdentity['id'] = '0';
        } elseif ($case === 'missing_identity_key') {
            unset($productIdentity['namespace']);
        } elseif ($case === 'extra_identity_key') {
            $productIdentity['extra'] = 'forbidden';
        } elseif ($case === 'missing_variant_identity') {
            unset($variants[1]['source_identity']);
        } elseif ($case === 'variant_wrong_product') {
            $env->prod->rows[200] = ['id' => 200, 'url' => 'other', 'external_id' => ''];
            $env->var->rows[2]['product_id'] = 200;
            // SKU intentionally points to product 100 while identity points to local variant 2
            // under product 200. Any SKU fallback would therefore create a false successful bind.
            $variants = [$this->identifiedVariant('v2', 'SKU-A', '2')];
        }

        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('1', '100', $variants, $productIdentity),
        ]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertGreaterThan(0, $stats->conflicts, $case . ' must fail closed');
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_PRODUCT));
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_VARIANT));
        $this->assertSame(0, $this->catalogMutations($env));
    }

    /** @return array<string, array{0:string}> */
    public function invalidIdentityCases(): array
    {
        return [
            'wrong instance' => ['wrong_instance'],
            'wrong namespace' => ['wrong_namespace'],
            'wrong entity' => ['wrong_entity'],
            'malformed id' => ['malformed_id'],
            'non-positive id' => ['non_positive_id'],
            'missing identity key' => ['missing_identity_key'],
            'extra identity key' => ['extra_identity_key'],
            'missing second variant identity' => ['missing_variant_identity'],
            'second variant belongs to another product' => ['variant_wrong_product'],
        ];
    }

    public function testNullProductIdentityKeepsLegacySkuBind(): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $line = json_decode($this->productLine('1', 'phone', 'h1', [
            $this->variant('v1', 'SKU-A', '1500.00', 5),
        ]), true);
        $line['data']['source_identity'] = null;
        $this->gz('products-0001.ndjson.gz', [(string) json_encode($line)]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(2, $stats->bound);
        $this->assertSame(100, (int) $env->map->findOne(['entity_type' => 'product', 'external_id' => '1'])->local_id);
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1'])->local_id);
    }

    public function testV1RowCannotActivateExactIdentityAndKeepsLegacySkuSemantics(): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $env->prod->rows[999] = ['id' => 999, 'url' => 'identity-target', 'external_id' => ''];
        $env->var->rows[9] = ['id' => 9, 'product_id' => 999, 'sku' => 'OTHER', 'external_id' => '', 'stock' => 1];
        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('1', '999', [
                $this->identifiedVariant('v1', 'SKU-A', '9'),
            ]),
        ]);

        [$status, $stats] = $this->runV1Apply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(2, $stats->bound);
        $this->assertSame(100, (int) $env->map->findOne(['entity_type' => 'product', 'external_id' => '1'])->local_id);
        $this->assertSame(1, (int) $env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1'])->local_id);
    }

    public function testMalformedNonNullProductIdentityConflictsWithoutSkuFallback(): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $line = json_decode($this->productLine('1', 'phone', 'h1', [
            $this->variant('v1', 'SKU-A', '1500.00', 5),
        ]), true);
        $line['data']['source_identity'] = 'okay:artaz:product:100';
        $this->gz('products-0001.ndjson.gz', [(string) json_encode($line)]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_PRODUCT));
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_VARIANT));
    }

    public function testExactIdentityRejectsExistingLocalMapOwnershipWithoutPartialRows(): void
    {
        $env = $this->buildIdentityEnv();
        $this->seedCatalog($env);
        $env->map->add([
            'entity_type' => Contract::ENTITY_VARIANT,
            'external_id' => 'other-core-variant',
            'local_id' => 2,
            'applied_hash' => null,
            'image_state' => null,
        ]);
        $env->map->add([
            'entity_type' => Contract::ENTITY_BIND_MARKER,
            'external_id' => Contract::BIND_MARKER_EXTERNAL_ID,
            'local_id' => null,
            'applied_hash' => Contract::BIND_MARKER_ACTIVE,
            'image_state' => null,
        ]);
        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('1', '100', [
                $this->identifiedVariant('v1', 'SKU-A', '1'),
                $this->identifiedVariant('v2', 'SKU-B', '2'),
            ]),
        ]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_PRODUCT));
        $this->assertFalse($env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1']));
        $this->assertFalse($env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v2']));
        $this->assertNotFalse($env->map->findOne([
            'entity_type' => 'variant',
            'external_id' => 'other-core-variant',
        ]));
    }

    /** @return array<string, string> */
    private function identity(string $entity, string $id): array
    {
        return ['namespace' => 'okay', 'instance' => 'artaz', 'entity' => $entity, 'id' => $id];
    }

    /** @return array<string, mixed> */
    private function identifiedVariant(string $externalId, string $sku, string $localId): array
    {
        $variant = $this->variant($externalId, $sku, '10.00', 1);
        $variant['source_identity'] = $this->identity('variant', $localId);

        return $variant;
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<string, string>|null $productIdentity
     */
    private function identifiedProductLine(
        string $externalId,
        string $localId,
        array $variants,
        ?array $productIdentity = null
    ): string {
        $line = json_decode($this->productLine($externalId, 'phone', 'h1', $variants), true);
        $line['data']['source_identity'] = $productIdentity ?? $this->identity('product', $localId);

        return (string) json_encode($line);
    }

    /** @return array{0:string,1:ApplyStats} */
    private function runIdentityApply(object $env): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $this->productsManifest(),
            $this->stagingDir,
            new InMemoryCheckpointStore(),
            static function (): bool {
                return false;
            },
            $stats,
            2,
            'artaz'
        );

        return [$status, $stats];
    }

    private function buildIdentityEnv(): object
    {
        return $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
    }

    /** @return array{0:string,1:ApplyStats} */
    private function runV1Apply(object $env): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $this->productsManifest(),
            $this->stagingDir,
            new InMemoryCheckpointStore(),
            static function (): bool {
                return false;
            },
            $stats,
            1,
            'artaz'
        );

        return [$status, $stats];
    }

    public function testUnmatchedSkuCounted(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v9', 'SKU-NOPE', '10.00', 1)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(0, $stats->bound);
        $this->assertSame(1, $stats->unmatched, 'SKU не найден в каталоге');
        $this->assertContains('SKU-NOPE', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'несвязанный товар не в карте');
    }

    /**
     * ИНВАРИАНТ СХОДИМОСТИ (D-SAT-BIND-LOOP-NEVER-APPLIES). Витрина с полностью ЧУЖИМ каталогом
     * (0 совпадений SKU) — типовой сценарий подключения нового клиента. Холостой bind = легальный
     * результат («у витрины нет ни одного нашего SKU»), а не «bind не делали»: он обязан ОТМЕТИТЬСЯ
     * выполненным, чтобы следующий прогон ушёл в full и витрина получила каталог ядра как новый.
     * Без отметки shouldBind() (карта пуста + каталог непуст) вечно истинен → bind→bound→bind→…
     * навсегда, при этом всё выглядит успешным (status=bound, ошибок нет).
     */
    public function testIdleBindConvergesSecondRunAppliesCatalog(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env); // чужой каталог: SKU-A/SKU-B — ни один SKU снапшота не совпадёт
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v9', 'SKU-NOPE', '10.00', 1)]),
        ]);

        // Прогон 1: bind вхолостую — 0 совпадений, карта product/variant осталась пустой.
        [$status1, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_BOUND, $status1);
        $this->assertSame(0, $stats1->bound, '0 совпадений — карта не наполнилась');
        $this->assertSame(0, $this->catalogMutations($env), 'bind не пишет в каталог');

        // Прогон 2 (та же версия, свежий чекпоинт-стор = новый job): bind уже отработал → full.
        [$status2, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status2, 'холостой bind не зацикливается: второй прогон применяет каталог');
        $this->assertSame(1, $stats2->upserted, 'товар ядра создан на витрине');
        // Нетавтологично: каталог реально записан (товар + вариант созданы), а не только статус сменился.
        $this->assertSame(1, count($env->prod->addCalls), 'товар ядра добавлен в каталог витрины');
        $this->assertSame(1, count($env->var->addCalls), 'вариант ядра добавлен в каталог витрины');
        $this->assertNotFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'товар попал в карту владения');
    }

    /**
     * Служебные метки bind (entity_type=bind_marker) НЕ считаются сущностями карты: они вне
     * Contract::ENTITY_TYPES, поэтому не искажают счётчики product/variant (и absent/FK-разрешение,
     * которые итерируют только эти типы). Здесь холостой bind взвёл обе метки, а счётчики всех
     * ENTITY_TYPES остались нулевыми. (Interrupt-safety самого bind — прерванный bind добивается
     * bind'ом, а не уходит в full — проверяет testCancelMidBindThenResumeFinishesBindNoDuplicates.)
     */
    public function testIdleBindDoneMarkerLivesOutsideEntityCounts(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v9', 'SKU-NOPE', '10.00', 1)]),
        ]);

        $this->runApply($env, $this->productsManifest());

        // Служебные строки карты не искажают счётчики сущностей (изоляция от product/variant/absent).
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_PRODUCT));
        $this->assertSame(0, $this->countMapRows($env, Contract::ENTITY_VARIANT));
        foreach (Contract::ENTITY_TYPES as $type) {
            $this->assertSame(0, $this->countMapRows($env, $type), 'служебная метка не считается сущностью карты: ' . $type);
        }
    }

    /**
     * ПЕРВИЧНЫЙ СЦЕНАРИЙ ОПЕРАТОРА (D-SAT-BIND-REBIND-CEREMONY, acceptance §1). Холостой bind
     * (связано 0, метка completed взведена) → оператор проставляет SKU на витрине → «Связать заново»
     * (rebind-сброс гасит completed; строк product/variant нет — связано было 0) → следующий прогон
     * снова уходит в BIND и связывает по SKU. По образцу testIdleBindConvergesSecondRunAppliesCatalog,
     * но со сбросом вместо конвергенции в full.
     *
     * KILL-ПРОБА: если clearBindCompleted не гасит метку, прогон 2 уйдёт в full → status APPLIED, а не
     * BOUND → тест красный (rebind обязателен, чтобы вернуться в bind).
     */
    public function testRebindAfterIdleBindReBindsBySku(): void
    {
        $env = $this->buildEnv();
        // Каталог витрины БЕЗ совпадающего SKU на момент первого bind.
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-OLD', 'external_id' => '', 'stock' => 5];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '1500.00', 5)]),
        ]);

        // Прогон 1: холостой bind (SKU-A нет на витрине) → 0 связано, completed взведён.
        [$status1, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_BOUND, $status1);
        $this->assertSame(0, $stats1->bound, '0 совпадений');

        // Оператор проставил SKU-A на витрине (тот самый вариант id=1).
        $env->var->rows[1]['sku'] = 'SKU-A';

        // «Связать заново»: гасим метку completed (в проде — CoreSyncMapEntity::resetForRebind; строк
        // product/variant нет, т.к. связано было 0, поэтому здесь достаточно снять completed).
        (new \Okay\Modules\Format\CoreSync\Core\Apply\MapGateway($env->map))->clearBindCompleted();

        // Прогон 2: карта product/variant пуста + каталог непуст + метки сняты → снова BIND, SKU совпал.
        [$status2, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status2, 'после «Связать заново» прогон снова уходит в bind');
        $this->assertSame(2, $stats2->bound, 'товар + проставленный оператором вариант связаны');
        $this->assertSame(0, $this->catalogMutations($env), 'bind не пишет каталог');
        $this->assertNotFalse($env->map->findOne(['entity_type' => 'variant', 'external_id' => 'v1']), 'вариант связан в карте');
    }

    public function testDuplicateSkuIsConflict(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        // Дубль SKU в каталоге (два варианта с одинаковым SKU-DUP).
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-DUP', 'external_id' => '', 'stock' => 1];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 101, 'sku' => 'SKU-DUP', 'external_id' => '', 'stock' => 1];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-DUP', '10.00', 1)]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->bound);
        $this->assertSame(1, $stats->conflicts, 'дубль SKU → конфликт');
        $this->assertNotEmpty($stats->conflictSamples);
    }

    public function testVariantsSpreadAcrossProductsIsConflict(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'p100', 'external_id' => ''];
        $env->prod->rows[200] = ['id' => 200, 'url' => 'p200', 'external_id' => ''];
        // SKU-A принадлежит товару 100, SKU-B — товару 200 (варианты одного снапшот-товара разъехались).
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 1];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 200, 'sku' => 'SKU-B', 'external_id' => '', 'stock' => 1];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '10.00', 1),
                $this->variant('v2', 'SKU-B', '20.00', 1),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->bound, 'разъехавшиеся варианты не связываются');
        $this->assertGreaterThanOrEqual(1, $stats->conflicts);
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']));
    }

    public function testAfterBindFullUpdatesLinkedAndCreatesUnlinked(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        // 1) bind.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        [$bindStatus] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_BOUND, $bindStatus);
        $varAddsAfterBind = count($env->var->addCalls);

        // 2) full той же версии: карта непуста → full. Связанный товар обновляется, несвязанный — создаётся.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1499.00', 4),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
            $this->productLine('2', 'tablet', 'h2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);
        [$fullStatus, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $fullStatus);
        // Связанный товар 1 обновлён (bind оставил applied_hash=NULL).
        $this->assertSame(1, $stats->updated, 'связанный товар обновлён');
        // Несвязанный товар 2 создан.
        $this->assertSame(1, $stats->upserted, 'несвязанный товар создан');
        // Связанные варианты обновлены ЧЕРЕЗ variant-карту (id 1/2), а не пере-созданы.
        $this->assertTrue($this->hasUpdateFor($env->var, 1), 'связанный вариант v1 (id1) обновлён');
        $this->assertTrue($this->hasUpdateFor($env->var, 2), 'связанный вариант v2 (id2) обновлён');
        // Новый вариант v3 несвязанного товара создан (ровно один новый add).
        $this->assertSame($varAddsAfterBind + 1, count($env->var->addCalls), 'создан только новый вариант v3');
    }

    public function testAfterBindPriceStockUpdatesOnlyLinkedNotCreatesUnlinked(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        // bind.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $varAddsAfterBind = count($env->var->addCalls);

        // price_stock: связанное обновляется, несвязанный товар 2 — НЕ создаётся.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1400.00', 2),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
            $this->productLine('2', 'tablet', 'h2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest('price_stock'));

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->skippedNewProducts, 'несвязанный товар 2 не создан (счётчик)');
        $this->assertSame($varAddsAfterBind, count($env->var->addCalls), 'price_stock ничего не создаёт');
        $this->assertGreaterThanOrEqual(1, $stats->updated, 'связанный вариант обновлён');
    }

    /** Двухфайловый bind: товар 1 (SKU-A/B) в products-0001, товар 2 (SKU-C) в products-0002. */
    private function seedTwoFileCatalog(object $env): void
    {
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->prod->rows[200] = ['id' => 200, 'url' => 'tablet', 'external_id' => ''];
        $env->var->rows[1] = ['id' => 1, 'product_id' => 100, 'sku' => 'SKU-A', 'external_id' => '', 'stock' => 5];
        $env->var->rows[2] = ['id' => 2, 'product_id' => 100, 'sku' => 'SKU-B', 'external_id' => '', 'stock' => 3];
        $env->var->rows[3] = ['id' => 3, 'product_id' => 200, 'sku' => 'SKU-C', 'external_id' => '', 'stock' => 9];
    }

    private function writeTwoBindFiles(): void
    {
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        $this->gz('products-0002.ndjson.gz', [
            $this->productLine('2', 'tablet', 'h2', [$this->variant('v3', 'SKU-C', '2000.00', 9)]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoFileManifest(): array
    {
        return $this->productsManifest('full', [
            'files' => [
                ['name' => 'products-0001.ndjson.gz'],
                ['name' => 'products-0002.ndjson.gz'],
            ],
        ]);
    }

    private function countMapRows(object $env, string $type): int
    {
        return count($env->map->find(['entity_type' => $type]));
    }

    /**
     * RISK(v) M3 / §0.1 (в): отмена ПОСРЕДИ bind (между файлами) → resume ДОБИВАЕТ bind (не флипает в
     * full), карта полна, 0 мутаций каталога, дублей нет. Нетавтологичная дубль-детекция — по счёту
     * строк карты (2 товара, 3 варианта — не удвоено) и по catalogMutations()==0 (full создал бы
     * несвязанный товар 2 → prod->addCalls>0). Общий чекпоинт-стор: resume пропускает файл 1.
     */
    public function testCancelMidBindThenResumeFinishesBindNoDuplicates(): void
    {
        $env = $this->buildEnv();
        $this->seedTwoFileCatalog($env);
        $this->writeTwoBindFiles();

        // Отмена срабатывает ПЕРЕД вторым файлом (после того, как первый связан и зачекпоинчен).
        $checkpoints = new \Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore();
        $calls = 0;
        $cancel = static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2; // 1-я проверка (перед файлом 1) — false, 2-я (перед файлом 2) — true
        };
        [$status1, $stats1] = $this->runApplyWithCancel($env, $this->twoFileManifest(), $cancel, $checkpoints);

        $inProgressFilter = ['entity_type' => Contract::ENTITY_BIND_MARKER, 'external_id' => Contract::BIND_MARKER_EXTERNAL_ID];
        $this->assertSame(Contract::STATUS_CANCELLED, $status1, 'bind прерван между файлами');
        $this->assertTrue($env->map->findOne($inProgressFilter) !== false, 'метка bind взведена');
        $this->assertSame('active', (string) $env->map->findOne($inProgressFilter)->applied_hash);
        $this->assertSame(3, $stats1->bound, 'файл 1: товар 1 + 2 варианта связаны');
        $this->assertSame(1, $this->countMapRows($env, Contract::ENTITY_PRODUCT), 'в карте только товар 1');
        $this->assertSame(0, $this->catalogMutations($env), 'bind не пишет в каталог');

        // Resume: тот же чекпоинт-стор. shouldBind должен вернуть true по МЕТКЕ (карта уже непуста!).
        [$status2, $stats2] = $this->runApply($env, $this->twoFileManifest(), $checkpoints);

        $this->assertSame(Contract::STATUS_BOUND, $status2, 'resume добил bind (не флипнул в full)');
        $this->assertSame(0, $this->catalogMutations($env), 'resume не тронул каталог (bind, не full)');
        $this->assertSame(0, count($env->prod->addCalls), 'ни одного нового товара не создано (нет дублей)');
        // Дубль-детекция по счёту строк карты: ровно 2 товара + 3 варианта, не удвоено.
        $this->assertSame(2, $this->countMapRows($env, Contract::ENTITY_PRODUCT), 'товар 2 связан, товар 1 не задублирован');
        $this->assertSame(3, $this->countMapRows($env, Contract::ENTITY_VARIANT), 'все 3 варианта связаны без дублей');
        $this->assertSame(2, $stats2->bound, 'resume связал только файл 2 (товар 2 + вариант v3 = 2 bound)');
        // Метка снята → следующий прогон уйдёт в full/price_stock. Фильтруем по in_progress явно:
        // после завершённого bind строк bind_marker две (in_progress + completed).
        $this->assertNull($env->map->findOne($inProgressFilter)->applied_hash, 'метка снята');
    }

    /**
     * §0.1 (а): crash ПОСРЕДИ файла (чекпоинт не сохранился) → resume переобрабатывает тот же файл;
     * recordBind идемпотентен → дублей строк карты нет, исключения нет. Fresh чекпоинт-стор на resume
     * имитирует потерю чекпоинта; метка bind (в stateful-карте) удерживает bind-режим.
     */
    public function testResumeAfterLostCheckpointReprocessesFileIdempotently(): void
    {
        $env = $this->buildEnv();
        $this->seedTwoFileCatalog($env);
        $this->writeTwoBindFiles();

        // Прогон 1: прерван перед файлом 2, файл 1 связан. Чекпоинт-стор #1 «потерян» (crash).
        $calls = 0;
        $cancel = static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        };
        $this->runApplyWithCancel($env, $this->twoFileManifest(), $cancel, new \Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore());
        $this->assertSame(1, $this->countMapRows($env, Contract::ENTITY_PRODUCT));

        // Resume со СВЕЖИМ чекпоинт-стором → файл 1 переобрабатывается (product 1 уже в карте).
        [$status, ] = $this->runApply($env, $this->twoFileManifest(), new \Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore());

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(0, $this->catalogMutations($env), 'переобработка файла 1 не тронула каталог');
        // Ключевая проверка: НЕТ дублей строк карты, несмотря на повторную обработку файла 1.
        $this->assertSame(2, $this->countMapRows($env, Contract::ENTITY_PRODUCT), 'товар 1 не задублирован при переобработке');
        $this->assertSame(3, $this->countMapRows($env, Contract::ENTITY_VARIANT), 'варианты не задублированы');
    }

    /**
     * ЗАМОК ПОРЯДКА (уборка D-SATBIND-MINOR-HYGIENE §1). В конце bind completed взводится СТРОГО ДО
     * снятия in_progress (crash между шагами при обратном порядке ведёт к петле при «связано 0» либо
     * к дублям каталога при «связано ≥1» — см. комментарий в Applier::runBind). Проверяем по журналу
     * записей карты: запись «completed → active» предшествует записи «in_progress → null».
     *
     * KILL-ПРОБА: перестановка строк markBindCompleted()/clearBindInProgress() в Applier::runBind
     * переставляет эти записи местами → assertLessThan краснеет.
     */
    public function testBindCompletedMarkedBeforeInProgressCleared(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '1500.00', 5)]),
        ]);

        $this->runApply($env, $this->productsManifest());

        $completedIdx = null;
        $clearedIdx = null;
        foreach ($env->map->writeLog as $i => $entry) {
            if ($entry['entity_type'] === Contract::ENTITY_BIND_MARKER
                && $entry['external_id'] === Contract::BIND_MARKER_DONE_EXTERNAL_ID
                && $entry['applied_hash'] === Contract::BIND_MARKER_ACTIVE) {
                $completedIdx = $i;
            }
            if ($entry['entity_type'] === Contract::ENTITY_BIND_MARKER
                && $entry['external_id'] === Contract::BIND_MARKER_EXTERNAL_ID
                && $entry['applied_hash'] === null) {
                $clearedIdx = $i;
            }
        }

        $this->assertNotNull($completedIdx, 'метка completed взведена');
        $this->assertNotNull($clearedIdx, 'метка in_progress снята');
        $this->assertLessThan($clearedIdx, $completedIdx, 'completed взводится ДО снятия in_progress');
    }

    private function hasUpdateFor($var, int $id): bool
    {
        foreach ($var->updateCalls as $call) {
            if ((int) $call[0] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Потеря product/variant-map при живом completed-маркере не означает «новый каталог»:
     * bind уже утверждал, что существующая витрина обследована. Продолжить через MAP_CREATE здесь
     * значит за минуты пере-создать тот же каталог дублями. Барьер обязан сработать до первой записи,
     * отдать строковую ошибку в штатный apply-порог и оставить оператору диагноз для rebind.
     */
    public function testCompletedBindWithMissingProductMapStopsBeforeCreatingDuplicate(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $env->map->add([
            'entity_type'  => Contract::ENTITY_BIND_MARKER,
            'external_id'  => Contract::BIND_MARKER_DONE_EXTERNAL_ID,
            'local_id'     => null,
            'applied_hash' => Contract::BIND_MARKER_ACTIVE,
            'image_state'  => null,
        ]);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);
        $catalogRowsBefore = count($env->prod->rows);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_FAILED, $status, 'ошибка строки попадает в штатный порог >10%');
        $this->assertCount($catalogRowsBefore, $env->prod->rows, 'существующий каталог не растёт');
        $this->assertCount(0, $env->prod->addCalls, 'MAP_CREATE не достигает записи товара');
        $this->assertCount(0, $env->var->addCalls, 'варианты недостижимы после отказа товара');
        $this->assertSame(1, $stats->errors);
        $this->assertContains(
            'product 1 (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $stats->conflictSamples
        );
        $this->assertFalse($env->map->findOne([
            'entity_type' => Contract::ENTITY_PRODUCT,
            'external_id' => '1',
        ]));
    }

    public function testCompletedBindWithMissingProductMapStopsOnExactExternalIdWithoutSkuMatch(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => '1'];
        $env->var->rows[1] = [
            'id' => 1,
            'product_id' => 100,
            'sku' => 'LEGACY-ONLY',
            'external_id' => '',
            'stock' => 5,
        ];
        $env->map->add([
            'entity_type'  => Contract::ENTITY_BIND_MARKER,
            'external_id'  => Contract::BIND_MARKER_DONE_EXTERNAL_ID,
            'local_id'     => null,
            'applied_hash' => Contract::BIND_MARKER_ACTIVE,
            'image_state'  => null,
        ]);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SNAPSHOT-ONLY', '1500.00', 5),
            ]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertCount(1, $env->prod->rows, 'exact external_id candidate is not duplicated');
        $this->assertCount(0, $env->prod->addCalls, 'external_id guard stops before product create');
        $this->assertCount(0, $env->var->addCalls, 'variant path is unreachable after product guard');
        $this->assertSame(1, $stats->errors);
        $this->assertContains(
            'product 1 (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $stats->conflictSamples
        );
    }

    public function testCompletedBindWithMissingProductMapStopsOnSchemaTwoSourceIdentityWithoutSkuMatch(): void
    {
        $env = $this->buildIdentityEnv();
        $env->prod->rows[100] = ['id' => 100, 'url' => 'phone', 'external_id' => ''];
        $env->var->rows[1] = [
            'id' => 1,
            'product_id' => 100,
            'sku' => 'LEGACY-ONLY',
            'external_id' => '',
            'stock' => 5,
        ];
        $env->map->add([
            'entity_type'  => Contract::ENTITY_BIND_MARKER,
            'external_id'  => Contract::BIND_MARKER_DONE_EXTERNAL_ID,
            'local_id'     => null,
            'applied_hash' => Contract::BIND_MARKER_ACTIVE,
            'image_state'  => null,
        ]);
        $this->gz('products-0001.ndjson.gz', [
            $this->identifiedProductLine('core-product', '100', [
                $this->identifiedVariant('core-variant', 'SNAPSHOT-ONLY', '1'),
            ]),
        ]);

        [$status, $stats] = $this->runIdentityApply($env);

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertCount(1, $env->prod->rows, 'source_identity candidate is not duplicated');
        $this->assertCount(0, $env->prod->addCalls, 'source_identity guard stops before product create');
        $this->assertCount(0, $env->var->addCalls, 'variant path is unreachable after product guard');
        $this->assertSame(1, $stats->errors);
        $this->assertContains(
            'product core-product (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $stats->conflictSamples
        );
    }

    public function testCompletedBindWithMissingVariantMapStopsBeforeCreatingDuplicateVariants(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $env->map->add([
            'entity_type'  => Contract::ENTITY_PRODUCT,
            'external_id'  => '1',
            'local_id'     => 100,
            'applied_hash' => null,
            'image_state'  => null,
        ]);
        $env->map->add([
            'entity_type'  => Contract::ENTITY_BIND_MARKER,
            'external_id'  => Contract::BIND_MARKER_DONE_EXTERNAL_ID,
            'local_id'     => null,
            'applied_hash' => Contract::BIND_MARKER_ACTIVE,
            'image_state'  => null,
        ]);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertCount(0, $env->var->addCalls, 'existing SKU variants are not recreated');
        $this->assertSame(2, $stats->errors);
        $this->assertContains(
            'variant v1 (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $stats->conflictSamples
        );
        $this->assertContains(
            'variant v2 (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $stats->conflictSamples
        );
    }

    public function testDatabaseReadFailureAbortsApplyBeforeCatalogCreate(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
            ]),
        ]);

        $select = (new AuraQueryFactory('mysql'))->newSelect();
        $select->cols(['csm.*'])->from('__format__coresync_map AS csm');
        $mapEntity = $this->getMockBuilder(CoreSyncMapEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSelect'])
            ->getMock();
        $mapEntity->method('getSelect')->willReturn($select);
        $db = new class {
            /** @var bool */
            public $resultsCalled = false;

            public function query($query, $debug = false): bool
            {
                return false;
            }

            public function results($field = null, $mapped = null): array
            {
                $this->resultsCalled = true;

                return [];
            }
        };
        $dbProperty = new \ReflectionProperty(Entity::class, 'db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($mapEntity, $db);
        $factory = new class($env, $mapEntity) {
            private $env;
            private $mapEntity;

            public function __construct(object $env, CoreSyncMapEntity $mapEntity)
            {
                $this->env = $env;
                $this->mapEntity = $mapEntity;
            }

            public function get(string $class)
            {
                switch ($class) {
                    case CoreSyncMapEntity::class: return $this->mapEntity;
                    case \Okay\Entities\CategoriesEntity::class: return $this->env->cat;
                    case \Okay\Entities\BrandsEntity::class: return $this->env->brand;
                    case \Okay\Entities\FeaturesEntity::class: return $this->env->feat;
                    case \Okay\Entities\FeaturesValuesEntity::class: return $this->env->fv;
                    case \Okay\Entities\ProductsEntity::class: return $this->env->prod;
                    case \Okay\Entities\VariantsEntity::class: return $this->env->var;
                    case \Okay\Entities\ImagesEntity::class: return $this->env->img;
                    case \Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity::class: return $this->env->csimg;
                    case Applier::REDIRECTS_ENTITY_CLASS: return $this->env->redir;
                }

                throw new \InvalidArgumentException('Unexpected entity: ' . $class);
            }
        };
        $factoryProperty = new \ReflectionProperty(Applier::class, 'entityFactory');
        $factoryProperty->setAccessible(true);
        $factoryProperty->setValue($env->applier, $factory);

        try {
            $this->runApply($env, $this->productsManifest());
            $this->fail('недоступная карта обязана остановить apply');
        } catch (CoreSyncException $e) {
            $this->assertStringContainsString('БД недоступна', $e->getMessage());
        }

        $this->assertFalse($db->resultsCalled, 'после query=false нельзя читать пустой/stale result');
        $this->assertCount(0, $env->prod->addCalls, 'товар не уходит в MAP_CREATE');
        $this->assertCount(0, $env->var->addCalls, 'вариант недостижим после отказа чтения карты');
    }

    /**
     * ЗАМОК ПОРЯДКА REBIND (REJECT-фикс приёмки, contract §2 — сходимость частичных состояний).
     * `resetForRebind` — два нетранзакционных SQL; crash МЕЖДУ ними оставляет промежуточное
     * состояние, безопасность которого зависит от ПОРЯДКА запросов (пробы приёмщика P1/P1'):
     *   • markers-first (прод-порядок → состояние P1'): метки сняты, карта владения жива →
     *     следующий автономный прогон (крон) = штатный FULL по живой карте → UPDATE, дублей нет;
     *   • DELETE-first (→ состояние P1): карта снесена, completed ещё active → shouldBind ложен
     *     по isBindCompleted → FULL с ПУСТОЙ картой → MAP_CREATE → каталог пере-создаётся
     *     ДУБЛЯМИ, и состояние не самолечится.
     *
     * Тест гоняет РЕАЛЬНЫЙ resetForRebind (настоящие Aura-запросы) против db-адаптера, который
     * применяет ПЕРВЫЙ запрос к in-memory карте и падает ДО второго (crash между запросами), затем
     * автономный прогон через полный путь apply(). Порядок берётся из прод-метода (адаптер узнаёт
     * запрос по форме SQL, не по позиции) — тест не пересказывает реализацию.
     *
     * KILL-ПРОБА: обратный свап двух db->query в resetForRebind (DELETE первым) → первый исполненный
     * запрос = DELETE → состояние P1 → прогон 2 создаёт товар заново → assertCount(0, addCalls)
     * красный. Прогнана при сдаче REJECT-фикса (см. handback).
     */
    public function testRebindCrashBetweenQueriesDoesNotDuplicateCatalog(): void
    {
        $env = $this->buildEnv();
        $this->seedCatalog($env);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [
                $this->variant('v1', 'SKU-A', '1500.00', 5),
                $this->variant('v2', 'SKU-B', '1600.00', 3),
            ]),
        ]);

        // Прогон 1: успешный bind, связано ≥1 — карта владения НЕпуста (в отличие от ветки
        // «связано 0», где DELETE — no-op и дефект порядка невидим), completed взведён.
        [$status1, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_BOUND, $status1);
        $this->assertSame(3, $stats1->bound, 'товар + 2 варианта связаны');
        $this->assertSame(1, $this->countMapRows($env, Contract::ENTITY_PRODUCT), 'владение товара записано');

        // «Связать заново» умирает МЕЖДУ двумя запросами: первый применён к карте, второй — нет.
        $entity = $this->rebindEntityAgainst($env->map, 1);
        try {
            $entity->resetForRebind();
            $this->fail('crash-адаптер обязан прервать resetForRebind после первого запроса');
        } catch (\RuntimeException $e) {
            $this->assertSame('crash между запросами rebind', $e->getMessage());
        }

        // Следующий АВТОНОМНЫЙ прогон (крон — оператор не участвует) из crash-состояния.
        [$status2] = $this->runApply($env, $this->productsManifest());

        // Сходимость: штатный full по живой карте — существующее ОБНОВЛЕНО, ничего не пере-создано.
        $this->assertSame(Contract::STATUS_APPLIED, $status2, 'crash-состояние маршрутизируется в штатный full');
        $this->assertCount(0, $env->prod->addCalls, 'каталог НЕ дублируется из crash-состояния rebind');
        $this->assertCount(0, $env->var->addCalls, 'варианты НЕ дублируются из crash-состояния rebind');
        $this->assertSame(1, $this->countMapRows($env, Contract::ENTITY_PRODUCT), 'владение не задвоено');
    }

    /**
     * Реальный CoreSyncMapEntity с настоящим Aura queryFactory и db-адаптером, транслирующим
     * запросы rebind в мутации in-memory карты $map; падает после $crashAfter исполненных запросов
     * (имитация смерти процесса между SQL).
     *
     * @param object $map MapEntityStub
     * @return \Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity
     */
    private function rebindEntityAgainst(object $map, int $crashAfter)
    {
        $db = new class($map, $crashAfter) {
            private $map;
            private $crashAfter;
            private $executed = 0;

            public function __construct($map, int $crashAfter)
            {
                $this->map = $map;
                $this->crashAfter = $crashAfter;
            }

            // resetForRebind теперь оборачивается в транзакцию ядра (D-OKAY-DB-NO-TX). Замок мерит
            // ХУДШИЙ случай — частичное состояние ПЕРЕЖИЛО (crash/частичный коммит), поэтому rollBack
            // здесь НЕ откатывает in-memory карту (no-op): defense-in-depth-порядок markers-first
            // обязан оставаться безопасным даже без защиты транзакции. Обратный свап db->query в
            // resetForRebind по-прежнему красит тест (kill-проба порядка сохранена).
            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            public function rollBack(): bool
            {
                return true;
            }

            /**
             * @param mixed $query Aura Update|Delete
             */
            public function query($query, $debug = false)
            {
                $statement = (string) $query->getStatement();
                $flat = [];
                $binds = $query->getBindValues();
                array_walk_recursive($binds, static function ($value) use (&$flat): void {
                    $flat[] = (string) $value;
                });

                if (stripos($statement, 'DELETE') !== false) {
                    // DELETE ... WHERE entity_type IN (...) → снос строк владения этих типов.
                    foreach ($this->map->rows as $id => $row) {
                        if (in_array((string) ($row['entity_type'] ?? ''), $flat, true)) {
                            unset($this->map->rows[$id]);
                        }
                    }
                } elseif (strpos($statement, 'applied_hash') !== false) {
                    // UPDATE ... SET applied_hash = NULL WHERE entity_type = bind_marker.
                    foreach ($this->map->rows as $id => $row) {
                        if (in_array((string) ($row['entity_type'] ?? ''), $flat, true)) {
                            $this->map->rows[$id]['applied_hash'] = null;
                        }
                    }
                } else {
                    throw new \InvalidArgumentException('Неожиданный запрос rebind: ' . $statement);
                }

                $this->executed++;
                if ($this->executed >= $this->crashAfter) {
                    throw new \RuntimeException('crash между запросами rebind');
                }

                return true;
            }
        };

        $queryFactory = new class {
            public function newUpdate()
            {
                return (new \Aura\SqlQuery\QueryFactory('mysql'))->newUpdate();
            }

            public function newDelete()
            {
                return (new \Aura\SqlQuery\QueryFactory('mysql'))->newDelete();
            }
        };

        $entityClass = \Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity::class;
        $entity = (new \ReflectionClass($entityClass))->newInstanceWithoutConstructor();
        foreach (['queryFactory' => $queryFactory, 'db' => $db] as $prop => $value) {
            $ref = new \ReflectionProperty(\Okay\Core\Entity\Entity::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($entity, $value);
        }

        return $entity;
    }
}
