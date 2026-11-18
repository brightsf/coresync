<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Translit;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\FeatureLineageValidator;

/**
 * Immutable receiver decisions. A Russian-only plan may pin unchanged local feature IDs and
 * protect existing feature-value links. The default profile retains the bilingual contract.
 */
final class V5AdoptionPlan
{
    private const MAX_BYTES = 8388608;

    /** @var string */
    private $digest;
    /** @var string */
    private $sourceInstance;
    /** @var string */
    private $channelCode;
    /** @var string[] */
    private $languages;
    /** @var array<string, array<string, mixed>> */
    private $parents;
    /** @var array<string, array<string, mixed>> */
    private $options;
    /** @var array<string, array<string, array<string, mixed>>> */
    private $holds;
    /** @var array<string, array<string, array<string, mixed>>> */
    private $productTargets;
    /** @var bool */
    private $preserveMappedStock;
    /** @var bool */
    private $preserveMappedProductStructure;
    /** @var bool */
    private $featureLifecycle;
    /** @var string|null */
    private $manifestChannelCode;
    /** @var array<string, array<int, array<string, mixed>>> */
    private $featureLinkAdoptions = [];

    private function __construct(string $digest, array $data)
    {
        $this->digest = $digest;
        $this->sourceInstance = $data['source_instance'];
        $this->channelCode = $data['channel_code'];
        $this->languages = $data['languages'] ?? ['ru', 'uk'];
        $this->preserveMappedStock = $data['preserve_mapped_stock'];
        $this->preserveMappedProductStructure = $data['preserve_mapped_product_structure'] ?? false;
        $this->featureLifecycle = $data['feature_lifecycle'] ?? false;
        $this->manifestChannelCode = $data['manifest_channel_code'] ?? null;
        $this->parents = [];
        $this->options = [];
        $this->holds = [];
        $this->productTargets = [];
        foreach ($data['parents'] as $row) {
            $this->parents[$row['source_id']] = $row;
        }
        foreach ($data['options'] as $row) {
            $this->options[$row['key']] = $row;
        }
        foreach ($data['holds'] as $row) {
            $this->holds[$row['product_external_id']][$row['key']] = $row;
        }
        foreach ($data['product_targets'] ?? [] as $row) {
            $this->productTargets[$row['product_external_id']][$row['key']] = $row;
        }
        foreach ($data['feature_link_adoptions'] ?? [] as $row) {
            $this->featureLinkAdoptions[$row['product_external_id']][] = $row;
        }
    }

    public static function load(string $path, string $expectedSha256): self
    {
        if ($path === '' || strpos($path, '://') !== false || !self::sha($expectedSha256)
            || !is_file($path) || !is_readable($path)) {
            throw new ManifestException('v5 adoption plan path or digest invalid');
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
            throw new ManifestException('v5 adoption plan size invalid');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size || !hash_equals($expectedSha256, hash('sha256', $raw))) {
            throw new ManifestException('v5 adoption plan digest mismatch');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE
            || json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== $raw) {
            throw new ManifestException('v5 adoption plan must be canonical JSON object');
        }
        self::validate($data);
        return new self($expectedSha256, $data);
    }

    public function withFeatureLineage(array $parents, array $options, array $events): self
    {
        if ($events === []) {
            return $this;
        }
        $resolved = clone $this;
        $resolved->parents = $parents + $this->parents;
        $resolved->options = $options + $this->options;
        ksort($events, SORT_STRING);
        $resolved->digest = hash('sha256', $this->digest . '|feature-lineage|'
            . implode('', array_map([FeatureLineageValidator::class, 'eventHash'], $events)));
        return $resolved;
    }

    public function featureLinkAdoptions(string $productExternalId): array
    {
        return $this->featureLinkAdoptions[$productExternalId] ?? [];
    }

