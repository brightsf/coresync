<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Languages;
use Okay\Core\Translit;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Apply-путь характеристик связывает СУЩЕСТВУЮЩУЮ строку витрины по точному имени вместо создания
 * второй (живой инцидент artaz 2026-09-08: 1 043 характеристики ядра завелись заново, посадочные
 * фильтры на `in_filter` осиротели). Bind-фаза характеристик по-прежнему не касается — на витрине
 * с завершённым bind лечение обязано жить в apply.
 *
 * Инварианты витрины (`position`, `in_filter`, `url`, `external_id`) меряются по СОДЕРЖИМОМУ
 * updateCalls, а не по итоговому состоянию: запись того же значения и отсутствие записи дают
 * одинаковое состояние, ассерт по состоянию был бы тавтологией.
 */
class FeatureBindTest extends TestCase
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

    /** B1: точное имя связывает существующую строку — ни одного add(), маркер и строка карты. */
    public function testExactNameBindsExistingStorefrontFeatureWithoutCreatingIt(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Высота подъема, мм', ['position' => 41, 'in_filter' => 1]);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Высота подъема, мм', false)]);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $env->feat->addCalls, 'существующая характеристика связывается, а не создаётся');
        $this->assertSame(0, $stats->upserted);
        $this->assertSame(0, $stats->conflicts);
        $this->assertSame(0, $stats->errors);

        $map = $env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']);
        $this->assertNotFalse($map, 'строка карты feature/8 обязана указывать на существующий local_id');
        $this->assertSame(8, (int) $map->local_id);

        $markerWrites = $this->updatesWithKey($env, 'coresync_external_id');
        $this->assertCount(1, $markerWrites, 'маркер пишется ровно один раз');
        $this->assertSame('8', $markerWrites[0][1]['coresync_external_id']);
        foreach (['position', 'in_filter', 'url', 'external_id'] as $frozen) {
            $this->assertSame(
                [],
                $this->updatesWithKey($env, $frozen),
                sprintf('поле витрины "%s" не попадает ни в один updateCalls', $frozen)
            );
        }
    }

    /** B2: инвариант владельца — снятый filterable в ядре не трогает in_filter витрины. */
    public function testFilterableChangeNeverReachesStorefrontInFilter(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Высота подъема, мм', ['in_filter' => 1]);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Высота подъема, мм', false)]);

        $this->runFeatures($env);

        $this->assertSame([], $this->updatesWithKey($env, 'in_filter'), 'ключа in_filter нет ни в одном update');
        $this->assertSame(1, (int) $env->feat->rows[8]['in_filter'], 'посадочный фильтр витрины не сброшен');
    }

    /** B3: путь создания (витрины in-ua) не изменился — тот же набор полей INSERT. */
    public function testCreatePathKeepsTodaysInsertFieldSet(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Новая характеристика', true)]);

        [, $stats] = $this->runFeatures($env);

        $this->assertSame([[
            'name'        => 'Новая характеристика',
            'in_filter'   => 1,
            'visible'     => 1,
            'external_id' => '8',
        ]], $env->feat->addCalls, 'набор и порядок полей INSERT прежние (in-ua ведёт себя как раньше)');
        $this->assertSame(1, $stats->upserted);
        $map = $env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']);
        $this->assertNotFalse($map);
        $this->assertSame(1, (int) $map->local_id);
    }

    /** B4: второй прогон того же снапшота при связанной карте — ноль мутаций, растёт skipped. */
    public function testSecondRunOfTheSameSnapshotWritesNothing(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Высота подъема, мм');
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Высота подъема, мм', false)]);

        $this->runFeatures($env);
        $addsAfterFirst = count($env->feat->addCalls);
        $updatesAfterFirst = count($env->feat->updateCalls);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->skipped, 'hash тот же — строка пропускается');
        $this->assertSame(0, $stats->upserted);
        $this->assertSame(0, $stats->updated);
        $this->assertSame($addsAfterFirst, count($env->feat->addCalls), 'второй прогон не создаёт строк');
        $this->assertSame($updatesAfterFirst, count($env->feat->updateCalls), 'второй прогон не пишет маркер повторно');
    }

    /** B5: карта потеряна, маркер на строке есть — ровно marker-lookup восстанавливает связь. */
    public function testLostMapIsRestoredByMarkerWithoutCreatingAnything(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        // Имя УЖЕ разошлось с ядром: восстановление обязано идти маркером, а не именем.
        $this->seedStorefrontFeature($env, 8, 'Переименовано оператором', ['coresync_external_id' => '8']);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Высота подъема, мм', false)]);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $env->feat->addCalls);
        $this->assertSame(0, $stats->conflicts);
        $this->assertSame(0, $stats->errors);
        $map = $env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']);
        $this->assertNotFalse($map);
        $this->assertSame(8, (int) $map->local_id);
        $this->assertSame([], $this->updatesWithKey($env, 'coresync_external_id'), 'маркер уже стоит — повторно не пишется');
    }

    /** B6: неоднозначное имя — fail-closed без записей, без errors++, без STATUS_FAILED на v2. */
    public function testAmbiguousNameFailsClosedWithoutErrorsAndWithoutFailingTheRun(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Мощность');
        $this->seedStorefrontFeature($env, 9, 'Мощность');
        $warnings = [];
        $this->attachRecordingLogger($env, $warnings);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Мощность', true)]);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertNotSame(
            Contract::STATUS_FAILED,
            $status,
            'snapshot v2+ роняет прогон при phaseErrors > 0 — неоднозначность имени не имеет права быть ошибкой'
        );
        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors, 'конфликт имени не инкрементирует errors');
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame([], $env->feat->addCalls);
        $this->assertSame([], $env->feat->updateCalls);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']));

        $this->assertNotSame([], $warnings, 'оператор обязан увидеть строку с external_id и именем');
        $matched = array_values(array_filter($warnings, static function (string $message): bool {
            return strpos($message, 'Мощность') !== false && strpos($message, '8') !== false;
        }));
        $this->assertNotSame([], $matched, 'в warning есть и external_id, и имя характеристики: ' . implode(' | ', $warnings));
    }

    /** B7: чужой маркер на кандидате — конфликт без единой записи (строку не угоняем). */
    public function testCandidateOwnedByAnotherExternalIdIsNotHijacked(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Мощность', ['coresync_external_id' => '777']);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Мощность', true)]);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertNotSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame([], $env->feat->addCalls);
        $this->assertSame([], $env->feat->updateCalls);
        $this->assertSame('777', $env->feat->rows[8]['coresync_external_id'], 'чужой маркер не переписан');
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']));
    }

    /** B8: один local_id не может принадлежать двум external_id ядра. */
    public function testLocalIdAlreadyMappedToAnotherExternalIdIsRefused(): void
    {
        $env = $this->buildEnv(['UAH' => 7], $this->createMock(Languages::class));
        $this->seedStorefrontFeature($env, 8, 'Мощность');
        $env->map->add([
            'entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '777',
            'local_id' => 8, 'applied_hash' => null, 'image_state' => null,
        ]);
        $this->gz('features.ndjson.gz', [$this->featureLine('8', 'Мощность', true)]);

        [$status, $stats] = $this->runFeatures($env);

        $this->assertNotSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame([], $env->feat->addCalls);
        $this->assertSame([], $env->feat->updateCalls);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8']));
    }

    // ------------------------------------------------------- C. значения без дублей

    /** C1: значение с тем же translit уже есть у характеристики — реюз, ноль add(). */
    public function testExistingValueIsReusedByTranslit(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => Translit::translitAlpha('Красный'), 'position' => 7,
        ];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $env->fv->addCalls, 'значение уже есть — вторая строка не создаётся');
        $this->assertSame(0, $stats->errors);
        $this->assertSame([5], $this->linkedValueIds($env));
    }

    /** C2: translit не совпал (его писал другой импортёр), но точное value есть — реюз, ноль add(). */
    public function testExistingValueIsReusedByExactValueWhenTranslitDiffers(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => 'legacy-krasnyj-from-another-importer', 'position' => 7,
        ];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame([], $env->fv->addCalls, 'точное значение есть — вторая строка не создаётся');
        $this->assertSame(0, $stats->errors);
        $this->assertSame([5], $this->linkedValueIds($env));
    }

    /** C3: add() отбит уникальным ключом (гонка прогонов) — повторный lookup, связь на месте. */
    public function testUniqueKeyRejectionIsResolvedByRepeatedLookupWithoutSecondInsert(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->failNextAdds = 1;
        // Конкурирующий прогон закоммитил ровно ту строку, на которой наш INSERT и споткнулся.
        $env->fv->competitorRowOnFail = [
            'feature_id' => 100, 'value' => 'Красный', 'translit' => Translit::translitAlpha('Красный'),
        ];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertCount(1, $env->fv->addCalls, 'вторая вставка после отказа ключа не выполняется');
        $this->assertSame(0, $stats->errors, 'строка найдена повторным lookup — это не ошибка');
        $this->assertCount(1, $this->linkedValueIds($env), 'связь товара со значением не потеряна');
    }

    /** C4: повторный lookup тоже пуст — errors++, связь пропущена, второй вставки нет. */
    public function testUnresolvedInsertFailureCountsAnErrorAndSkipsTheLink(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->failNextAdds = 1;
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertCount(1, $env->fv->addCalls, 'ровно одна попытка вставки');
        $this->assertSame(1, $stats->errors, 'потерянная связь обязана быть видимой ошибкой');
        $this->assertSame([], $this->linkedValueIds($env), 'молча вторую строку не заводим');
    }

    /** C5: position существующего значения не меняется (ключа нет ни в одном update). */
    public function testExistingValuePositionIsNeverRewritten(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => Translit::translitAlpha('Красный'), 'position' => 7,
        ];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']]);

        $this->runApply($env, $this->productsManifest());

        $this->assertSame([], $env->fv->updateCalls, 'модуль не пишет в существующие строки значений');
        $this->assertSame(7, (int) $env->fv->values[5]['position']);
    }

    // ------------------------------- D. разъединение только управляемых характеристик

    /**
     * D1: значение характеристики ВНЕ карты модуля переживает apply. Именно этим на artaz исчезли
     * 278 062 старых связи: разъединение шло по товару целиком, а не по управляемым характеристикам.
     */
    public function testValuesOfUnmanagedFeaturesSurviveApply(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => Translit::translitAlpha('Красный'), 'position' => 7,
        ];
        // Чужая характеристика витрины (её в карте модуля нет вовсе).
        $env->fv->values[900] = ['id' => 900, 'feature_id' => 999, 'value' => 'Ручная сборка', 'translit' => 'ruchnayasborka'];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']], 'h1');
        $this->runApply($env, $this->productsManifest());
        $productId = (int) $env->prod->findOne(['external_id' => '1'])->id;
        $env->fv->productValues[] = ['product_id' => $productId, 'value_id' => 900];

        // Второй прогон с изменившимся хешем товара — тот самый повторный reconcile.
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']], 'h2');
        $this->runApply($env, $this->productsManifest());

        $this->assertContains(900, $this->linkedValueIds($env), 'чужие характеристики товара не снимаются');
        $this->assertContains(5, $this->linkedValueIds($env), 'управляемая связь на месте');
    }

    /** D2: управляемые значения пересобираются — снятая в снапшоте связь исчезает. */
    public function testManagedValuesAreStillRebuiltFromTheSnapshot(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => Translit::translitAlpha('Красный'), 'position' => 7,
        ];
        $env->fv->values[6] = [
            'id' => 6, 'feature_id' => 100, 'value' => 'Синий',
            'translit' => Translit::translitAlpha('Синий'), 'position' => 8,
        ];
        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']], 'h1');
        $this->runApply($env, $this->productsManifest());
        $productId = (int) $env->prod->findOne(['external_id' => '1'])->id;
        $env->fv->productValues[] = ['product_id' => $productId, 'value_id' => 6];

        $this->stageProductWithFeatureValues([['feature_external_id' => '8', 'value' => 'Красный']], 'h2');
        $this->runApply($env, $this->productsManifest());

        $this->assertSame([5], $this->linkedValueIds($env), 'снятая в снапшоте связь управляемой характеристики уходит');
    }

    /** D3: список управляемых id читается один раз за прогон, а не на каждый товар. */
    public function testManagedFeatureIdsAreReadOncePerRun(): void
    {
        $env = $this->managedFeatureEnv();
        $env->fv->values[5] = [
            'id' => 5, 'feature_id' => 100, 'value' => 'Красный',
            'translit' => Translit::translitAlpha('Красный'), 'position' => 7,
        ];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLineWithFeatureValues('1', 'phone-1', 'h1', [['feature_external_id' => '8', 'value' => 'Красный']]),
            $this->productLineWithFeatureValues('2', 'phone-2', 'h2', [['feature_external_id' => '8', 'value' => 'Красный']]),
            $this->productLineWithFeatureValues('3', 'phone-3', 'h3', [['feature_external_id' => '8', 'value' => 'Красный']]),
        ]);

        $this->runApply($env, $this->productsManifest());

        $reads = array_values(array_filter($env->map->findFilters, static function (array $filter): bool {
            return $filter === ['entity_type' => Contract::ENTITY_FEATURE];
        }));
        $this->assertCount(1, $reads, 'управляемые id читаются один раз на прогон (3 товара в снапшоте)');
    }

    // ------------------------------------------------------------------ helpers

    /** Окружение с одной УПРАВЛЯЕМОЙ характеристикой (карта модуля указывает на local 100). */
    private function managedFeatureEnv(): object
    {
        $env = $this->buildEnv();
        $env->feat->rows[100] = [
            'id' => 100, 'name' => 'Цвет', 'position' => 3, 'in_filter' => 1,
            'visible' => 1, 'url' => 'cvet', 'external_id' => '', 'coresync_external_id' => '8',
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_FEATURE, 'external_id' => '8',
            'local_id' => 100, 'applied_hash' => str_repeat('f', 64), 'image_state' => null,
        ]);

        return $env;
    }

    /** @param list<array<string, string>> $featureValues */
    private function stageProductWithFeatureValues(array $featureValues, string $hash = 'h1'): void
    {
        $this->gz('products-0001.ndjson.gz', [
            $this->productLineWithFeatureValues('1', 'phone', $hash, $featureValues),
        ]);
    }

    /** @param list<array<string, string>> $featureValues */
    private function productLineWithFeatureValues(
        string $externalId,
        string $slug,
        string $hash,
        array $featureValues
    ): string {
        $line = json_decode(
            $this->productLine($externalId, $slug, $hash, [$this->variant('v' . $externalId, 'SKU-' . $externalId, '10.00', 1)]),
            true
        );
        $line['data']['feature_values'] = $featureValues;

        return (string) json_encode($line, JSON_UNESCAPED_UNICODE);
    }

    /** @return list<int> id значений, связанных с товарами после прогона */
    private function linkedValueIds(object $env): array
    {
        $ids = array_map(static function (array $link): int {
            return (int) $link['value_id'];
        }, $env->fv->productValues);
        sort($ids);

        return $ids;
    }

    private function seedStorefrontFeature(object $env, int $id, string $name, array $override = []): void
    {
        $env->feat->rows[$id] = array_merge([
            'id'                   => $id,
            'name'                 => $name,
            'position'             => $id * 10,
            'in_filter'            => 1,
            'visible'              => 1,
            'url'                  => 'legacy-feature-' . $id,
            'external_id'          => '',
            'coresync_external_id' => null,
        ], $override);
    }

    private function featureLine(string $externalId, string $name, bool $filterable, ?string $hash = null): string
    {
        return (string) json_encode([
            'external_id' => $externalId,
            'hash'        => $hash ?? hash('sha256', 'feature-' . $externalId . '-' . $name),
            'data'        => [
                'name'       => $name,
                'type'       => 'text',
                'unit'       => null,
                'filterable' => $filterable,
                'options'    => [],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function featuresManifest(): array
    {
        return [
            'currency'  => 'UAH',
            'sync_mode' => Contract::SYNC_MODE_FULL,
            'files'     => [['name' => 'features.ndjson.gz']],
        ];
    }

    /** @return array{0:string,1:ApplyStats} */
    private function runFeatures(object $env, int $schemaMajor = 2): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $this->featuresManifest(),
            $this->stagingDir,
            new InMemoryCheckpointStore(),
            static function (): bool {
                return false;
            },
            $stats,
            $schemaMajor,
            'artaz'
        );

        return [$status, $stats];
    }

    /**
     * Записи update по характеристикам, содержащие ключ (мера «что реально передано в ядро»).
     *
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function updatesWithKey(object $env, string $field): array
    {
        return array_values(array_filter($env->feat->updateCalls, static function (array $call) use ($field): bool {
            return array_key_exists($field, $call[1]);
        }));
    }

    /** @param list<string> $warnings by-ref журнал warning-строк applier'а */
    private function attachRecordingLogger(object $env, array &$warnings): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $property = new ReflectionProperty($env->applier, 'logger');
        $property->setAccessible(true);
        $property->setValue($env->applier, $logger);
    }
}
