<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Languages;
use Okay\Core\Translit;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\FeatureLifecycleConflict;

/** Resolve source merge events to reviewed, existing receiver identities. */
final class FeatureLineageResolver
{
    private $map;
    private $features;
    private $values;
    private $languages;
    private $languageIds;

    public function __construct(MapGateway $map, $features, $values, Languages $languages, array $languageIds)
    {
        $this->map = $map;
        $this->features = $features;
        $this->values = $values;
        $this->languages = $languages;
        $this->languageIds = $languageIds;
    }

    public static function shape(array $source): string
    {
        return hash('sha256', json_encode([$source['type'], $source['unit']], JSON_THROW_ON_ERROR));
    }

    public function resolve(array $events, array $features, V5AdoptionPlan $plan): V5AdoptionPlan
    {
        $parents = [];
        $options = [];
        foreach ($events as $event) {
            $source = $event['source_id'];
            if (isset($features[$source])) {
                throw $this->conflict($event, 'retired source ID is still projected');
            }
            if ($event['action'] === 'retire') {
                continue;
            }
            $sourceMap = $this->map->find(Contract::ENTITY_FEATURE, $source);
            if ($sourceMap === null) {
                continue;
            }
            [$target, $optionPairs] = $this->terminal($event, $events);
            if ($target === null || !isset($features[$target])) {
                continue;
            }
            $targetData = $features[$target]['data'];
            $featureId = (int) $sourceMap->local_id;
            $parent = $this->parent($event, $target, $targetData, $featureId, $plan, $events);
            if ($parent !== null) {
                if (isset($parents[$target])) {
                    $parent = $this->combine($event, $parents[$target], $parent, 'owner_source_ids');
                }
                $parents[$target] = $parent;
            }
            $projectedOptions = [];
            foreach ($targetData['option_records'] as $record) {
                $projectedOptions[$record['external_id']] = $record;
            }
            foreach ($optionPairs as $old => $new) {
                if (!isset($projectedOptions[$new])) {
                    continue;
                }
                $oldKey = $source . ':' . $old;
                $newKey = $target . ':' . $new;
                $this->assertProtectedTransfer($event, $oldKey, $newKey, $plan);
                $oldMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $oldKey);
                if ($oldMap === null) {
                    $oldPolicy = $plan->option($oldKey);
                    if (($oldPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                        $targetPolicy = $plan->option($newKey);
                        if (($targetPolicy['mode'] ?? null) !== 'preserve_existing_equivalent_set'
                            || $targetPolicy['local_ids'] !== $oldPolicy['local_ids']) {
                            throw $this->conflict($event, 'merge option has an unresolved physical value set: ' . $oldKey);
                        }
                    }
                    continue;
                }
                $option = $this->option($event, $oldKey, $newKey, $projectedOptions[$new],
                    (int) $oldMap->local_id, $featureId, $plan);
                if ($option !== null) {
                    if (isset($options[$newKey])) {
                        $option = $this->combine($event, $options[$newKey], $option, 'owner_keys');
                    }
                    $options[$newKey] = $option;
                }
            }
        }
        return $plan->withFeatureLineage($parents, $options, $events);
    }

    private function terminal(array $event, array $events): array
    {
        $target = $event['target_id'];
        $pairs = array_column($event['option_map'], 'target_id', 'source_id');
        while (isset($events[$target])) {
            $next = $events[$target];
            if ($next['action'] === 'retire') {
                return [null, []];
            }
            $nextPairs = array_column($next['option_map'], 'target_id', 'source_id');
            foreach ($pairs as $old => $intermediate) {
                if (isset($nextPairs[$intermediate])) {
                    $pairs[$old] = $nextPairs[$intermediate];
                } else {
                    unset($pairs[$old]);
                }
            }
            $target = $next['target_id'];
        }
        return [$target, $pairs];
    }

