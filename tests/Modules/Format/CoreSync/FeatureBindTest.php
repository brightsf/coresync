<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Languages;
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

    // ------------------------------------------------------------------ helpers

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