    public function featureLifecycle(): bool { return $this->featureLifecycle; }
    public function manifestChannelCode(): ?string { return $this->manifestChannelCode; }
    public function digest(): string { return $this->digest; }
    public function preserveMappedStock(): bool { return $this->preserveMappedStock; }
    public function preserveMappedProductStructure(): bool { return $this->preserveMappedProductStructure; }
    public function parent(string $externalId): ?array { return $this->parents[$externalId] ?? null; }
    public function option(string $key): ?array { return $this->options[$key] ?? null; }
    public function hold(string $productExternalId, string $key): ?array
    {
        return $this->holds[$productExternalId][$key] ?? null;
    }
    public function parents(): array { return $this->parents; }
    public function options(): array { return $this->options; }
    public function holds(): array { return $this->holds; }
    public function productTarget(string $productExternalId, string $key): ?array
    {
        return $this->productTargets[$productExternalId][$key] ?? null;
    }
    public function productTargets(): array { return $this->productTargets; }
    public function parentCreateGroups(): array
    {
        $groups = [];
        foreach ($this->parents as $row) {
            if (isset($row['create_group'])) { $groups[$row['create_group']][] = $row['source_id']; }
        }
        foreach ($groups as &$members) { sort($members, SORT_STRING); }
        return $groups;
    }
    public function sharedOptionCreateGroups(): array
    {
        $groups = [];
        foreach ($this->options as $key => $row) {
            if (isset($row['share_create_group'])) { $groups[$row['share_create_group']][] = $key; }
        }
        foreach ($groups as &$members) { sort($members, SORT_STRING); }
        return $groups;
    }

    public function assertContext(string $sourceInstance, string $channelCode): void
    {
        if ($this->sourceInstance !== $sourceInstance || $this->channelCode !== $channelCode) {
            throw new ManifestException('v5 adoption plan source/channel mismatch');
        }
    }

    /** @return string[] */
    public function languages(): array
    {
        return $this->languages;
    }

    /** @param string[] $snapshotLanguages @param string[] $receiverLanguages */
    public function assertLanguageScope(array $snapshotLanguages, array $receiverLanguages): void
    {
        if ($this->languages === ['ru'] && ($snapshotLanguages !== ['ru'] || $receiverLanguages !== ['ru'])) {
            throw new ManifestException('v5 adoption RU-only plan requires a Russian-only snapshot and receiver');
        }
    }