    private function parent(
        array $event,
        string $target,
        array $data,
        int $localId,
        V5AdoptionPlan $plan,
        array $events
    ): ?array {
        $source = $event['source_id'];
        $sourcePolicy = $plan->parent($source);
        $targetPolicy = $plan->parent($target);
        $targetMap = $this->map->find(Contract::ENTITY_FEATURE, $target);
        $sameGroup = isset($sourcePolicy['create_group'], $targetPolicy['create_group'])
            && $sourcePolicy['create_group'] === $targetPolicy['create_group']
            && $targetMap !== null && (int) $targetMap->local_id === $localId;
        if ($localId < 1 || ($targetMap !== null && (int) $targetMap->local_id !== $localId)
            || ($targetPolicy !== null && $targetPolicy['local_id'] !== $localId && !$sameGroup)
            || ($sourcePolicy !== null && $sourcePolicy['local_id'] !== null
                && $sourcePolicy['local_id'] !== $localId)) {
            throw $this->conflict($event, 'merge parent targets different physical fields: ' . $target);
        }
        $shape = self::shape($data);
        if ($sourcePolicy !== null) {
            $knownShape = self::shape($sourcePolicy['expected_source']);
        } else {
            $metadata = $this->map->find(Contract::ENTITY_FEATURE_SOURCE_SHAPE, $source);
            $knownShape = $metadata !== null && (int) $metadata->local_id === $localId
                ? (string) $metadata->applied_hash : '';
        }
        if (!hash_equals($knownShape, $shape)) {
            throw $this->conflict($event, 'merge source type or unit is incompatible or unverified: ' . $target);
        }
        if ($sameGroup) {
            // Existing guarded group preflight already proves all physical owners and filter metadata.
            return null;
        }
        $local = $this->map->findEntityOne($this->features, ['id'=>$localId]);
        if ($local === false || (string) $local->url === '') {
            throw $this->conflict($event, 'merge physical field or URL missing: ' . $target);
        }
        $sourcePin = $sourcePolicy['expected_target'] ?? null;
        $targetPin = $targetPolicy['expected_target'] ?? null;
        foreach ([$sourcePolicy, $targetPolicy] as $policy) {
            if (isset($policy['filterable_resolution'])
                && (int) $local->in_filter !== (int) $policy['filterable_resolution']['target']) {
                throw $this->conflict($event, 'merge physical filter pin drift: ' . $target);
            }
        }
        foreach ([$sourcePin, $targetPin] as $pin) {
            if ($pin !== null && ((string) $local->external_id !== $pin['external_id']
                || (string) $local->url !== $pin['url'])) {
                throw $this->conflict($event, 'merge physical field identity drift: ' . $target);
            }
        }
        if ($sourcePin === null && $sourcePolicy === null && (string) $local->external_id !== $source
            && !$this->hasPhysicalAncestor($source, $local, $plan, $events)) {
            throw $this->conflict($event, 'unreviewed physical field has no verified source or merge origin');
        }
        $actual = $this->labels($this->features, $localId, 'name');
        $display = $targetPolicy !== null && empty($targetPolicy['follow_source_labels'])
            ? $targetPolicy['display']
            : ($sourcePolicy !== null && empty($sourcePolicy['follow_source_labels'])
                ? $sourcePolicy['display'] : $actual);
        $this->assertLabels($event, $actual, $sourcePolicy);
        $this->assertLabels($event, $actual, $targetPolicy);
        $required = array_values(array_unique(array_merge(
            [$source], $sourcePin['owner_source_ids'] ?? [], $targetPin['owner_source_ids'] ?? []
        )));
        if ($sourcePolicy !== null && isset($sourcePolicy['create_group'])) {
            $members = $plan->parentCreateGroups()[$sourcePolicy['create_group']];
            if ((string) $local->external_id !== $members[0]
                || (string) $local->url !== Translit::translitAlpha($sourcePolicy['display']['ru'])) {
                throw $this->conflict($event, 'historical create group identity drift');
            }
            foreach ($this->map->findByLocalId(Contract::ENTITY_FEATURE, $localId) as $owner) {
                if (in_array((string) $owner->external_id, $members, true)) {
                    $required[] = (string) $owner->external_id;
                }
            }
        }
        return [
            'source_id'=>$target, 'local_id'=>$localId,
            'expected_source'=>$this->sourceLabels($data['translations'], 'name')
                + ['type'=>$data['type'], 'unit'=>$data['unit']],
            'expected_target'=>$actual + [
                'id'=>$localId, 'external_id'=>(string) $local->external_id,
                'url'=>(string) $local->url, 'owner_source_ids'=>array_values(array_unique($required)),
            ],
            'display'=>$display,
        ];
    }

