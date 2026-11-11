<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/** Serialize a reviewed ownership claim and its first link reconciliation with catalog edits. */
final class FeatureLinkAdoptionTransaction
{
    private $pdo;
    private $db;
    private $queries;
    private $config;
    private $transactional = false;

    public function __construct(ExtendedPdo $pdo, Database $db, QueryFactory $queries, Config $config)
    {
        $this->pdo = $pdo;
        $this->db = $db;
        $this->queries = $queries;
        $this->config = $config;
    }

    public function run(int $productId, array $adoptions, callable $apply): void
    {
        $this->assertTransactionalTables();
        if ($this->pdo->inTransaction()
            || $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false
            || !$this->pdo->beginTransaction()) {
            throw new ManifestException('v5 feature-link adoption cannot open an exclusive transaction');
        }
        try {
            $this->lock('__products', 'id', $productId);
            $this->lock('__products_features_values', 'product_id', $productId);
            // Existing installations index (entity_type, external_id), not local_id.
            // Lock the entire indexed value-owner range once so no alias can be inserted
            // while the reviewed physical-owner set is checked.
            $this->lockValueOwnerRange();
            foreach ($adoptions as $adoption) {
                $valueId = $adoption['local_value_id'];
                $linkKey = 'p' . $productId . ':v' . $valueId;
                $this->lock('__features_values', 'id', $valueId);
                $this->lock('__lang_features_values', 'feature_value_id', $valueId);
                $this->lockMap(Contract::ENTITY_PRODUCT, $adoption['product_external_id']);
                $this->lockMap(Contract::ENTITY_FEATURE, explode(':', $adoption['option_key'], 2)[0]);
                $this->lockMap(Contract::ENTITY_FEATURE_VALUE, $adoption['option_key']);
                $this->lockMap(Contract::ENTITY_FEATURE_LINK, $linkKey);
                $this->lockMap(Contract::ENTITY_FEATURE_LINK_ADOPTION, $linkKey);
            }
            $apply();
            if (!$this->pdo->commit()) {
                throw new ManifestException('v5 feature-link adoption commit failed');
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertTransactionalTables(): void
    {
        if ($this->transactional) {
            return;
        }
        $prefix = (string) $this->config->get('db_prefix');
        $tables = array_map(static function (string $name) use ($prefix): string {
            return $prefix . $name;
        }, ['products', 'products_features_values', 'features_values', 'lang_features_values', 'format__coresync_map']);
        $engines = $this->pdo->fetchPairs(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:tables)',
            ['tables'=>$tables]
        );
        foreach ($tables as $table) {
            if (($engines[$table] ?? null) !== 'InnoDB') {
                throw new ManifestException('v5 feature-link adoption requires InnoDB catalog and ownership tables');
            }
        }
        $this->transactional = true;
    }

    private function lock(string $table, string $column, int $id): void
    {
        $query = $this->queries->newSelect()->cols([$column])->from($table)
            ->where($column . ' = :id')->bindValue('id', $id)->forUpdate();
        if ($this->db->query($query) === false) {
            throw new ManifestException('v5 feature-link adoption catalog lock failed');
        }
    }

    private function lockValueOwnerRange(): void
    {
        $query = $this->queries->newSelect()->cols(['id'])->from('__format__coresync_map')
            ->where('entity_type = :type')->bindValue('type', Contract::ENTITY_FEATURE_VALUE)->forUpdate();
        if ($this->db->query($query) === false) {
            throw new ManifestException('v5 feature-link adoption owner range lock failed');
        }
    }

    private function lockMap(string $type, string $externalId): void
    {
        $query = $this->queries->newSelect()->cols(['id'])->from('__format__coresync_map')
            ->where('entity_type = :type')->where('external_id = :external_id')
            ->bindValues(['type'=>$type, 'external_id'=>$externalId])->forUpdate();
        if ($this->db->query($query) === false) {
            throw new ManifestException('v5 feature-link adoption ownership lock failed');
        }
    }
}
