<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Config;
use Psr\Log\LoggerInterface;

/**
 * Bounded, fail-closed housekeeping for updater-owned code copies only.
 * Callers hold the same LockHelper as SyncRunner and the updater for the whole operation.
 */
class RetentionManager
{
    private const MAX_ENTRIES = 256;
    private const MAX_VERIFIED_PER_PREVIEW = 8;
    private const MAX_DELETIONS_PER_APPLY = 4;
    private const MAX_TREE_FILES = 6000;
    private const MAX_TREE_BYTES = 134217728;
    private const FAILED_AGE_SECONDS = 604800;
    private const WORK_AGE_SECONDS = 86400;
    private const MIN_UPDATE_FREE_BYTES = 167772160; // 32 MiB artifact + 64 MiB extract + 64 MiB reserve.
    private const CONFIRM = 'DELETE_OBSOLETE_COPIES';

    /** @var Config */
    private $config;
    /** @var SchemaMarker */
    private $schemaMarker;
    /** @var SchemaMigrationCatalog */
    private $schemaCatalog;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        Config $config,
        SchemaMarker $schemaMarker,
        SchemaMigrationCatalog $schemaCatalog,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->schemaMarker = $schemaMarker;
        $this->schemaCatalog = $schemaCatalog;
        $this->logger = $logger;
    }

    /** A false/unknown disk reading blocks before the updater downloads or swaps any bytes. */
    public function assertSpaceForUpdate(): void
    {
        $root = realpath($this->root());
        $free = $root === false ? false : @disk_free_space($root);
        if ($free === false || $free < self::MIN_UPDATE_FREE_BYTES) {
            throw new UpdateException('Обновление: недостаточно подтверждённого свободного места для артефакта и отката');
        }
    }

    public function currentSchemaVersion(): ?string
    {
        return $this->schemaMarker->appliedVersion();
    }

    /** Apache defense in depth; the deployment still requires a real HTTP deny readback. */
    public function ensurePrivateRoot(): bool
    {
        $base = realpath($this->root());
        if ($base === false) { return false; }
        $updates = $this->updatesRoot();
        if (is_link($updates) || (!is_dir($updates) && !@mkdir($updates, 0755, true))) { return false; }
        if (realpath($updates) !== $base . '/files/coresync/updates') { return false; }
        $deny = $updates . '/.htaccess';
        if (is_link($deny) || (file_exists($deny) && !is_file($deny))) { return false; }
        if (!file_exists($deny) && @file_put_contents($deny, "Deny from all\nRequire all denied\n", LOCK_EX) === false) {
            return false;
        }
        return true;
    }

    /** Record a completed swap. Missing/invalid metadata makes the backup protected, never eligible. */
    public function recordRollback(string $path, string $fromVersion, string $toVersion, ?string $appliedSchema): bool
    {
        return $this->recordCopy($path, 'rollback', $fromVersion, $toVersion, $appliedSchema);
    }

    /** Keep failed content for diagnosis; only a completed restore may call this. */
    public function recordFailed(string $path, string $fromVersion, string $toVersion, ?string $appliedSchema): bool
    {
        return $this->recordCopy($path, 'failed', $fromVersion, $toVersion, $appliedSchema);
    }

    /** Mark work before the first download byte so a crashed operation can later be identified. */
    public function recordWork(string $path): bool
    {
        try {
            $name = basename($path);
            if (!$this->recognizedName($name, 'work')) { return false; }
            if (!$this->ensurePrivateRoot()) { return false; }
            $this->assertDirectChild($path);
            if (!is_dir($path) && !@mkdir($path, 0755)) { return false; }
            if (is_link($path)) { return false; }
            return $this->writeJson($path . '/.retention-work.json', [
                'schema' => 1, 'type' => 'work', 'name' => $name,
                'created_at' => time(), 'operation_id' => bin2hex(random_bytes(12)),
            ]);
        } catch (\Throwable $e) {
            $this->warning('CoreSync retention: work marker unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /** Remove this cycle's own work with the same no-symlink guard as periodic cleanup. */
    public function finishWork(string $path): bool
    {
        try {
            $this->assertDirectChild($path);
            if (!is_dir($path)) { return true; }
            $marker = $this->readJson($path . '/.retention-work.json');
            if (!$this->validWorkMarker($marker, basename($path))) { return false; }
            $this->removeTree($path);
            return !file_exists($path) && !is_link($path);
        } catch (\Throwable $e) {
            $this->warning('CoreSync retention: work cleanup failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Cached journal only: opening the admin page never walks copies or snapshots. */
    public function status(): array
    {
        $journal = $this->confirmedUpdatesRoot()
            ? $this->readJson($this->updatesRoot() . '/.retention-journal.json') : null;
        return [
            'last_cleanup_at' => is_array($journal) ? ($journal['at'] ?? null) : null,
            'bytes_freed' => is_array($journal) ? (int) ($journal['bytes_freed'] ?? 0) : 0,
            'last_error' => is_array($journal) ? ($journal['last_error'] ?? null) : null,
            'rule' => 'two_compatible_rollbacks_latest_failed_7d_work_24h',
        ];
    }

    /** @return array{plan_sha256:string,items:array,totals:array} */
    public function preview(): array
    {
        $root = $this->updatesRoot();
        if (!is_dir($root)) { return $this->plan([], null); }
        if (!$this->confirmedUpdatesRoot()) {
            throw new \RuntimeException('retention root is not a confirmed directory');
        }
        $names = [];
        $iterator = new \DirectoryIterator($root);
        foreach ($iterator as $entry) {
            if ($entry->isDot() || $entry->getFilename() === '.htaccess' || $entry->getFilename() === '.retention-journal.json') { continue; }
            $names[] = $entry->getFilename();
            if (count($names) > self::MAX_ENTRIES) { throw new \RuntimeException('retention inventory exceeds bounded scan'); }
        }
        sort($names, SORT_STRING);
        $rollbacks = array_values(array_filter($names, function (string $name): bool {
            return $this->recognizedName($name, 'rollback');
        }));
        rsort($rollbacks, SORT_STRING);
        $immediate = $rollbacks[0] ?? null;
        $protectedRollbacks = array_slice($rollbacks, 0, 2);
        $failed = array_values(array_filter($names, function (string $name): bool {
            return $this->recognizedName($name, 'failed');
        }));
        rsort($failed, SORT_STRING);
        $latestFailed = $failed[0] ?? null;
        $applied = $this->schemaMarker->appliedVersion();
        $migrationVersions = array_keys($this->schemaCatalog->migrations());
        // Names and timestamps alone do not establish two usable rollback points.
        // If either newest copy is unverified, keep every older copy for review.
        $rollbackChainVerified = true;
        foreach ($protectedRollbacks as $protectedName) {
            $protectedPath = $root . '/' . $protectedName;
            try {
                $meta = $this->readJson($protectedPath . '/.retention.json');
                if (is_link($protectedPath) || !is_dir($protectedPath)
                    || !$this->validCopyMetadata($meta, $protectedName, 'rollback', $protectedPath)
                    || !$this->schemaCompatible($meta, $applied, $migrationVersions)) {
                    $rollbackChainVerified = false;
                    break;
                }
            } catch (\Throwable $e) {
                $rollbackChainVerified = false;
                break;
            }
        }
        $items = [];
        $verified = 0;
        foreach ($names as $name) {
            $path = $root . '/' . $name;
            $type = $this->typeOf($name);
            $item = [
                'id' => $name, 'type' => $type, 'version' => null, 'created_at' => null,
                'size_bytes' => 0, 'decision' => 'keep', 'reason' => 'unknown_or_legacy',
                'snapshot_sha256' => null,
                'relative_path' => 'files/coresync/updates/' . $name,
            ];
            if ($type === null || is_link($path) || !is_dir($path)) { $items[] = $item; continue; }
            $protectedReason = $name === $immediate ? 'immediate_rollback' : null;
            if ($protectedReason === null && $type === 'rollback' && in_array($name, $protectedRollbacks, true)) {
                $protectedReason = 'last_two_rollbacks';
            }
            if ($type === 'failed' && $name === $latestFailed) { $protectedReason = 'latest_diagnostic'; }
            if ($type === 'rollback' && !$rollbackChainVerified && $protectedReason === null) {
                $item['reason'] = 'rollback_chain_unverified'; $items[] = $item; continue;
            }
            if (is_file($path . '/.retention-hold') && !is_link($path . '/.retention-hold')) {
                $item['reason'] = 'investigation_hold'; $items[] = $item; continue;
            }
            if ($protectedReason === null) {
                $marker = $this->readJson($path . ($type === 'work' ? '/.retention-work.json' : '/.retention.json'));
                $plausible = $type === 'work' ? $this->validWorkMarker($marker, $name)
                    : $this->validCopyMetadataShape($marker, $name, $type);
                if (!$plausible) { $items[] = $item; continue; }
                if ($verified >= self::MAX_VERIFIED_PER_PREVIEW) {
                    $item['reason'] = 'scan_budget'; $items[] = $item; continue;
                }
                $verified++;
            }
            try {
                $meta = $this->readJson($path . '/.retention.json');
                if ($type === 'work') {
                    $meta = $this->readJson($path . '/.retention-work.json');
                    if (!$this->validWorkMarker($meta, $name)) { $items[] = $item; continue; }
                    $item['created_at'] = gmdate('c', (int) $meta['created_at']);
                    $tree = $this->treeInfo($path, ['.retention-work.json']);
                    $markerHash = @hash_file('sha256', $path . '/.retention-work.json');
                    if (!is_string($markerHash)) { throw new \RuntimeException('unreadable work marker'); }
                    $item['snapshot_sha256'] = hash('sha256', $tree['sha256'] . "\0" . $markerHash);
                    $item['size_bytes'] = $tree['bytes'] + (int) @filesize($path . '/.retention-work.json');
                    if (time() - (int) $meta['created_at'] < self::WORK_AGE_SECONDS) {
                        $item['reason'] = 'work_24h'; $items[] = $item; continue;
                    }
                    $item['decision'] = 'delete'; $item['reason'] = 'orphan_work_24h';
                } elseif ($this->validCopyMetadata($meta, $name, $type, $path)) {
                    $item['version'] = $type === 'rollback' ? $meta['from_version'] : $meta['to_version'];
                    $item['created_at'] = gmdate('c', (int) $meta['completed_at']);
                    $item['size_bytes'] = (int) $meta['bytes'] + (int) @filesize($path . '/.retention.json');
                    if ($protectedReason !== null) {
                        $item['reason'] = $protectedReason;
                    } elseif ($type === 'failed' && time() - (int) $meta['completed_at'] < self::FAILED_AGE_SECONDS) {
                        $item['reason'] = 'failed_7d';
                    } elseif ($type === 'rollback' && !$this->schemaCompatible($meta, $applied, $migrationVersions)) {
                        $item['reason'] = 'schema_uncertain';
                    } else {
                        $item['decision'] = 'delete';
                        $item['reason'] = $type === 'rollback' ? 'older_compatible_rollback' : 'old_failed';
                    }
                }
            } catch (\Throwable $e) {
                $item['reason'] = 'unverified_contents';
            }
            if ($protectedReason !== null) { $item['decision'] = 'keep'; $item['reason'] = $protectedReason; }
            $items[] = $item;
        }

        return $this->plan($items, $applied);
    }

    /** Caller has the shared LockHelper; a changed preview aborts before any deletion. */
    public function apply(string $expectedPlanSha256): array
    {
        $plan = $this->preview();
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedPlanSha256)
            || !hash_equals($plan['plan_sha256'], $expectedPlanSha256)) {
            throw new \RuntimeException('retention preview is stale');
        }
        if (!is_dir($this->updatesRoot())) {
            return ['deleted_count' => 0, 'bytes_freed' => 0, 'errors' => [], 'summary' => $this->status()];
        }
        $deleted = 0;
        $bytes = 0;
        $errors = [];
        $attempted = 0;
        foreach ($plan['items'] as $item) {
            if ($item['decision'] !== 'delete') { continue; }
            if ($attempted >= self::MAX_DELETIONS_PER_APPLY) { break; }
            $attempted++;
            $path = $this->updatesRoot() . '/' . $item['id'];
            try {
                $this->assertDirectChild($path);
                if (is_link($path) || !is_dir($path)) { throw new \RuntimeException('candidate changed'); }
                $current = $this->preview();
                $same = null;
                foreach ($current['items'] as $row) {
                    if ($row['id'] === $item['id']) { $same = $row; break; }
                }
                if ($same !== $item) { throw new \RuntimeException('candidate changed'); }
                $this->removeTree($path);
                $deleted++;
                $bytes += (int) $item['size_bytes'];
            } catch (\Throwable $e) {
                $errors[] = ['id' => $item['id'], 'reason' => 'delete_failed'];
                $this->warning('CoreSync retention: deletion failed for ' . $item['id'] . ': ' . $e->getMessage());
            }
        }
        if (!$this->writeJournal($deleted, $bytes, $errors === [] ? null : 'delete_failed')) {
            $errors[] = ['id' => 'journal', 'reason' => 'write_failed'];
        }

        return ['deleted_count' => $deleted, 'bytes_freed' => $bytes, 'errors' => $errors, 'summary' => $this->status()];
    }

    /** Bounded periodic cleanup under the existing SyncRunner lock. */
    public function cleanup(): array
    {
        if (!is_dir($this->updatesRoot())) {
            return ['deleted_count' => 0, 'bytes_freed' => 0, 'errors' => [], 'summary' => $this->status()];
        }
        try {
            $plan = $this->preview();
            return $this->apply($plan['plan_sha256']);
        } catch (\Throwable $e) {
            $this->warning('CoreSync retention: bounded inventory refused: ' . $e->getMessage());
            $this->writeJournal(0, 0, 'inventory_failed');
            return [
                'deleted_count' => 0, 'bytes_freed' => 0,
                'errors' => [['id' => 'inventory', 'reason' => 'inventory_failed']],
                'summary' => $this->status(),
            ];
        }
    }

    private function recordCopy(string $path, string $type, string $from, string $to, ?string $schema): bool
    {
        try {
            $name = basename($path);
            if (!$this->recognizedName($name, $type) || $schema === null) { return false; }
            if (!$this->ensurePrivateRoot()) { return false; }
            $this->assertDirectChild($path);
            if (is_link($path) || !is_dir($path)) { return false; }
            $version = $this->versionAt($path);
            if ($version !== ($type === 'rollback' ? $from : $to)) { return false; }
            $tree = $this->treeInfo($path, ['.retention.json']);
            return $this->writeJson($path . '/.retention.json', [
                'schema' => 1, 'type' => $type, 'name' => $name,
                'from_version' => $from, 'to_version' => $to,
                'applied_schema_at_swap' => $schema, 'completed_at' => time(),
                'bytes' => $tree['bytes'], 'tree_sha256' => $tree['sha256'],
                'operation_id' => bin2hex(random_bytes(12)),
            ]);
        } catch (\Throwable $e) {
            $this->warning('CoreSync retention: copy metadata unavailable: ' . $e->getMessage());
            return false;
        }
    }

    private function validCopyMetadata($meta, string $name, string $type, string $path): bool
    {
        if (!$this->validCopyMetadataShape($meta, $name, $type)) { return false; }
        if ($this->versionAt($path) !== ($type === 'rollback' ? $meta['from_version'] : $meta['to_version'])) { return false; }
        $tree = $this->treeInfo($path, ['.retention.json']);
        return $tree['bytes'] === $meta['bytes'] && hash_equals($meta['tree_sha256'], $tree['sha256']);
    }

    private function validCopyMetadataShape($meta, string $name, string $type): bool
    {
        return is_array($meta) && ($meta['schema'] ?? null) === 1 && ($meta['type'] ?? null) === $type
            && ($meta['name'] ?? null) === $name && is_string($meta['from_version'] ?? null)
            && is_string($meta['to_version'] ?? null) && is_string($meta['applied_schema_at_swap'] ?? null)
            && is_int($meta['completed_at'] ?? null) && is_int($meta['bytes'] ?? null)
            && is_string($meta['tree_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $meta['tree_sha256']) === 1
            && is_string($meta['operation_id'] ?? null)
            && preg_match('/^[a-f0-9]{24}$/', $meta['operation_id']) === 1;
    }

    private function validWorkMarker($meta, string $name): bool
    {
        return is_array($meta) && ($meta['schema'] ?? null) === 1 && ($meta['type'] ?? null) === 'work'
            && ($meta['name'] ?? null) === $name && is_int($meta['created_at'] ?? null)
            && $meta['created_at'] > 0 && $meta['created_at'] <= time()
            && is_string($meta['operation_id'] ?? null)
            && preg_match('/^[a-f0-9]{24}$/', $meta['operation_id']) === 1;
    }

    private function schemaCompatible(array $meta, ?string $applied, array $migrationVersions): bool
    {
        if ($applied === null || version_compare($applied, $meta['applied_schema_at_swap'], '<')) { return false; }
        if (version_compare($meta['from_version'], $meta['applied_schema_at_swap'], '<')) { return false; }
        foreach ($migrationVersions as $version) {
            if (version_compare($version, $meta['from_version'], '>') && version_compare($version, $applied, '<=')) {
                return false;
            }
        }
        return true;
    }

    private function plan(array $items, ?string $applied): array
    {
        $remaining = self::MAX_DELETIONS_PER_APPLY;
        foreach ($items as &$item) {
            if ($item['decision'] !== 'delete') { continue; }
            if ($remaining === 0) {
                $item['decision'] = 'keep';
                $item['reason'] = 'apply_budget';
            } else {
                $remaining--;
            }
        }
        unset($item);
        $deletable = 0; $bytes = 0; $protected = 0; $knownBytes = 0; $unmeasured = 0;
        foreach ($items as $item) {
            if ($item['decision'] === 'delete') { $deletable++; $bytes += (int) $item['size_bytes']; }
            else { $protected++; }
            $knownBytes += (int) $item['size_bytes'];
            // A confirmed work marker or code copy always has nonzero bytes; zero means
            // the row's contents were deliberately not measured, whatever its keep reason.
            if ($item['size_bytes'] === 0) {
                $unmeasured++;
            }
        }
        $totals = [
            'deletable_count' => $deletable, 'deletable_bytes' => $bytes,
            'protected_count' => $protected, 'known_bytes' => $knownBytes,
            'unmeasured_count' => $unmeasured,
        ];
        $hash = hash('sha256', json_encode([$applied, $items, $totals], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['plan_sha256' => $hash, 'items' => $items, 'totals' => $totals];
    }

    private function typeOf(string $name): ?string
    {
        foreach (['rollback', 'failed', 'work'] as $type) {
            if ($this->recognizedName($name, $type)) { return $type; }
        }
        return null;
    }

    private function recognizedName(string $name, string $type): bool
    {
        if ($type === 'work') { return preg_match('/^work-[a-zA-Z0-9.]{10}$/', $name) === 1; }
        return preg_match('/^' . $type . '-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $name) === 1;
    }

    private function versionAt(string $path): ?string
    {
        $file = $path . '/Init/module.json';
        if (is_link($file) || !is_file($file)) { return null; }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
    }

    private function treeInfo(string $root, array $excluded): array
    {
        $hash = hash_init('sha256'); $bytes = 0; $count = 0;
        $this->walk($root, '', $excluded, $hash, $bytes, $count, false);
        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    /** @param resource $hash */
    private function walk(string $root, string $relative, array $excluded, $hash, int &$bytes, int &$count, bool $delete): void
    {
        $dir = $relative === '' ? $root : $root . '/' . $relative;
        if (is_link($dir) || !is_dir($dir)) { throw new \RuntimeException('unsafe directory in retention tree'); }
        $names = @scandir($dir);
        if (!is_array($names)) { throw new \RuntimeException('unreadable retention directory'); }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || ($relative === '' && in_array($name, $excluded, true))) { continue; }
            $childRelative = $relative === '' ? $name : $relative . '/' . $name;
            $child = $root . '/' . $childRelative;
            $stat = @lstat($child);
            if ($stat === false || is_link($child)) { throw new \RuntimeException('symlink or vanished retention entry'); }
            $count++;
            if ($count > self::MAX_TREE_FILES) { throw new \RuntimeException('retention tree file cap exceeded'); }
            $mode = $stat['mode'] & 0170000;
            if ($mode === 0040000) {
                hash_update($hash, "d\0" . $childRelative . "\n");
                $this->walk($root, $childRelative, [], $hash, $bytes, $count, $delete);
                if ($delete && !@rmdir($child)) { throw new \RuntimeException('retention directory removal failed'); }
            } elseif ($mode === 0100000) {
                $size = (int) $stat['size'];
                $bytes += $size;
                if ($bytes > self::MAX_TREE_BYTES) { throw new \RuntimeException('retention tree byte cap exceeded'); }
                $digest = @hash_file('sha256', $child);
                if (!is_string($digest)) { throw new \RuntimeException('unreadable retention file'); }
                hash_update($hash, "f\0" . $childRelative . "\0" . $size . "\0" . $digest . "\n");
                if ($delete && !@unlink($child)) { throw new \RuntimeException('retention file removal failed'); }
            } else {
                throw new \RuntimeException('unsupported retention entry');
            }
        }
    }

    private function removeTree(string $path): void
    {
        $hash = hash_init('sha256'); $bytes = 0; $count = 0;
        $this->walk($path, '', [], $hash, $bytes, $count, true);
        if (!@rmdir($path)) { throw new \RuntimeException('retention root removal failed'); }
    }

    private function assertDirectChild(string $path): void
    {
        if (!$this->confirmedUpdatesRoot()) { throw new \RuntimeException('unconfirmed updates root'); }
        $parent = realpath(dirname($path));
        $updates = realpath($this->updatesRoot());
        if ($updates === false || $parent === false || $parent !== $updates || basename($path) === '.' || basename($path) === '..') {
            throw new \RuntimeException('path is outside confirmed updates root');
        }
    }

    private function confirmedUpdatesRoot(): bool
    {
        $base = realpath($this->root());
        $updates = $this->updatesRoot();
        return $base !== false && !is_link($updates)
            && realpath($updates) === $base . '/files/coresync/updates';
    }

    private function readJson(string $path)
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 8192) { return null; }
        return json_decode((string) @file_get_contents($path), true);
    }

    private function writeJournal(int $deleted, int $bytes, ?string $error): bool
    {
        if (!$this->confirmedUpdatesRoot()) { return false; }
        return $this->writeJson($this->updatesRoot() . '/.retention-journal.json', [
            'at' => gmdate('c'), 'deleted_count' => $deleted,
            'bytes_freed' => $bytes, 'last_error' => $error,
        ]);
    }

    private function writeJson(string $path, array $data): bool
    {
        if (is_link($path)) { return false; }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { return false; }
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) { @unlink($temp); return false; }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) { @unlink($temp); return false; }
        return true;
    }

    private function root(): string { return rtrim((string) $this->config->get('root_dir'), '/\\'); }
    private function updatesRoot(): string { return $this->root() . '/files/coresync/updates'; }
    private function warning(string $message): void { if ($this->logger !== null) { $this->logger->warning($message); } }
}
