<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

final class FeatureLineageValidator
{
    public const VERSION = '1.0.0';

    /** @return array<string, array<string, mixed>> */
    public function events(array $manifest): array
    {
        if (!array_key_exists('feature_lineage', $manifest)) {
            return [];
        }
        $lineage = $manifest['feature_lineage'];
        $snapshotVersion = $manifest['snapshot_version'] ?? null;
        if (!is_int($snapshotVersion) || $snapshotVersion < 1
            || ($manifest['schema_version'] ?? null) !== Contract::SNAPSHOT_SCHEMA_V5
            || ($manifest['sync_mode'] ?? null) !== Contract::SYNC_MODE_FULL
            || !$this->hasKeys($lineage, ['version', 'source_instance', 'events'])
            || $lineage['version'] !== self::VERSION
            || !is_string($lineage['source_instance'])
            || !Contract::isValidSourceInstance($lineage['source_instance'])
            || !is_array($lineage['events']) || !Contract::isList($lineage['events'])) {
            throw new ManifestException('v5 feature lineage envelope invalid');
        }
        $events = [];
        $eventIds = [];
        foreach ($lineage['events'] as $event) {
            if (!$this->hasKeys($event, ['id', 'effective_version', 'action', 'source_id', 'target_id', 'option_map'])
                || !$this->validCatalogId($event['id'])
                || isset($eventIds[$event['id']])
                || !is_int($event['effective_version']) || $event['effective_version'] < 1
                || $event['effective_version'] > $snapshotVersion
                || !$this->validCatalogId($event['source_id']) || isset($events[$event['source_id']])
                || !is_array($event['option_map']) || !Contract::isList($event['option_map'])) {
                throw new ManifestException('v5 feature lineage event invalid or duplicate');
            }
            if ($event['action'] === 'retire') {
                if ($event['target_id'] !== null || $event['option_map'] !== []) {
                    throw new ManifestException('v5 feature retirement carries a merge target');
                }
            } elseif ($event['action'] !== 'merge' || !$this->validCatalogId($event['target_id'])
                || $event['source_id'] === $event['target_id']) {
                throw new ManifestException('v5 feature merge target invalid');
            }
            $sources = [];
            foreach ($event['option_map'] as $pair) {
                if (!$this->hasKeys($pair, ['source_id', 'target_id'])
                    || !$this->validCatalogId($pair['source_id']) || !$this->validCatalogId($pair['target_id'])
                    || isset($sources[$pair['source_id']])) {
                    throw new ManifestException('v5 feature lineage option mapping invalid or duplicate');
                }
                $sources[$pair['source_id']] = true;
            }
            $eventIds[$event['id']] = true;
            $events[$event['source_id']] = $event;
        }
        $checked = [];
        foreach (array_keys($events) as $source) {
            $path = [];
            while (isset($events[$source]) && !isset($checked[$source])) {
                if (isset($path[$source])) {
                    throw new ManifestException('v5 feature lineage contains a cycle');
                }
                $path[$source] = true;
                $current = $events[$source];
                $target = $current['target_id'];
                if ($target === null) {
                    break;
                }
                if (isset($events[$target])
                    && $events[$target]['effective_version'] < $current['effective_version']) {
                    throw new ManifestException('v5 feature lineage chain goes backwards in version');
                }
                $source = $target;
            }
            $checked += $path;
        }
        return $events;
    }

    public static function eventHash(array $event): string
    {
        $pairs = array_column($event['option_map'], 'target_id', 'source_id');
        ksort($pairs, SORT_STRING);
        return hash('sha256', json_encode([
            $event['id'], $event['effective_version'], $event['action'],
            $event['source_id'], $event['target_id'], $pairs,
        ], JSON_THROW_ON_ERROR));
    }

    private function validCatalogId($value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }

    private function hasKeys($value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys)
            && array_diff($keys, array_keys($value)) === [];
    }
}
