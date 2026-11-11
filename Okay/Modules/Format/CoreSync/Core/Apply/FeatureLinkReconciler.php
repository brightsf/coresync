<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Reconcile v5 physical links without assigning ownership to pre-existing rows. */
class FeatureLinkReconciler
{
    /** @var MapGateway */
    private $map;
    /** @var mixed FeaturesValuesEntity or a standalone fixture */
    private $values;
    /** @var Languages */
    private $languages;
    /** @var array<string, int> */
    private $languageIds;
    /** @var array<int, string|null> */
    private $semantics = [];
    /** @var array<int, bool> synthetic values without a complete localized identity */
    private $exactOnly = [];

    /** @param mixed $values @param array<string, int> $languageIds */
    public function __construct(MapGateway $map, $values, Languages $languages, array $languageIds)
    {
        $this->map = $map;
        $this->values = $values;
        $this->languages = $languages;
        $this->languageIds = $languageIds;
        ksort($this->languageIds, SORT_STRING);
    }

    /** @param array<int, int> $desiredValueIds @param array<int, int> $exactOnlyValueIds */
    public function reconcile(
        int $productId,
        array $desiredValueIds,
        array $exactOnlyValueIds = [],
        ?ApplyStats $stats = null
    ): void
    {
        if ($productId < 1 || $this->languageIds === []) {
            throw new ManifestException('product v5 feature links have invalid product/languages');
        }
        $desired = [];
        foreach ($desiredValueIds as $valueId) {
            if ((int) $valueId < 1) {
                throw new ManifestException('product v5 desired option local ID invalid');
            }
            $desired[(int) $valueId] = (int) $valueId;
        }
        $this->exactOnly = array_fill_keys($exactOnlyValueIds, true);
        $linked = array_fill_keys($this->map->productValueIdsChecked($this->values, $productId), true);
        $ledger = [];
        foreach ($this->map->featureLinkRowsForProduct($productId) as $row) {
            $valueId = $this->ledgerValueId($row, $productId);
            if (isset($ledger[$valueId])) {
                throw new ManifestException('product v5 duplicate feature-link ledger key');
            }
            $ledger[$valueId] = $row;
        }

        // Decide the interrupted state before any link mutation. A pending row
        // plus an existing physical link cannot establish who inserted it.
        foreach ($ledger as $valueId => $row) {
            $state = (string) $row->applied_hash;
            if ($state === 'pending' && isset($linked[$valueId])) {
                $this->map->recordUpdateChecked($row, $valueId, 'protected');
                throw new ManifestException('product v5 interrupted feature link has ambiguous ownership');
            }
            if (!isset($linked[$valueId]) && $state !== 'pending') {
                $this->map->removeChecked($row);
                unset($ledger[$valueId]);
            }
        }

        $desiredSemantics = [];
        foreach ($desired as $valueId) {
            $desiredSemantics[$this->semantic($valueId, true)] = true;
        }
        $linkedSemantics = [];
        foreach (array_keys($linked) as $valueId) {
            $semantic = $this->semantic($valueId, false);
            if ($semantic !== null) {
                $linkedSemantics[$semantic] = true;
            }
        }

        foreach ($desired as $valueId) {
            $semantic = $this->semantic($valueId, true);
            if (isset($linkedSemantics[$semantic])) {
                continue;
            }
            $row = $ledger[$valueId] ?? null;
            if ($row === null) {
                $key = $this->key($productId, $valueId);
                $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_LINK, $key, $valueId, 'pending');
                $row = $this->map->find(Contract::ENTITY_FEATURE_LINK, $key);
                if ($row === null) {
                    throw new ManifestException('product v5 pending feature-link ledger missing');
                }
                $ledger[$valueId] = $row;
            } elseif ((string) $row->applied_hash !== 'pending') {
                throw new ManifestException('product v5 protected/owned link disappeared before insertion');
            }
            $this->map->addProductValueChecked($this->values, $productId, $valueId);
            $this->map->recordUpdateChecked($row, $valueId, 'owned');
            if ($stats !== null) { ++$stats->featureLinksAdded; }
            $linked[$valueId] = true;
            $linkedSemantics[$semantic] = true;
            $ledger[$valueId] = $this->map->find(Contract::ENTITY_FEATURE_LINK, $this->key($productId, $valueId));
        }

        if ($stats !== null) {
            foreach (array_keys($linked) as $valueId) {
                if (isset($ledger[$valueId]) && (string) $ledger[$valueId]->applied_hash === 'owned') {
                    continue;
                }
                $semantic = $this->semantic($valueId, false);
                if ($semantic === null || !isset($desiredSemantics[$semantic])) {
                    ++$stats->featureLinksProtected;
                }
            }
        }

        foreach ($ledger as $valueId => $row) {
            if ((string) $row->applied_hash === 'pending' && !isset($linked[$valueId])) {
                $this->map->removeChecked($row);
                continue;
            }
            if ((string) $row->applied_hash !== 'owned' || !isset($linked[$valueId])) {
                continue;
            }
            $semantic = $this->semantic($valueId, false);
            if ($semantic === null || !isset($desiredSemantics[$semantic])) {
                $this->map->deleteProductValueChecked($this->values, $productId, $valueId);
                $this->map->removeChecked($row);
                if ($stats !== null) { ++$stats->featureLinksRemoved; }
            }
        }
    }

    /** @param object $row */
    private function ledgerValueId($row, int $productId): int
    {
        $key = (string) ($row->external_id ?? '');
        if ((string) ($row->entity_type ?? '') !== Contract::ENTITY_FEATURE_LINK
            || !preg_match('/^p([1-9][0-9]*):v([1-9][0-9]*)$/D', $key, $matches)
            || (int) $matches[1] !== $productId
            || (int) $matches[2] < 1
            || (int) ($row->local_id ?? 0) !== (int) $matches[2]
            || !in_array((string) ($row->applied_hash ?? ''), ['pending', 'owned', 'protected'], true)) {
            throw new ManifestException('product v5 invalid feature-link ledger row');
        }
        return (int) $matches[2];
    }

    private function key(int $productId, int $valueId): string
    {
        return 'p' . $productId . ':v' . $valueId;
    }

    /** Identity across declared content languages; unknown baseline links remain unowned. */
    private function semantic(int $valueId, bool $required): ?string
    {
        if (array_key_exists($valueId, $this->semantics)) {
            return $this->semantics[$valueId];
        }
        if (isset($this->exactOnly[$valueId])) {
            return $this->semantics[$valueId] = 'physical:' . $valueId;
        }
        $previous = (int) $this->languages->getLangId();
        $labels = [];
        $featureId = null;
        try {
            foreach ($this->languageIds as $language => $languageId) {
                $this->languages->setLangId($languageId);
                $row = $this->map->findEntityOne($this->values, ['id' => $valueId]);
                if ($row === false || (int) ($row->id ?? 0) !== $valueId
                    || (int) ($row->feature_id ?? 0) < 1 || (string) ($row->value ?? '') === '') {
                    if ($required) {
                        throw new ManifestException('product v5 required localized feature value missing');
                    }
                    return $this->semantics[$valueId] = null;
                }
                if ($featureId !== null && $featureId !== (int) $row->feature_id) {
                    throw new ManifestException('product v5 physical feature value changed feature');
                }
                $featureId = (int) $row->feature_id;
                $labels[$language] = (string) $row->value;
            }
        } finally {
            $this->languages->setLangId($previous);
        }
        return $this->semantics[$valueId] = hash('sha256', (string) json_encode(
            [$featureId, $labels], JSON_UNESCAPED_UNICODE
        ));
    }
}
