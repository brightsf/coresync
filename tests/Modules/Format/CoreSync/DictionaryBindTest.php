<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Одноразовый discovery-bind существующих словарей: exact slug используется только для первого
 * обнаружения, после чего durable identity хранится в module-owned marker + CoreSync map.
 */
class DictionaryBindTest extends TestCase
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

    public function testCategoryStubMatchesOkayUnknownFindFilterContract(): void
    {
        $env = $this->buildEnv();
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'grundfos',
            'coresync_external_id' => 'cat-grundfos',
        ];

        $this->assertCount(1, $env->cat->find(), 'unfiltered find возвращает живой category iterable');
        $this->assertSame([], $env->cat->find(['url' => 'grundfos']), 'Okay CategoriesEntity не поддерживает url filter и возвращает пусто');
        $this->assertSame(
            [],
            $env->cat->find(['coresync_external_id' => 'cat-grundfos']),
            'module marker тоже не является поддержанным category filter'
        );
        $this->assertSame(10, (int) $env->cat->findOne(['id' => 10])->id, 'known id lookup остаётся рабочим');
    }

    public function testWetContractBindsSevenFlatCategoriesAndBrandWithOkayFindSemantics(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $categoryLines = [];
        foreach (range(1, 7) as $number) {
            $localId = 9 + $number;
            $externalId = 'wet-cat-' . $number;
            $slug = 'wet-category-' . $number;
            $env->cat->rows[$localId] = [
                'id' => $localId, 'parent_id' => 0, 'url' => $slug,
                'coresync_external_id' => null, 'name' => 'Wet category ' . $number,
            ];
            $categoryLines[] = $this->categoryLine([
                'local_id' => $localId, 'external_id' => $externalId, 'slug' => $slug,
                'parent_local_id' => 0, 'parent_external_id' => null,
            ]);
        }
        $env->brand->rows[20] = [
            'id' => 20, 'url' => 'wet-brand', 'coresync_external_id' => null, 'name' => 'Wet brand',
        ];
        $this->gz('categories.ndjson.gz', $categoryLines);
        $this->gz('brands.ndjson.gz', [$this->brandLine('wet-brand', 'wet-brand')]);

        [$status, $stats] = $this->runApply($env, $this->dictionaryManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(8, $stats->bound, '7 categories + 1 brand; с product/variant baseline это 1034');
        $this->assertSame(0, $stats->unmatched, '7/7 exact category slugs должны находиться через реальный Okay find contract');
        $this->assertSame(0, $stats->conflicts);
        $this->assertCount(7, $env->map->find(['entity_type' => Contract::ENTITY_CATEGORY]));
        $this->assertCount(1, $env->map->find(['entity_type' => Contract::ENTITY_BRAND]));
    }

    public function testBrandDiscoveryKeepsExactLookupSemanticsThroughCommonHelper(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->brand->rows[20] = [
            'id' => 20, 'url' => 'brand-exact', 'coresync_external_id' => null, 'name' => 'Brand exact',
        ];
        $this->gz('brands.ndjson.gz', [$this->brandLine('brand-exact-id', 'brand-exact')]);

        [$status, $stats] = $this->runApply($env, $this->manifestFor('brands.ndjson.gz'));

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(1, $stats->bound);
        $this->assertSame(0, $stats->unmatched);
        $this->assertSame(0, $stats->conflicts);
        $map = $env->map->findOne([
            'entity_type' => Contract::ENTITY_BRAND,
            'external_id' => 'brand-exact-id',
        ]);
        $this->assertNotFalse($map);
        $this->assertSame(20, (int) $map->local_id);
        $this->assertSame('brand-exact-id', $env->brand->rows[20]['coresync_external_id']);
    }

    public function testGrundfosLikeExistingCategoriesAndBrandBindWithoutChangingContent(): void
    {
        $env = $this->buildEnv();
        $this->seedExistingDictionary($env);
        $beforeCategories = $env->cat->rows;
        $beforeBrands = $env->brand->rows;
        $this->writeDictionaryFiles();

        [$status, $stats] = $this->runApply($env, $this->dictionaryManifest());

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(8, $stats->bound, '7 categories + 1 brand связаны');
        $this->assertSame(0, $stats->unmatched);
        $this->assertSame(0, $stats->conflicts);
        $this->assertCount(7, $env->map->find(['entity_type' => Contract::ENTITY_CATEGORY]));
        $this->assertCount(1, $env->map->find(['entity_type' => Contract::ENTITY_BRAND]));

        foreach ($this->categorySpecs() as $spec) {
            $localId = $spec['local_id'];
            $externalId = $spec['external_id'];
            $map = $env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => $externalId]);
            $this->assertNotFalse($map);
            $this->assertSame($localId, (int) $map->local_id);
            $this->assertNull($map->applied_hash);
            $this->assertSame($externalId, $env->cat->rows[$localId]['coresync_external_id'] ?? null);
            $this->assertSame(
                $this->withoutMarker($beforeCategories[$localId]),
                $this->withoutMarker($env->cat->rows[$localId]),
                'bind категории меняет только marker, не контент/slug/parent/visibility'
            );
        }

        $brandMap = $env->map->findOne(['entity_type' => Contract::ENTITY_BRAND, 'external_id' => 'brand-grundfos']);
        $this->assertNotFalse($brandMap);
        $this->assertSame(20, (int) $brandMap->local_id);
        $this->assertSame('brand-grundfos', $env->brand->rows[20]['coresync_external_id'] ?? null);
        $this->assertSame(
            $this->withoutMarker($beforeBrands[20]),
            $this->withoutMarker($env->brand->rows[20]),
            'bind бренда меняет только marker'
        );
    }

    public function testSlugRenameUpdatesSameRowsAndPreservesGenericCategoryExternalId(): void
    {
        $env = $this->buildEnv();
        $this->seedExistingDictionary($env);
        $this->writeDictionaryFiles();
        $this->runApply($env, $this->dictionaryManifest());

        $this->writeDictionaryFiles(['cat-1' => 'nasosy-renamed'], 'grundfos-renamed');
        [$status] = $this->runApply($env, $this->dictionaryManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertCount(0, $env->cat->addCalls, 'связанная категория не создаётся повторно');
        $this->assertCount(0, $env->brand->addCalls, 'связанный бренд не создаётся повторно');
        $this->assertSame('nasosy-renamed', $env->cat->rows[10]['url']);
        $this->assertSame('grundfos-renamed', $env->brand->rows[20]['url']);
        $this->assertSame('cat-1', $env->cat->rows[10]['coresync_external_id']);
        $this->assertSame('brand-grundfos', $env->brand->rows[20]['coresync_external_id']);
        $this->assertSame('legacy-category:10', $env->cat->rows[10]['external_id'], 'generic legacy external_id не захватывается CoreSync');
    }

    public function testFullApplyRepairsDeletedMapFromMarkerAfterSlugAlreadyChanged(): void
    {
        $env = $this->buildEnv();
        $this->seedExistingDictionary($env);
        $this->writeDictionaryFiles();
        $this->runApply($env, $this->dictionaryManifest());

        $this->removeMap($env, Contract::ENTITY_CATEGORY, 'cat-1');
        $this->removeMap($env, Contract::ENTITY_BRAND, 'brand-grundfos');
        // Slug уже изменён локально: fallback по старому slug больше физически невозможен.
        $env->cat->rows[10]['url'] = 'nasosy-local-renamed';
        $env->brand->rows[20]['url'] = 'grundfos-local-renamed';
        $this->writeDictionaryFiles(['cat-1' => 'nasosy-from-core'], 'grundfos-from-core');

        [$status] = $this->runApply($env, $this->dictionaryManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertCount(0, $env->cat->addCalls, 'marker восстанавливает map без duplicate category');
        $this->assertCount(0, $env->brand->addCalls, 'marker восстанавливает map без duplicate brand');
        $this->assertSame(10, (int) $env->map->findOne([
            'entity_type' => Contract::ENTITY_CATEGORY,
            'external_id' => 'cat-1',
        ])->local_id);
        $this->assertSame(20, (int) $env->map->findOne([
            'entity_type' => Contract::ENTITY_BRAND,
            'external_id' => 'brand-grundfos',
        ])->local_id);
        $this->assertSame('nasosy-from-core', $env->cat->rows[10]['url']);
        $this->assertSame('grundfos-from-core', $env->brand->rows[20]['url']);
    }

    public function testFullCreateWritesModuleMarkerAndNeverGenericCategoryExternalId(): void
    {
        $env = $this->buildEnv(); // пустой product catalog => сразу full, не bind
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-new', 'slug' => 'new-category',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);
        $this->gz('brands.ndjson.gz', [$this->brandLine('brand-new', 'new-brand')]);

        [$status] = $this->runApply($env, $this->dictionaryManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame('cat-new', $env->cat->addCalls[0]['coresync_external_id'] ?? null);
        $this->assertArrayNotHasKey('external_id', $env->cat->addCalls[0], 'generic category external_id не пишется даже на create');
        $this->assertSame('brand-new', $env->brand->addCalls[0]['coresync_external_id'] ?? null);
    }

    public function testFullHashSkipBackfillsMarkerOnExistingMapWithoutContentWrite(): void
    {
        $env = $this->buildEnv();
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'stable', 'coresync_external_id' => null,
            'external_id' => 'legacy-stable', 'name' => 'Untouched',
        ];
        $hash = hash('sha256', 'cat-stable');
        $env->map->add([
            'entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-stable',
            'local_id' => 10, 'applied_hash' => $hash, 'image_state' => null,
        ]);
        $this->gz('categories.ndjson.gz', [(string) json_encode([
            'external_id' => 'cat-stable', 'hash' => $hash,
            'data' => [
                'name' => 'Snapshot name must be skipped', 'slug' => 'stable',
                'parent_external_id' => null, 'position' => 999, 'is_active' => true,
            ],
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->skipped);
        $this->assertSame('cat-stable', $env->cat->rows[10]['coresync_external_id']);
        $this->assertSame('Untouched', $env->cat->rows[10]['name'], 'hash skip не превращается в content update');
        $this->assertSame('legacy-stable', $env->cat->rows[10]['external_id']);
        $this->assertSame([[10, ['coresync_external_id' => 'cat-stable']]], $env->cat->updateCalls);
    }

    public function testDuplicateSlugFailsClosedWithoutMarkerOrMapWrites(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        foreach ([10, 11] as $id) {
            $env->cat->rows[$id] = [
                'id' => $id, 'parent_id' => 0, 'url' => 'duplicate',
                'coresync_external_id' => null, 'name' => 'Duplicate ' . $id,
            ];
        }
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-dup', 'slug' => 'duplicate',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->conflicts);
        $this->assertContains('category cat-dup (duplicate slug)', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-dup']));
        $this->assertCount(0, $env->cat->updateCalls, 'ни один duplicate candidate не получает marker');
    }

    public function testBindDuplicateSlugConflictRemainsFailClosedOnNextFull(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        foreach ([10, 11] as $id) {
            $env->cat->rows[$id] = [
                'id' => $id, 'parent_id' => 0, 'url' => 'duplicate-full',
                'coresync_external_id' => null, 'name' => 'Untouched duplicate ' . $id,
            ];
        }
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-dup-full', 'slug' => 'duplicate-full',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);
        $manifest = $this->manifestFor('categories.ndjson.gz');

        [$bindStatus, $bindStats] = $this->runApply($env, $manifest);
        $this->assertSame(Contract::STATUS_BOUND, $bindStatus);
        $this->assertSame(1, $bindStats->conflicts, 'bind фиксирует duplicate slug');
        $env->cat->addCalls = [];
        $env->cat->updateCalls = [];

        [$fullStatus, $fullStats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_FAILED, $fullStatus, 'следующий full не превращает bind conflict в create');
        $this->assertSame(1, $fullStats->conflicts);
        $this->assertSame(1, $fullStats->errors);
        $this->assertContains('category cat-dup-full (duplicate slug)', $fullStats->conflictSamples);
        $this->assertCount(0, $env->cat->addCalls, 'duplicate slug не вызывает add в full');
        $this->assertCount(0, $env->cat->updateCalls, 'duplicate slug не вызывает content update в full');
        $this->assertSame('Untouched duplicate 10', $env->cat->rows[10]['name']);
        $this->assertSame('Untouched duplicate 11', $env->cat->rows[11]['name']);
        $this->assertFalse($env->map->findOne([
            'entity_type' => Contract::ENTITY_CATEGORY,
            'external_id' => 'cat-dup-full',
        ]));
    }

    public function testBindWrongParentConflictRemainsFailClosedOnNextFull(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'root-full',
            'coresync_external_id' => 'cat-root-full', 'name' => 'Root untouched',
        ];
        $env->cat->rows[11] = [
            'id' => 11, 'parent_id' => 999, 'url' => 'child-full',
            'coresync_external_id' => null, 'name' => 'Child untouched',
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-root-full',
            'local_id' => 10, 'applied_hash' => hash('sha256', 'cat-root-full'), 'image_state' => null,
        ]);
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 11, 'external_id' => 'cat-child-full', 'slug' => 'child-full',
            'parent_local_id' => 10, 'parent_external_id' => 'cat-root-full',
        ])]);
        $manifest = $this->manifestFor('categories.ndjson.gz');

        [$bindStatus, $bindStats] = $this->runApply($env, $manifest);
        $this->assertSame(Contract::STATUS_BOUND, $bindStatus);
        $this->assertSame(1, $bindStats->conflicts, 'bind фиксирует wrong parent');
        $env->cat->addCalls = [];
        $env->cat->updateCalls = [];

        [$fullStatus, $fullStats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_FAILED, $fullStatus, 'следующий full не создаёт child после wrong-parent conflict');
        $this->assertSame(1, $fullStats->conflicts);
        $this->assertSame(1, $fullStats->errors);
        $this->assertContains('category cat-child-full (parent mismatch)', $fullStats->conflictSamples);
        $this->assertCount(0, $env->cat->addCalls);
        $this->assertCount(0, $env->cat->updateCalls);
        $this->assertSame('Child untouched', $env->cat->rows[11]['name']);
        $this->assertNull($env->cat->rows[11]['coresync_external_id']);
        $this->assertFalse($env->map->findOne([
            'entity_type' => Contract::ENTITY_CATEGORY,
            'external_id' => 'cat-child-full',
        ]));
    }

    public function testFullRecoversUniqueSlugAfterEarlierBindConflictWasResolved(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        foreach ([10, 11] as $id) {
            $env->cat->rows[$id] = [
                'id' => $id, 'parent_id' => 0, 'url' => 'resolved-before-full',
                'coresync_external_id' => null, 'name' => 'Legacy ' . $id,
            ];
        }
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-recovered', 'slug' => 'resolved-before-full',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);
        $manifest = $this->manifestFor('categories.ndjson.gz');

        [, $bindStats] = $this->runApply($env, $manifest);
        $this->assertSame(1, $bindStats->conflicts);
        unset($env->cat->rows[11]); // оператор устранил неоднозначность между bind и следующим full
        $env->cat->addCalls = [];
        $env->cat->updateCalls = [];

        [$status, $stats] = $this->runApply($env, $manifest);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->conflicts);
        $this->assertSame(1, $stats->updated);
        $this->assertCount(0, $env->cat->addCalls, 'unique exact candidate обновляется, а не дублируется');
        $this->assertSame('cat-recovered', $env->cat->rows[10]['coresync_external_id']);
        $this->assertSame('Snapshot cat-recovered', $env->cat->rows[10]['name']);
        $map = $env->map->findOne([
            'entity_type' => Contract::ENTITY_CATEGORY,
            'external_id' => 'cat-recovered',
        ]);
        $this->assertNotFalse($map);
        $this->assertSame(10, (int) $map->local_id);
        $this->assertSame(hash('sha256', 'cat-recovered'), $map->applied_hash);
        $this->assertSame(
            [10, ['coresync_external_id' => 'cat-recovered']],
            $env->cat->updateCalls[0],
            'marker пишется до map/content для crash-safe recovery'
        );
    }

    public function testFullExactSlugOwnedByAnotherExternalIdFailsBeforeContentWrite(): void
    {
        $env = $this->buildEnv();
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'owned-before-full',
            'coresync_external_id' => null, 'name' => 'Owned untouched',
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-owner',
            'local_id' => 10, 'applied_hash' => hash('sha256', 'cat-owner'), 'image_state' => null,
        ]);
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 10, 'external_id' => 'cat-intruder', 'slug' => 'owned-before-full',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [$status, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame(1, $stats->errors);
        $this->assertContains('category cat-intruder (local id already mapped)', $stats->conflictSamples);
        $this->assertCount(0, $env->cat->addCalls);
        $this->assertCount(0, $env->cat->updateCalls);
        $this->assertSame('Owned untouched', $env->cat->rows[10]['name']);
        $this->assertNull($env->cat->rows[10]['coresync_external_id']);
        $this->assertFalse($env->map->findOne([
            'entity_type' => Contract::ENTITY_CATEGORY,
            'external_id' => 'cat-intruder',
        ]));
    }

    public function testDuplicateMarkerFailsClosedWithoutNewWrites(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        foreach ([10, 11] as $id) {
            $env->cat->rows[$id] = [
                'id' => $id, 'parent_id' => 0, 'url' => 'slug-' . $id,
                'coresync_external_id' => 'cat-marker',
            ];
        }
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-marker', 'slug' => 'slug-10',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->conflicts);
        $this->assertContains('category cat-marker (duplicate marker)', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-marker']));
        $this->assertCount(0, $env->cat->updateCalls);
    }

    public function testFullApplyDuplicateMarkerFailsBeforeCreateOrContentMutation(): void
    {
        $env = $this->buildEnv();
        foreach ([10, 11] as $id) {
            $env->cat->rows[$id] = [
                'id' => $id, 'parent_id' => 0, 'url' => 'marker-' . $id,
                'coresync_external_id' => 'cat-marker', 'name' => 'Untouched ' . $id,
            ];
        }
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-marker', 'slug' => 'snapshot',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [$status, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->conflicts);
        $this->assertSame(1, $stats->errors);
        $this->assertCount(0, $env->cat->addCalls);
        $this->assertCount(0, $env->cat->updateCalls);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-marker']));
    }

    public function testMarkerMapMismatchFailsClosedWithoutCatalogWrite(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'mapped', 'coresync_external_id' => null,
        ];
        $env->cat->rows[11] = [
            'id' => 11, 'parent_id' => 0, 'url' => 'marker', 'coresync_external_id' => 'cat-x',
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-x',
            'local_id' => 10, 'applied_hash' => null, 'image_state' => null,
        ]);
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 0, 'external_id' => 'cat-x', 'slug' => 'mapped',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->conflicts);
        $this->assertContains('category cat-x (marker-map mismatch)', $stats->conflictSamples);
        $this->assertCount(0, $env->cat->updateCalls);
    }

    public function testWrongCategoryParentFailsClosedAfterParentResolved(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'root', 'coresync_external_id' => null,
        ];
        $env->cat->rows[11] = [
            'id' => 11, 'parent_id' => 999, 'url' => 'child', 'coresync_external_id' => null,
        ];
        $root = ['local_id' => 10, 'external_id' => 'cat-root', 'slug' => 'root', 'parent_local_id' => 0, 'parent_external_id' => null];
        $child = ['local_id' => 11, 'external_id' => 'cat-child', 'slug' => 'child', 'parent_local_id' => 10, 'parent_external_id' => 'cat-root'];
        $this->gz('categories.ndjson.gz', [$this->categoryLine($child), $this->categoryLine($root)]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->bound, 'parent связан');
        $this->assertSame(1, $stats->conflicts, 'неверный parent ребёнка — conflict');
        $this->assertContains('category cat-child (parent mismatch)', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-child']));
        $this->assertNull($env->cat->rows[11]['coresync_external_id']);
    }

    public function testRawLocalIdEqualityNeverActsAsDictionaryIdentity(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[777] = [
            'id' => 777, 'parent_id' => 0, 'url' => 'unrelated-local-slug', 'coresync_external_id' => null,
        ];
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 777, 'external_id' => '777', 'slug' => 'snapshot-slug',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->unmatched);
        $this->assertSame(0, $stats->bound);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => '777']));
        $this->assertNull($env->cat->rows[777]['coresync_external_id']);
    }

    public function testSlugDiscoveryIsExactAndCaseSensitive(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'Grundfos', 'coresync_external_id' => null,
        ];
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 10, 'external_id' => 'cat-case', 'slug' => 'grundfos',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->unmatched);
        $this->assertSame(0, $stats->bound);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-case']));
        $this->assertNull($env->cat->rows[10]['coresync_external_id']);
    }

    public function testOneLocalIdCannotBeMappedToTwoExternalIds(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'same-local', 'coresync_external_id' => null,
        ];
        $env->map->add([
            'entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-old',
            'local_id' => 10, 'applied_hash' => null, 'image_state' => null,
        ]);
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 10, 'external_id' => 'cat-new', 'slug' => 'same-local',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);

        [, $stats] = $this->runApply($env, $this->manifestFor('categories.ndjson.gz'));

        $this->assertSame(1, $stats->conflicts);
        $this->assertContains('category cat-new (local id already mapped)', $stats->conflictSamples);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_CATEGORY, 'external_id' => 'cat-new']));
        $this->assertNull($env->cat->rows[10]['coresync_external_id']);
    }

    public function testFeatureNameMatchIsNotUsedByBind(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->feat->rows[30] = ['id' => 30, 'name' => 'Flow rate', 'external_id' => 'legacy-feature'];
        $this->gz('features.ndjson.gz', [(string) json_encode([
            'external_id' => 'feature-core', 'hash' => hash('sha256', 'feature-core'),
            'data' => ['name' => 'Flow rate', 'filterable' => true],
        ])]);

        [$status, $stats] = $this->runApply($env, $this->manifestFor('features.ndjson.gz'));

        $this->assertSame(Contract::STATUS_BOUND, $status);
        $this->assertSame(0, $stats->bound);
        $this->assertFalse($env->map->findOne(['entity_type' => Contract::ENTITY_FEATURE, 'external_id' => 'feature-core']));
        $this->assertCount(0, $env->feat->updateCalls);
    }

    public function testDictionaryBindCheckpointAndInterruptResumeWithoutDuplicateWrites(): void
    {
        $env = $this->buildEnv();
        $this->activateBind($env);
        $env->cat->rows[10] = [
            'id' => 10, 'parent_id' => 0, 'url' => 'root', 'coresync_external_id' => null,
        ];
        $env->brand->rows[20] = [
            'id' => 20, 'url' => 'grundfos', 'coresync_external_id' => null,
        ];
        $this->gz('categories.ndjson.gz', [$this->categoryLine([
            'local_id' => 10, 'external_id' => 'cat-root', 'slug' => 'root',
            'parent_local_id' => 0, 'parent_external_id' => null,
        ])]);
        $this->gz('brands.ndjson.gz', [$this->brandLine('brand-grundfos', 'grundfos')]);
        $manifest = [
            'currency' => 'UAH', 'sync_mode' => Contract::SYNC_MODE_FULL,
            'files' => [['name' => 'brands.ndjson.gz'], ['name' => 'categories.ndjson.gz']],
        ];
        $checkpoints = new InMemoryCheckpointStore();
        $calls = 0;
        $cancel = static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        };

        [$cancelled] = $this->runApplyWithCancel($env, $manifest, $cancel, $checkpoints);
        $this->assertSame(Contract::STATUS_CANCELLED, $cancelled);
        $this->assertCount(1, $env->map->find(['entity_type' => Contract::ENTITY_CATEGORY]));
        $this->assertCount(0, $env->map->find(['entity_type' => Contract::ENTITY_BRAND]));

        [$resumed, $stats] = $this->runApply($env, $manifest, $checkpoints);
        $this->assertSame(Contract::STATUS_BOUND, $resumed);
        $this->assertSame(1, $stats->bound, 'resume связывает только незачекпоинченный brand file');
        $this->assertCount(1, $env->map->find(['entity_type' => Contract::ENTITY_CATEGORY]));
        $this->assertCount(1, $env->map->find(['entity_type' => Contract::ENTITY_BRAND]));
        $this->assertCount(1, $env->cat->updateCalls, 'category marker не пишется повторно');
    }

    private function seedExistingDictionary(object $env): void
    {
        // Непустой каталог активирует одноразовый bind; product/variant discovery покрыт BindTest.
        $this->activateBind($env);

        foreach ($this->categorySpecs() as $spec) {
            $id = $spec['local_id'];
            $env->cat->rows[$id] = [
                'id'                   => $id,
                'parent_id'            => $spec['parent_local_id'],
                'url'                  => $spec['slug'],
                'external_id'          => 'legacy-category:' . $id,
                'coresync_external_id' => null,
                'name'                 => 'Legacy category ' . $id,
                'annotation'           => '<p>annotation ' . $id . '</p>',
                'description'          => '<p>description ' . $id . '</p>',
                'meta_title'           => 'Legacy SEO ' . $id,
                'position'             => $id,
                'visible'              => $id % 2,
            ];
        }
        $env->brand->rows[20] = [
            'id'                   => 20,
            'url'                  => 'grundfos',
            'coresync_external_id' => null,
            'name'                 => 'Legacy Grundfos',
            'visible'              => 0,
        ];
    }

    /** @param array<string, string> $categorySlugOverrides */
    private function writeDictionaryFiles(array $categorySlugOverrides = [], string $brandSlug = 'grundfos'): void
    {
        // Дети намеренно стоят раньше родителей: bind обязан разрешить parent/path итеративно.
        $specs = array_reverse($this->categorySpecs());
        $this->gz('categories.ndjson.gz', array_map(function (array $spec) use ($categorySlugOverrides): string {
            if (isset($categorySlugOverrides[$spec['external_id']])) {
                $spec['slug'] = $categorySlugOverrides[$spec['external_id']];
            }

            return $this->categoryLine($spec);
        }, $specs));
        $this->gz('brands.ndjson.gz', [$this->brandLine('brand-grundfos', $brandSlug)]);
    }

    /** @return array<string, mixed> */
    private function dictionaryManifest(): array
    {
        return [
            'currency'  => 'UAH',
            'sync_mode' => Contract::SYNC_MODE_FULL,
            'files'     => [
                ['name' => 'brands.ndjson.gz'],
                ['name' => 'categories.ndjson.gz'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function manifestFor(string $name): array
    {
        return [
            'currency' => 'UAH', 'sync_mode' => Contract::SYNC_MODE_FULL,
            'files' => [['name' => $name]],
        ];
    }

    private function activateBind(object $env): void
    {
        $env->prod->rows[100] = ['id' => 100, 'url' => 'legacy-product', 'external_id' => 'legacy-product:100'];
    }

    /** @return list<array{local_id:int,external_id:string,slug:string,parent_local_id:int,parent_external_id:?string}> */
    private function categorySpecs(): array
    {
        return [
            ['local_id' => 10, 'external_id' => 'cat-1', 'slug' => 'nasosy', 'parent_local_id' => 0, 'parent_external_id' => null],
            ['local_id' => 11, 'external_id' => 'cat-2', 'slug' => 'cirkulyacionnye', 'parent_local_id' => 10, 'parent_external_id' => 'cat-1'],
            ['local_id' => 12, 'external_id' => 'cat-3', 'slug' => 'skvazhinnye', 'parent_local_id' => 10, 'parent_external_id' => 'cat-1'],
            ['local_id' => 13, 'external_id' => 'cat-4', 'slug' => 'drenazhnye', 'parent_local_id' => 10, 'parent_external_id' => 'cat-1'],
            ['local_id' => 14, 'external_id' => 'cat-5', 'slug' => 'kanalizacionnye', 'parent_local_id' => 13, 'parent_external_id' => 'cat-4'],
            ['local_id' => 15, 'external_id' => 'cat-6', 'slug' => 'poverhnostnye', 'parent_local_id' => 10, 'parent_external_id' => 'cat-1'],
            ['local_id' => 16, 'external_id' => 'cat-7', 'slug' => 'komplektuyushchie', 'parent_local_id' => 0, 'parent_external_id' => null],
        ];
    }

    private function categoryLine(array $spec): string
    {
        return (string) json_encode([
            'external_id' => $spec['external_id'],
            'hash'        => hash('sha256', $spec['external_id']),
            'data'        => [
                'name'                => 'Snapshot ' . $spec['external_id'],
                'slug'                => $spec['slug'],
                'parent_external_id'  => $spec['parent_external_id'],
                'position'            => 999,
                'is_active'           => true,
                'annotation_html'     => '<p>snapshot annotation</p>',
                'description_html'    => '<p>snapshot description</p>',
                'seo_title'           => 'Snapshot SEO',
                'seo_keywords'        => 'snapshot',
                'seo_description'     => 'Snapshot description',
                'image_url'           => null,
            ],
        ]);
    }

    private function brandLine(string $externalId, string $slug): string
    {
        return (string) json_encode([
            'external_id' => $externalId,
            'hash'        => hash('sha256', $externalId),
            'data'        => ['name' => 'Snapshot Grundfos', 'slug' => $slug, 'is_active' => true],
        ]);
    }

    /** @return array<string, mixed> */
    private function withoutMarker(array $row): array
    {
        unset($row['coresync_external_id']);

        return $row;
    }

    private function removeMap(object $env, string $entityType, string $externalId): void
    {
        foreach ($env->map->rows as $id => $row) {
            if (($row['entity_type'] ?? null) === $entityType && ($row['external_id'] ?? null) === $externalId) {
                unset($env->map->rows[$id]);
            }
        }
    }
}
