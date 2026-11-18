<?php

declare(strict_types=1);

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;

final class VariantInventoryStore
{
    public const TABLE = '__format__coresync_variant_inventory';
    public const READY = 'format_coresync_variant_inventory_ready';

    private $db;
    private $queries;

    public function __construct(Database $db, QueryFactory $queries)
    {
        $this->db = $db;
        $this->queries = $queries;
    }

    public static function origin(array $config): ?string
    {
        $url = parse_url((string) ($config['core_url'] ?? ''));
        if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
            return null;
        }

        return $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
    }

    public static function scope(array $config): string
    {
        return hash('sha256', (self::origin($config) ?? '') . '\0' . ($config['channel_code'] ?? ''));
    }

    public function replace(int $variantId, string $scope, array $rows): void
    {
        $this->query('INSERT INTO ' . self::TABLE . ' (variant_id, scope_key, payload)'
            . ' VALUES (:variant_id, :scope_key, :payload)'
            . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload)', [
                'variant_id' => $variantId,
                'scope_key' => $scope,
                'payload' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
    }

    public function forVariants(array $variantIds, string $scope): array
    {
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
        if (!$variantIds) {
            return [];
        }
        $this->query('SELECT variant_id, payload FROM ' . self::TABLE
            . ' WHERE scope_key = :scope_key AND variant_id IN (' . implode(',', $variantIds) . ')', ['scope_key' => $scope]);

        $result = [];
        foreach ($this->db->results() as $row) {
            $result[(int) $row->variant_id] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    private function query(string $statement, array $params): void
    {
        $query = $this->queries->newSqlQuery();
        $query->setStatement($statement);
        $query->bindValues($params);
        if ($this->db->query($query) === false) {
            throw new CoreSyncException('Variant inventory database operation failed');
        }
    }
}
