<?php

declare(strict_types=1);

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

final class VariantInventory
{
    public const CAPABILITY = 'variant_inventory';
    public const VERSION = '1.0.0';

    /** @return array|null Null preserves the last accepted inventory. */
    public static function replacement($block): ?array
    {
        if ($block === ['action' => 'hold']) {
            return null;
        }
        if (!is_array($block) || count($block) !== 2 || ($block['action'] ?? null) !== 'replace'
            || !is_array($block['rows'] ?? null) || !Contract::isList($block['rows'])) {
            throw new ManifestException('Invalid variant inventory action or rows');
        }

        $rows = [];
        foreach ($block['rows'] as $row) {
            if (!is_array($row) || count($row) !== 6
                || !is_string($row['key'] ?? null) || !preg_match('/^[a-z][a-z0-9:_-]{0,127}$/D', $row['key'])
                || !is_string($row['source'] ?? null) || trim($row['source']) === '' || strlen($row['source']) > 1024
                || !array_key_exists('warehouse', $row) || ($row['warehouse'] !== null
                    && (!is_string($row['warehouse']) || strlen($row['warehouse']) > 1024))
                || !array_key_exists('quantity', $row) || ($row['quantity'] !== null
                    && (!is_int($row['quantity']) || $row['quantity'] < 0))
                || !array_key_exists('observed_at', $row) || ($row['observed_at'] !== null
                    && (!is_string($row['observed_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $row['observed_at'])))
                || !is_string($row['core_path'] ?? null)
                || !preg_match('~^/(?:catalog/products/[1-9][0-9]*/edit\?tab=variants&variant=[1-9][0-9]*|matching\?match_id=[1-9][0-9]*&supplier_offer_id=[1-9][0-9]*&import_profile_id=[1-9][0-9]*)$~D', $row['core_path'])) {
                throw new ManifestException('Invalid variant inventory row');
            }
            if (isset($rows[$row['key']])) {
                throw new ManifestException('Duplicate variant inventory row identity');
            }
            $rows[$row['key']] = $row;
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }
}
