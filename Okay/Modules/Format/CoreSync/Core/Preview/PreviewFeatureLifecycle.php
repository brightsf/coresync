<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

final class PreviewFeatureLifecycle
{
    private $map;
    private $values;
    private $engines;
    private $held = false;

    public function __construct(PreviewRows $map, PreviewFeatureValues $values, array $engines)
    {
        $this->map = $map;
        $this->values = $values;
        $this->engines = $engines;
    }

    public function acquire(): void
    {
        if ($this->held) {
            throw new ManifestException('v5 feature lifecycle lock is already held by this runner');
        }
        $this->held = true;
    }

    public function release(): void
    {
        $this->held = false;
    }

    public function run(int $productId, array $adoptions, callable $apply): void
    {
        foreach (['products', 'products_features_values', 'features_values', 'lang_features_values', 'format__coresync_map'] as $table) {
            if (!array_key_exists($table, $this->engines)) {
                throw new ManifestException('Offline feature-link adoption is missing table engine evidence: ' . $table);
            }
            if ($this->engines[$table] !== 'InnoDB') {
                throw new ManifestException('v5 feature-link adoption requires InnoDB catalog and ownership tables');
            }
        }
        // The native callback writes feature values, translations, links, and the ownership map.
        $this->map->transaction(function () use ($apply): void {
            $this->values->transaction($apply);
        });
    }
}