    /** A previously merged intermediate ID keeps the physical marker of its original owner. */
    private function hasPhysicalAncestor(string $source, object $local, V5AdoptionPlan $plan, array $events): bool
    {
        foreach ($events as $ancestor) {
            $target = $ancestor['target_id'];
            while ($target !== null && $target !== $source && isset($events[$target])) {
                $target = $events[$target]['target_id'];
            }
            if ($target !== $source) {
                continue;
            }
            $origin = $ancestor['source_id'];
            $originMap = $this->map->find(Contract::ENTITY_FEATURE, $origin);
            if ($originMap === null || (int) $originMap->local_id !== (int) $local->id) {
                continue;
            }
            $policy = $plan->parent($origin);
            $pin = $policy['expected_target'] ?? null;
            if ($pin !== null && $pin['id'] === (int) $local->id
                && $pin['external_id'] === (string) $local->external_id
                && $pin['url'] === (string) $local->url) {
                return true;
            }
            if (isset($policy['create_group'])) {
                $members = $plan->parentCreateGroups()[$policy['create_group']];
                if ($members[0] === (string) $local->external_id
                    && Translit::translitAlpha($policy['display']['ru']) === (string) $local->url) {
                    return true;
                }
            }
            if ($policy === null && $origin === (string) $local->external_id) {
                return true;
            }
        }
        return false;
    }

    private function option(
        array $event,
        string $oldKey,
        string $newKey,
        array $record,
        int $localId,
        int $featureId,
        V5AdoptionPlan $plan
    ): ?array {
        $oldPolicy = $plan->option($oldKey);
        $newPolicy = $plan->option($newKey);
        $newMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $newKey);
        $sameGroup = isset($oldPolicy['share_create_group'], $newPolicy['share_create_group'])
            && $oldPolicy['share_create_group'] === $newPolicy['share_create_group']
            && $newMap !== null && (int) $newMap->local_id === $localId;
        $sameSplit = ($newPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set'
            && in_array($localId, $newPolicy['local_ids'], true)
            && ($oldPolicy['local_id'] ?? null) === $localId;
        if ($localId < 1 || ($newMap !== null && (int) $newMap->local_id !== $localId)
            || ($oldPolicy !== null && isset($oldPolicy['local_id']) && $oldPolicy['local_id'] !== $localId)
            || ($newPolicy !== null && ($newPolicy['local_id'] ?? null) !== $localId && !$sameGroup && !$sameSplit)) {
            throw $this->conflict($event, 'merge option targets different physical values: ' . $newKey);
        }
        if ($sameGroup || $sameSplit) {
            // Existing preflight pins the target without collapsing a reviewed equivalent value set.
            return null;
        }
        $local = $this->map->findEntityOne($this->values, ['id'=>$localId]);
        if ($local === false || (int) $local->feature_id !== $featureId) {
            throw $this->conflict($event, 'merge option points outside the physical field: ' . $newKey);
        }
        $oldPin = $oldPolicy['expected_target'] ?? null;
        $newPin = $newPolicy['expected_target'] ?? null;
        foreach ([$oldPin, $newPin] as $pin) {
            if ($pin !== null && ((int) $pin['feature_id'] !== $featureId
                || (string) $local->translit !== $pin['translit'])) {
                throw $this->conflict($event, 'merge physical value identity drift: ' . $newKey);
            }
        }
        $actual = $this->labels($this->values, $localId, 'value');
        $this->assertLabels($event, $actual, $oldPolicy);
        $this->assertLabels($event, $actual, $newPolicy);
        $required = array_values(array_unique(array_merge(
            [$oldKey], $oldPin['owner_keys'] ?? [], $newPin['owner_keys'] ?? []
        )));
        if (isset($oldPolicy['share_create_group']) || isset($oldPolicy['create_group'])) {
            foreach ($this->map->findByLocalId(Contract::ENTITY_FEATURE_VALUE, $localId) as $owner) {
                $alias = $plan->option((string) $owner->external_id);
                if ((isset($oldPolicy['share_create_group'])
                        && ($alias['share_create_group'] ?? null) === $oldPolicy['share_create_group'])
                    || (isset($oldPolicy['create_group'])
                        && ($alias['create_group'] ?? null) === $oldPolicy['create_group'])) {
                    $required[] = (string) $owner->external_id;
                }
            }
        }
        return [
            'key'=>$newKey, 'local_id'=>$localId,
            'expected_source'=>$this->sourceLabels($record['translations'], 'value'),
            'expected_target'=>$actual + [
                'id'=>$localId, 'feature_id'=>$featureId, 'translit'=>(string) $local->translit,
                'owner_keys'=>array_values(array_unique($required)),
            ],
            'display'=>$newPolicy['display'] ?? $oldPolicy['display'] ?? $actual,
        ];
    }