    private static function validate(array $data): void
    {
        self::keys($data, ['schema', 'source_instance', 'channel_code', 'parents', 'options', 'holds', 'preserve_mapped_stock'],
            ['product_targets', 'preserve_mapped_product_structure', 'feature_lifecycle', 'feature_link_adoptions', 'manifest_channel_code', 'languages']);
        if ($data['schema'] !== 1 || !self::id($data['source_instance']) || !self::id($data['channel_code'])
            || !is_bool($data['preserve_mapped_stock'])
            || (array_key_exists('feature_lifecycle', $data) && !is_bool($data['feature_lifecycle']))
            || (!empty($data['feature_lifecycle']) && !self::id($data['manifest_channel_code'] ?? null))
            || (array_key_exists('manifest_channel_code', $data) && empty($data['feature_lifecycle']))
            || (array_key_exists('preserve_mapped_product_structure', $data)
                && !is_bool($data['preserve_mapped_product_structure']))) {
            throw new ManifestException('v5 adoption plan header invalid');
        }
        foreach (['parents', 'options', 'holds'] as $name) {
            if (!self::list($data[$name])) { throw new ManifestException('v5 adoption plan rows invalid'); }
        }
        if (isset($data['product_targets']) && !self::list($data['product_targets'])) {
            throw new ManifestException('v5 adoption plan product targets invalid');
        }
        $russianOnly = array_key_exists('languages', $data);
        if ($russianOnly && ($data['languages'] !== ['ru'] || $data['options'] !== []
            || !empty($data['feature_lifecycle']) || !empty($data['product_targets'])
            || !empty($data['feature_link_adoptions']))) {
            throw new ManifestException('v5 adoption RU-only plan supports only exact parents and existing-link holds');
        }
        $languages = $russianOnly ? ['ru'] : ['ru', 'uk'];
        $parentTargets = [];
        $parents = [];
        foreach ($data['parents'] as $row) {
            self::keys($row, ['source_id', 'local_id', 'expected_source', 'expected_target', 'display'],
                ['create_group', 'filterable_resolution', 'follow_source_labels']);
            if (!self::id($row['source_id']) || isset($parents[$row['source_id']])) {
                throw new ManifestException('v5 adoption plan duplicate/invalid parent');
            }
            self::labels($row['expected_source'], array_merge($languages, ['type', 'unit']), $languages);
            if (!is_string($row['expected_source']['type']) || $row['expected_source']['type'] === ''
                || ($row['expected_source']['unit'] !== null && !is_string($row['expected_source']['unit']))) {
                throw new ManifestException('v5 adoption plan parent type/unit invalid');
            }
            self::labels($row['display'], $languages, $languages);
            if (array_key_exists('filterable_resolution', $row)) {
                if (!isset($row['create_group']) && empty($data['feature_lifecycle'])) {
                    throw new ManifestException('v5 adoption existing-parent filter pin requires lifecycle mode');
                }
                self::keys($row['filterable_resolution'], ['expected_source', 'target']);
                if (!is_bool($row['filterable_resolution']['expected_source'])
                    || !is_bool($row['filterable_resolution']['target'])) {
                    throw new ManifestException('v5 adoption filter resolution flags invalid');
                }
            }
            self::target($row, 'parent', $languages);
            if ($russianOnly) {
                $target = $row['expected_target'];
                if ($row['local_id'] === null || isset($parentTargets[$row['local_id']])
                    || array_intersect(['create_group', 'filterable_resolution', 'follow_source_labels'], array_keys($row)) !== []
                    || $row['display']['ru'] !== $row['expected_source']['ru']
                    || $row['display']['ru'] !== $target['ru']
                    || !in_array($target['owner_source_ids'], [[], [$row['source_id']]], true)) {
                    throw new ManifestException('v5 adoption RU-only parent requires one unchanged local target');
                }
                $parentTargets[$row['local_id']] = true;
            }
            if (array_key_exists('follow_source_labels', $row)
                && (!is_bool($row['follow_source_labels'])
                    || ($row['follow_source_labels'] && (empty($data['feature_lifecycle'])
                        || $row['local_id'] === null || isset($row['create_group'])
                        || $row['expected_target']['owner_source_ids'] !== [$row['source_id']])))) {
                throw new ManifestException('v5 adoption managed labels require a single established owner');
            }
            $parents[$row['source_id']] = $row;
        }
        self::newParentGroups($parents);
        $options = [];
        foreach ($data['options'] as $row) {
            if (($row['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                self::keys($row, ['key', 'mode', 'local_ids', 'expected_source', 'expected_targets'],
                    ['display', 'semantic_duration_months']);
            } else {
                self::keys($row, ['key', 'local_id', 'expected_source', 'expected_target', 'display'],
                    ['create_group', 'share_create_group']);
            }
            if (!is_string($row['key']) || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $row['key'])
                || isset($options[$row['key']])) {
                throw new ManifestException('v5 adoption plan duplicate/invalid option');
            }
            self::labels($row['expected_source'], ['ru', 'uk']);
            if (($row['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                self::splitTargets($row);
            } else {
                self::labels($row['display'], ['ru', 'uk']);
                self::target($row, 'option');
            }
            $options[$row['key']] = $row;
        }
        self::newOptionGroups($options, $parents);
        $holds = [];
        foreach ($data['holds'] as $row) {
            self::keys($row, ['product_external_id', 'key', 'expected_value'], ['existing_local_value_ids']);
            if (!self::id($row['product_external_id']) || !is_string($row['key'])
                || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $row['key'])
                || !is_string($row['expected_value']) || $row['expected_value'] === '') {
                throw new ManifestException('v5 adoption plan hold invalid');
            }
            $holdKey = $row['product_external_id'] . '|' . $row['key'];
            if (isset($holds[$holdKey])) { throw new ManifestException('v5 adoption plan duplicate hold'); }
            if ($russianOnly && empty($row['existing_local_value_ids'])) {
                throw new ManifestException('v5 adoption RU-only hold requires existing physical links');
            }
            if (isset($row['existing_local_value_ids'])) {
                $ids = $row['existing_local_value_ids'];
                if (!self::list($ids) || count(array_unique($ids)) !== count($ids)) {
                    throw new ManifestException('v5 adoption plan held physical IDs invalid');
                }
                foreach ($ids as $id) {
                    if (!is_int($id) || $id < 1) {
                        throw new ManifestException('v5 adoption plan held physical ID invalid');
                    }
                }
            }
            $holds[$holdKey] = true;
        }
        $productTargets = [];
        foreach ($data['product_targets'] ?? [] as $row) {
            self::keys($row, ['product_external_id', 'key', 'local_value_id'],
                ['reference_product_external_id', 'reference_target_product_id']);
            if (!self::id($row['product_external_id']) || !is_string($row['key'])
                || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $row['key'])
                || !is_int($row['local_value_id']) || $row['local_value_id'] < 1
                || isset($row['reference_product_external_id']) === isset($row['reference_target_product_id'])) {
                throw new ManifestException('v5 adoption plan product target invalid');
            }
            if (isset($row['reference_product_external_id'])
                && !self::id($row['reference_product_external_id'])) {
                throw new ManifestException('v5 adoption plan reference source ID invalid');
            }
            if (isset($row['reference_target_product_id'])
                && (!is_int($row['reference_target_product_id']) || $row['reference_target_product_id'] < 1)) {
                throw new ManifestException('v5 adoption plan reference target ID invalid');
            }
            $pair = $row['product_external_id'] . '|' . $row['key'];
            if (isset($productTargets[$pair]) || isset($holds[$pair])) {
                throw new ManifestException('v5 adoption plan product target duplicate/held');
            }
            $option = $options[$row['key']] ?? null;
            if ($option === null || ($option['mode'] ?? null) !== 'preserve_existing_equivalent_set'
                || !in_array($row['local_value_id'], $option['local_ids'], true)) {
                throw new ManifestException('v5 adoption plan product target lacks guarded value set');
            }
            $productTargets[$pair] = true;
        }
        if (array_key_exists('feature_link_adoptions', $data)
            && (empty($data['feature_lifecycle']) || !self::list($data['feature_link_adoptions']))) {
            throw new ManifestException('v5 feature-link adoptions require lifecycle mode and a list');
        }
        $claims = [];
        foreach ($data['feature_link_adoptions'] ?? [] as $row) {
            self::keys($row, ['product_external_id', 'local_product_id', 'option_key', 'local_value_id']);
            if (!self::id($row['product_external_id'])
                || !is_string($row['option_key']) || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $row['option_key'])
                || !is_int($row['local_product_id']) || $row['local_product_id'] < 1
                || !is_int($row['local_value_id']) || $row['local_value_id'] < 1) {
                throw new ManifestException('v5 feature-link adoption row invalid');
            }
            $option = $options[$row['option_key']] ?? null;
            $key = $row['local_product_id'] . ':' . $row['local_value_id'];
            if (isset($claims[$key]) || $option === null
                || ($option['local_id'] ?? null) !== $row['local_value_id']) {
                throw new ManifestException('v5 feature-link adoption lacks a unique pinned option');
            }
            foreach ($data['holds'] as $hold) {
                if ($hold['product_external_id'] === $row['product_external_id']
                    && ($hold['key'] === $row['option_key']
                        || in_array($row['local_value_id'], $hold['existing_local_value_ids'] ?? [], true))) {
                    throw new ManifestException('v5 feature-link adoption conflicts with a hold');
                }
            }
            $claims[$key] = true;
        }
    }

    private static function target(array $row, string $kind, array $languages = ['ru', 'uk']): void
    {
        $id = $row['local_id'];
        if ($id === null && $kind === 'parent' && isset($row['create_group'])
            && $row['expected_target'] === null) { return; }
        if ($id === null && $kind === 'option'
            && (isset($row['create_group']) || isset($row['share_create_group']))
            && $row['expected_target'] === null) { return; }
        if (!is_int($id) || $id < 1) {
            throw new ManifestException('v5 adoption plan target ID invalid');
        }
        if (isset($row['create_group']) || isset($row['share_create_group'])) {
            throw new ManifestException('v5 adoption plan create group claims existing target');
        }
        $target = $row['expected_target'];
        if (!is_array($target)) { throw new ManifestException('v5 adoption plan target guard missing'); }
        if ($kind === 'parent') {
            self::keys($target, array_merge(['id', 'external_id', 'url', 'owner_source_ids'], $languages));
        } else {
            self::keys($target, ['id', 'feature_id', 'ru', 'uk', 'translit', 'owner_keys']);
            if (!is_int($target['feature_id']) || $target['feature_id'] < 1
                || !is_string($target['translit'])) {
                throw new ManifestException('v5 adoption plan option target invalid');
            }
        }
        if ($target['id'] !== $id) {
            throw new ManifestException('v5 adoption plan target fingerprint invalid');
        }
        foreach ($languages as $language) {
            if (!is_string($target[$language]) || $target[$language] === '') {
                throw new ManifestException('v5 adoption plan target fingerprint invalid');
            }
        }
        $owners = $kind === 'parent' ? $target['owner_source_ids'] : $target['owner_keys'];
        if (!self::list($owners) || count(array_unique($owners)) !== count($owners)) {
            throw new ManifestException('v5 adoption plan target owners invalid');
        }
        foreach ($owners as $owner) {
            if (!self::id($owner)) { throw new ManifestException('v5 adoption plan target owner invalid'); }
        }
        if ($kind === 'parent' && (!is_string($target['external_id']) || !is_string($target['url']))) {
            throw new ManifestException('v5 adoption plan parent target invalid');
        }
    }

    private static function newParentGroups(array $parents): void
    {
        $groups = [];
        $physicalNames = [];
        foreach ($parents as $row) {
            if (!isset($row['create_group'])) { continue; }
            $group = $row['create_group'];
            if (!self::id($group) || $row['local_id'] !== null || $row['expected_target'] !== null) {
                throw new ManifestException('v5 adoption new-parent group invalid');
            }
            $meaning = [$row['expected_source'], $row['display']];
            if (isset($groups[$group]) && $groups[$group]['meaning'] !== $meaning) {
                throw new ManifestException('v5 adoption new-parent group semantics conflict');
            }
            $resolution = $row['filterable_resolution'] ?? null;
            if (isset($groups[$group])
                && ($groups[$group]['resolution_present'] !== ($resolution !== null)
                    || ($resolution !== null
                        && $groups[$group]['target_filterable'] !== $resolution['target']))) {
                throw new ManifestException('v5 adoption new-parent filter resolution incomplete/conflicting');
            }
            $slug = Translit::translitAlpha($row['display']['ru']);
            if ($slug === '' || (isset($physicalNames[$slug]) && $physicalNames[$slug] !== $group)) {
                throw new ManifestException('v5 adoption new-parent groups collide physically');
            }
            $physicalNames[$slug] = $group;
            if (!isset($groups[$group])) {
                $groups[$group] = ['meaning'=>$meaning, 'count'=>0,
                    'resolution_present'=>$resolution !== null,
                    'target_filterable'=>$resolution['target'] ?? null];
            }
            $groups[$group]['count']++;
        }
        foreach ($groups as $group) {
            if ($group['count'] < 2) {
                throw new ManifestException('v5 adoption new-parent group must have aliases');
            }
        }
    }

    private static function newOptionGroups(array $options, array $parents): void
    {
        $groups = [];
        $shared = [];
        $physicalCreateSlots = [];
        foreach ($options as $row) {
            if (isset($row['share_create_group'])) {
                if (isset($row['create_group']) || !self::id($row['share_create_group'])
                    || $row['local_id'] !== null || $row['expected_target'] !== null
                    || $row['display']['ru'] !== $row['expected_source']['ru']) {
                    throw new ManifestException('v5 adoption shared new-option group invalid');
                }
                $parent = explode(':', $row['key'], 2)[0];
                $parentPolicy = $parents[$parent] ?? null;
                if ($parentPolicy === null) {
                    throw new ManifestException('v5 adoption shared new-option parent lacks guard');
                }
                $physicalParent = $parentPolicy['local_id'] !== null
                    ? 'id:' . $parentPolicy['local_id'] : 'group:' . ($parentPolicy['create_group'] ?? '');
                $physicalName = $physicalParent . ':' . Translit::translitAlpha($row['expected_source']['ru']);
                if (substr($physicalName, -1) === ':') {
                    throw new ManifestException('v5 adoption shared new-option translit empty');
                }
                $owner = 'shared:' . $row['share_create_group'];
                if (isset($physicalCreateSlots[$physicalName])
                    && $physicalCreateSlots[$physicalName] !== $owner) {
                    throw new ManifestException('v5 adoption new-option groups collide physically');
                }
                $physicalCreateSlots[$physicalName] = $owner;
                $meaning = [$row['expected_source'], $row['display'], $physicalParent,
                    $parentPolicy['expected_source'], $parentPolicy['display']];
                $group = $row['share_create_group'];
                if (isset($shared[$group]) && $shared[$group]['meaning'] !== $meaning) {
                    throw new ManifestException('v5 adoption shared new-option semantics conflict');
                }
                if (!isset($shared[$group])) {
                    $shared[$group] = ['meaning'=>$meaning, 'parents'=>[]];
                }
                $shared[$group]['parents'][$parent] = true;
                continue;
            }
            if (!isset($row['create_group'])) { continue; }
            $group = $row['create_group'];
            $parent = explode(':', $row['key'], 2)[0];
            $sourceRu = $row['expected_source']['ru'];
            if (!self::id($group) || $row['local_id'] !== null
                || $row['expected_target'] !== null
                || $row['display']['ru'] !== $sourceRu
                || preg_match('/\pN/u', $sourceRu) === 1) {
                throw new ManifestException('v5 adoption new-option group invalid');
            }
            $meaning = [$parent, $sourceRu, $row['display']];
            if (isset($groups[$group]) && $groups[$group]['meaning'] !== $meaning) {
                throw new ManifestException('v5 adoption new-option group semantics conflict');
            }
            if (!isset($groups[$group])) { $groups[$group] = ['meaning'=>$meaning, 'count'=>0]; }
            $groups[$group]['count']++;
            $parentPolicy = $parents[$parent] ?? null;
            if ($parentPolicy !== null) {
                $physicalParent = $parentPolicy['local_id'] !== null
                    ? 'id:' . $parentPolicy['local_id'] : 'group:' . $parentPolicy['create_group'];
                $physicalName = $physicalParent . ':' . Translit::translitAlpha($sourceRu);
                $owner = 'legacy:' . $group;
                if (isset($physicalCreateSlots[$physicalName])
                    && $physicalCreateSlots[$physicalName] !== $owner) {
                    throw new ManifestException('v5 adoption new-option groups collide physically');
                }
                $physicalCreateSlots[$physicalName] = $owner;
            }
        }
        foreach ($groups as $token => $group) {
            if (isset($shared[$token])) {
                throw new ManifestException('v5 adoption mixed create group token');
            }
            if ($group['count'] < 2) {
                throw new ManifestException('v5 adoption new-option group must have aliases');
            }
        }
        foreach ($shared as $group) {
            if (count($group['parents']) < 2) {
                throw new ManifestException('v5 adoption shared new-option group needs two parents');
            }
        }
    }

    private static function splitTargets(array $row): void
    {
        $ids = $row['local_ids'];
        $targets = $row['expected_targets'];
        if (!self::list($ids) || count($ids) < 1 || !self::list($targets)
            || count($targets) !== count($ids) || count(array_unique($ids)) !== count($ids)) {
            throw new ManifestException('v5 adoption plan split set invalid');
        }
        $semantic = null;
        foreach ($ids as $index => $id) {
            if (!is_int($id) || $id < 1) { throw new ManifestException('v5 adoption plan split ID invalid'); }
            self::target(['local_id'=>$id, 'expected_target'=>$targets[$index]], 'option');
            $target = $targets[$index];
            $current = [$target['feature_id'], $target['ru'], $target['uk']];
            if (!isset($row['semantic_duration_months']) && $semantic !== null && $semantic !== $current) {
                throw new ManifestException('v5 adoption plan split target semantics differ');
            }
            $semantic = $current;
        }
        if (isset($row['semantic_duration_months'])) {
            $months = $row['semantic_duration_months'];
            if (!is_int($months) || $months < 1 || $months > 1200 || !isset($row['display'])) {
                throw new ManifestException('v5 adoption plan duration/display invalid');
            }
            self::labels($row['display'], ['ru', 'uk']);
            foreach (array_merge([$row['expected_source'], $row['display']], $targets) as $labels) {
                foreach (['ru', 'uk'] as $language) {
                    if (self::durationMonths($labels[$language]) !== $months) {
                        throw new ManifestException('v5 adoption plan duration proof mismatch');
                    }
                }
            }
        } elseif ($row['expected_source']['ru'] !== $semantic[1]
            || $row['expected_source']['uk'] !== $semantic[2]) {
            throw new ManifestException('v5 adoption plan split source/target semantics differ');
        }
    }

    private static function durationMonths(string $label): ?int
    {
        $label = mb_strtolower(trim($label), 'UTF-8');
        $label = preg_replace('/[\s\x{00A0}]+/u', ' ', $label);
        // Existing target translations can contain the other locale's period word; the
        // declared duration still has to match every before and after label exactly.
        $pattern = '/^([1-9][0-9]*) (год|года|лет|рік|роки|років|месяц|месяца|месяцев|мес\.?|місяць|місяця|місяців|місяці|міс\.?)$/uD';
        $years = ['год', 'года', 'лет', 'рік', 'роки', 'років'];
        if (preg_match($pattern, $label, $match) !== 1) { return null; }
        $count = (int) $match[1];
        return in_array($match[2], $years, true) ? $count * 12 : $count;
    }

    private static function labels($row, array $keys, array $languages = ['ru', 'uk']): void
    {
        self::keys($row, $keys);
        foreach ($languages as $language) {
            if (!is_string($row[$language]) || $row[$language] === '') {
                throw new ManifestException('v5 adoption plan bilingual labels invalid');
            }
        }
    }

    private static function keys($row, array $required, array $optional = []): void
    {
        if (!is_array($row) || !array_key_exists(0, $row) && $row === []) {
            // Empty objects are valid only where an explicit empty list is expected.
            throw new ManifestException('v5 adoption plan object invalid');
        }
        $actual = array_keys($row);
        foreach ($required as $key) {
            if (!array_key_exists($key, $row)) { throw new ManifestException('v5 adoption plan required field missing'); }
        }
        foreach ($actual as $key) {
            if (!in_array($key, $required, true) && !in_array($key, $optional, true)) {
                throw new ManifestException('v5 adoption plan unknown field');
            }
        }
    }

    private static function list($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }
    private static function id($value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*$/D', $value) === 1;
    }
    private static function sha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }
}