    private function assertProtectedTransfer(array $event, string $oldKey, string $newKey, V5AdoptionPlan $plan): void
    {
        foreach ($plan->holds() as $product => $holds) {
            if (!isset($holds[$oldKey])) {
                continue;
            }
            $target = $holds[$newKey] ?? null;
            if ($target === null
                || ($target['existing_local_value_ids'] ?? []) !== ($holds[$oldKey]['existing_local_value_ids'] ?? [])) {
                throw $this->conflict($event, 'merge requires reviewed transfer of held product ' . $product);
            }
        }
        foreach ($plan->productTargets() as $product => $targets) {
            if (isset($targets[$oldKey])
                && ($targets[$newKey]['local_value_id'] ?? null) !== $targets[$oldKey]['local_value_id']) {
                throw $this->conflict($event, 'merge requires reviewed transfer of split product ' . $product);
            }
        }
    }

    private function combine(array $event, array $previous, array $next, string $owners): array
    {
        if ($previous['local_id'] !== $next['local_id'] || $previous['display'] !== $next['display']) {
            throw $this->conflict($event, 'multiple source owners disagree on the merged target');
        }
        $next['expected_target'][$owners] = array_values(array_unique(array_merge(
            $previous['expected_target'][$owners], $next['expected_target'][$owners]
        )));
        return $next;
    }

    private function sourceLabels(array $translations, string $field): array
    {
        $labels = array_column($translations, $field, 'language');
        return ['ru'=>$labels['ru'], 'uk'=>$labels['uk']];
    }

    private function labels($entity, int $id, string $field): array
    {
        $previous = (int) $this->languages->getLangId();
        $labels = [];
        try {
            foreach (['ru', 'uk'] as $language) {
                if (!isset($this->languageIds[$language])) {
                    throw new ManifestException('v5 feature lineage requires RU/UK target translations');
                }
                $this->languages->setLangId($this->languageIds[$language]);
                $row = $this->map->findEntityOne($entity, ['id'=>$id]);
                if ($row === false || (string) ($row->$field ?? '') === '') {
                    throw new ManifestException('v5 feature lineage target translation absent');
                }
                $labels[$language] = (string) $row->$field;
            }
        } finally {
            $this->languages->setLangId($previous);
        }
        return $labels;
    }

    private function assertLabels(array $event, array $actual, ?array $policy): void
    {
        if ($policy === null || !empty($policy['follow_source_labels'])) {
            return;
        }
        foreach ($actual as $language => $value) {
            if ($value !== ($policy['expected_target'][$language] ?? null)
                && $value !== ($policy['display'][$language] ?? null)) {
                throw $this->conflict($event, 'historical physical bilingual label drift');
            }
        }
    }

    private function conflict(array $event, string $message): ManifestException
    {
        return new FeatureLifecycleConflict('v5 feature lineage event ' . $event['id'] . ': ' . $message, [
            'lineage_event_id'=>$event['id'], 'source_feature_id'=>$event['source_id'],
            'target_feature_id'=>$event['target_id'],
        ]);
    }
}
