<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Okay\Core\Settings;
use Psr\Log\LoggerInterface;

/** Bounded rotation of verified numeric exchange staging only; callers own LockHelper. */
class ExchangeRetentionManager
{
    public const POLICY_KEY = 'coresync_exchange_retention_policy';
    public const CONFIRM = 'DELETE_EXCHANGE_PACKAGES';
    private const PAGE = 32;
    private const MAX_DIRS = 8192;
    private const MAX_GLOBAL_FILES = 65536;
    private const MAX_FILES = 128;
    private const MAX_HASH_BYTES = 536870912; // Cumulative full-content read per preview/apply step.
    private const MAX_SCAN_SECONDS = 5;
    private const MAX_DELETIONS = 2;
    private const DIAGNOSTIC_DAYS = 90;
    private const MAX_DIAGNOSTICS = 1000;

    /** @var Config */ private $config;
    /** @var Settings */ private $settings;
    /** @var ExchangeRetentionEvidence */ private $evidence;
    /** @var LoggerInterface|null */ private $logger;
    /** @var int */ private $hashBytes = 0;
    /** @var float */ private $scanDeadline = 0.0;

    public function __construct(Config $config, Settings $settings, ExchangeRetentionEvidence $evidence, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->settings = $settings;
        $this->evidence = $evidence;
        $this->logger = $logger;
    }

    public function policy(): array
    {
        $defaults = [
            'enabled' => false,
            'applied' => ['max_age_days' => 14, 'max_count' => 20, 'max_bytes' => 2147483648],
            'failed' => ['max_age_days' => 7, 'max_count' => 10, 'max_bytes' => 1073741824],
            'diagnostic_days' => self::DIAGNOSTIC_DAYS,
        ];
        $stored = $this->settings->get(self::POLICY_KEY);
        if (!is_array($stored)) { return $defaults; }
        try { return $this->validatedPolicy($stored); }
        catch (\Throwable $e) { return $defaults; }
    }

    public function savePolicy(array $input): array
    {
        $policy = $this->validatedPolicy($input);
        $this->settings->set(self::POLICY_KEY, $policy);
        return $policy;
    }

    public function status(): array
    {
        $journal = $this->readStateJson('journal.json');
        $diagnostics = $this->readStateJson('diagnostics.json');
        return [
            'last_cleanup_at' => is_array($journal) ? ($journal['at'] ?? null) : null,
            'bytes_freed' => is_array($journal) ? (int) ($journal['bytes_freed'] ?? 0) : 0,
            'deleted_count' => is_array($journal) ? (int) ($journal['deleted_count'] ?? 0) : 0,
            'last_error' => is_array($journal) ? ($journal['last_error'] ?? null) : null,
            'diagnostic_count' => is_array($diagnostics) ? count($diagnostics) : 0,
            'diagnostic_limit' => self::MAX_DIAGNOSTICS,
        ];
    }

    /** @return array{success?:bool,plan_sha256:string,items:array,totals:array,next_cursor:?string,incomplete:bool} */
    public function preview(?string $cursor): array
    {
        $this->startScan();
        $after = $this->parseCursor($cursor);
        $cursor = $after === 0 ? null : (string) $after;
        $stageRoot = $this->confirmedStageRoot();
        if ($stageRoot === null) { throw new \RuntimeException('exchange root unavailable'); }
        $versions = [];
        $allEntries = 0;
        foreach (new \DirectoryIterator($stageRoot) as $entry) {
            $this->checkDeadline();
            if ($entry->isDot()) { continue; }
            if (++$allEntries > self::MAX_DIRS) { throw new \RuntimeException('exchange inventory exceeds bounded scan'); }
            $name = $entry->getFilename();
            if (preg_match('/\A[1-9][0-9]{0,9}\z/', $name) === 1 && (int) $name <= 2147483647) {
                $versions[] = (int) $name;
            }
        }
        sort($versions, SORT_NUMERIC);
        $summary = $this->evidence->statusSummary($versions);
        $applied = $this->evidence->currentAppliedVersions();
        $holds = $this->protections();
        $policy = $this->policy();
        $remaining = array_values(array_filter($versions, static function (int $v) use ($after): bool { return $v > $after; }));
        $pageVersions = array_slice($remaining, 0, self::PAGE);
        $next = count($remaining) > self::PAGE ? (string) end($pageVersions) : null;
        $previews = [];
        foreach ($pageVersions as $version) {
            $previews[$version] = $this->inspect($stageRoot, $version, $summary[$version] ?? null, $applied,
                $holds[$version] ?? [], $policy, [], false);
        }
        [$quota, $bytesComplete, $representatives] = $this->quotaTriggers(
            $stageRoot, $versions, $summary, $applied, $holds, $policy, $previews
        );
        $items = [];
        foreach ($pageVersions as $version) {
            $items[] = $this->policyDecision($previews[$version], $policy,
                $quota[$version] ?? [], $representatives);
        }
        return $this->plan($items, $cursor, $next, !$bytesComplete || $next !== null);
    }

    /** Exact confirmation; a changed DB/file identity refuses the whole initial plan. */
    public function apply(?string $cursor, string $expectedPlanSha256): array
    {
        $plan = $this->preview($cursor);
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedPlanSha256) !== 1
            || !hash_equals($plan['plan_sha256'], $expectedPlanSha256)) {
            throw new \RuntimeException('exchange retention preview is stale');
        }
        $deleted = 0; $bytes = 0; $errors = [];
        $this->startScan();
        $stageRoot = $this->confirmedStageRoot();
        if ($stageRoot === null) { throw new \RuntimeException('exchange root unavailable'); }
        foreach ($plan['items'] as $item) {
            if ($item['decision'] !== 'delete') { continue; }
            $version = (int) $item['version'];
            try {
                $freshSummary = $this->evidence->statusSummary([$version]);
                $fresh = $this->inspect($stageRoot, $version, $freshSummary[$version] ?? null,
                    $this->evidence->currentAppliedVersions(), $this->protections()[$version] ?? [],
                    $this->policy(), [], false);
                if ($fresh['identity_sha256'] === null || !hash_equals($item['identity_sha256'], $fresh['identity_sha256'])
                    || $item['evidence_sha256'] === null
                    || !hash_equals($item['evidence_sha256'], (string) $fresh['evidence_sha256'])
                    || $fresh['decision'] !== 'delete') {
                    throw new \RuntimeException('exchange candidate changed');
                }
                if ($item['status'] === 'failed') { $this->preserveDiagnostic($item); }
                $this->removeConfirmed($stageRoot, $version, $item['identity_sha256'], $item['evidence_sha256']);
                $deleted++; $bytes += $item['size_bytes'];
            } catch (\Throwable $e) {
                $errors[] = ['version' => $version, 'reason' => 'delete_failed'];
                $this->warning('CoreSync exchange retention: package ' . $version . ' kept: ' . $e->getMessage());
            }
        }
        $this->writeStateJson('journal.json', [
            'at' => gmdate('c'), 'deleted_count' => $deleted, 'bytes_freed' => $bytes,
            'last_error' => $errors === [] ? null : 'delete_failed',
        ]);
        return $this->result($deleted, $bytes, $errors, $plan['next_cursor']);
    }

    /** Periodic cleanup is opt-in and advances its own cursor under the caller's existing lock. */
    public function cleanupTick(): array
    {
        if (!$this->policy()['enabled']) { return $this->result(0, 0, [], null); }
        $journal = $this->readStateJson('cursor.json');
        $cursor = is_array($journal) && is_string($journal['cursor'] ?? null) ? $journal['cursor'] : null;
        try {
            $this->startScan();
            $recovered = $this->recoverQuarantine();
            $plan = $this->preview($cursor);
            $result = $this->apply($cursor, $plan['plan_sha256']);
            $result['deleted_count'] += $recovered['deleted_count'];
            $result['bytes_freed'] += $recovered['bytes_freed'];
            if ($recovered['deleted_count'] > 0) {
                $this->writeStateJson('journal.json', [
                    'at' => gmdate('c'), 'deleted_count' => $result['deleted_count'],
                    'bytes_freed' => $result['bytes_freed'],
                    'last_error' => $result['errors'] === [] ? null : 'delete_failed',
                ]);
                $result['summary'] = $this->status();
            }
            $this->writeStateJson('cursor.json', ['cursor' => $plan['next_cursor']]);
            return $result;
        } catch (\Throwable $e) {
            $this->writeStateJson('journal.json', [
                'at' => gmdate('c'), 'deleted_count' => 0, 'bytes_freed' => 0,
                'last_error' => 'inventory_failed',
            ]);
            $this->warning('CoreSync exchange retention: periodic cleanup refused: ' . $e->getMessage());
            return $this->result(0, 0, [['version' => null, 'reason' => 'inventory_failed']], $cursor);
        }
    }

    /** A validated manifest is immutable provenance for newly created staging versions. */
    public function noteValidatedManifest(array $manifest, array $cfg): ?bool
    {
        $version = $manifest['snapshot_version'] ?? null;
        $files = $manifest['files'] ?? null;
        if (!is_int($version) || $version < 1 || !is_array($files)) { return null; }
        $scope = $this->scopeHash($cfg);
        $specs = $this->manifestSpecs($files);
        // Clear the former "latest" first. A failed marker write must never leave the old
        // pointer eligible to retire the just-observed current failed package.
        if (!$this->writeStateJson('latest.json', [
            'schema' => 1, 'unproven' => true, 'observed_at' => gmdate('c'),
        ])) { return null; }
        if ($scope === null || $specs === null) { return null; }
        $path = 'manifests/' . $version . '.json';
        $existing = $this->readStateJson($path);
        $digest = hash('sha256', (string) json_encode([$scope, $specs], JSON_UNESCAPED_SLASHES));
        if (is_array($existing) && ($existing['digest'] ?? null) !== $digest) { return false; }
        if (!is_array($existing)) {
            if (is_dir($this->stageRoot() . '/' . $version)) {
                $this->writeStateJson('latest.json', ['schema' => 1, 'unproven' => true]);
                return null; // Pre-feature staging has unknown provenance.
            }
            $dir = $this->stateRoot() . '/manifests';
            if (is_dir($dir)) {
                $seen = 0;
                foreach (new \DirectoryIterator($dir) as $entry) {
                    if (!$entry->isDot() && ++$seen >= self::MAX_DIAGNOSTICS) { return null; }
                }
            }
            if (!$this->writeStateJson($path, [
                'schema' => 1, 'version' => $version, 'scope_hash' => $scope,
                'digest' => $digest, 'files' => $specs, 'accepted_at' => gmdate('c'),
            ])) { return null; }
        }
        return $this->writeStateJson('latest.json', [
            'schema' => 1, 'version' => $version, 'scope_hash' => $scope,
            'digest' => $digest, 'observed_at' => gmdate('c'), 'unproven' => false,
        ]) ? true : null;
    }

    public function setProtection(int $version, bool $hold, bool $pin, string $expectedIdentity): array
    {
        if ($version < 1 || $version > 2147483647) { throw new \RuntimeException('invalid exchange version'); }
        $stageRoot = $this->confirmedStageRoot();
        if ($stageRoot === null) { throw new \RuntimeException('exchange root unavailable'); }
        $this->startScan();
        $summary = $this->evidence->statusSummary([$version]);
        $item = $this->inspect($stageRoot, $version, $summary[$version] ?? null,
            $this->evidence->currentAppliedVersions(), $this->protections()[$version] ?? [],
            $this->policy(), [], false);
        if (!is_string($item['identity_sha256'])
            || !hash_equals($item['identity_sha256'], $expectedIdentity)) {
            throw new \RuntimeException('exchange protection preview is stale');
        }
        $holds = $this->protections();
        if (!$hold && !$pin) { unset($holds[$version]); }
        else { $holds[$version] = ['hold' => $hold, 'pin' => $pin]; }
        if (!$this->writeStateJson('protections.json', $holds)
            || $this->protections() !== $holds) { throw new \RuntimeException('exchange protection not persisted'); }
        return ['version' => $version, 'hold' => $hold, 'pin' => $pin];
    }

    private function inspect(string $root, int $version, ?string $summary, array $applied, array $flags,
        array $policy, array $quota, bool $evaluatePolicy): array
    {
        $item = [
            'version' => $version, 'status' => $summary ?? 'unknown', 'created_at' => null,
            'size_bytes' => 0, 'files_count' => 0, 'decision' => 'keep', 'reason' => 'legacy_unproven',
            'hold' => !empty($flags['hold']), 'pin' => !empty($flags['pin']),
            'identity_sha256' => null, 'evidence_sha256' => null, 'failure_fingerprint' => null,
            'relative_path' => 'files/coresync/' . $version,
        ];
        $path = $root . '/' . $version;
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) { $item['reason'] = 'unsafe_path'; return $item; }
        if ($item['hold'] || $item['pin'] || in_array($version, $applied, true)) {
            try {
                $raw = $this->rawIdentity($path);
                $item['identity_sha256'] = $this->physicalIdentity($version, $raw);
                $item['size_bytes'] = $raw['bytes']; $item['files_count'] = $raw['files_count'];
            } catch (\Throwable $e) { /* Keep without offering an unsafe CAS action. */ }
            $item['reason'] = $item['hold'] ? 'manual_hold' : ($item['pin'] ? 'manual_pin'
                : ($version === ($applied[0] ?? null) ? 'current_applied' : 'previous_applied'));
            return $item;
        }
        if ($summary !== 'applied' && $summary !== 'failed') { $item['reason'] = 'mixed_or_unknown'; return $item; }
        if (!empty($this->settings->get(Contract::SETTINGS_FORCE_REAPPLY_KEY))
            || !empty($this->settings->get(Contract::SETTINGS_PING_PENDING_KEY))) {
            $item['reason'] = 'pending_replay'; return $item;
        }
        $facts = $this->evidence->facts($version);
        if (!is_array($facts) || ($facts['status'] ?? null) !== $summary
            || !in_array($facts['phase'] ?? null, $summary === 'applied' ? [Contract::PHASE_DONE] : [Contract::PHASE_APPLY, Contract::PHASE_DOWNLOAD], true)) {
            $item['reason'] = 'mixed_or_unknown'; return $item;
        }
        $finished = strtotime((string) ($facts['finished_at'] ?? ''));
        if ($finished === false || $finished > time() || !is_array($facts['files'] ?? null)) {
            $item['reason'] = 'invalid_job_state'; return $item;
        }
        $item['created_at'] = gmdate('c', $finished);
        if ($summary === 'failed') {
            $item['failure_fingerprint'] = $this->failureFingerprint($facts);
        }
        $expected = $this->expectedFiles($facts['files'], $summary);
        if ($expected === null) { $item['reason'] = 'unsafe_checkpoint'; return $item; }
        if ($this->stagedCount($expected) > self::MAX_FILES) { $item['reason'] = 'mixed_or_unknown'; return $item; }
        if ($expected === []) { $item['reason'] = 'unsafe_checkpoint'; return $item; }
        if ($this->evidence->stagingLinked($version)) { $item['reason'] = 'linked_staging_document'; return $item; }
        $marker = $this->readStateJson('manifests/' . $version . '.json');
        $currentFailed = false;
        if ($summary === 'failed') {
            if (!$this->validManifestMarker($marker, $version, $expected)) { $item['reason'] = 'legacy_unproven'; return $item; }
            $currentFailed = !$this->hasNewerManifest($version, $marker['scope_hash']);
        }
        try { $physical = $this->measure($path, $expected, $summary, $version); }
        catch (\Throwable $e) { $item['reason'] = $e->getMessage(); return $item; }
        $item['size_bytes'] = $physical['bytes'];
        $item['files_count'] = $physical['files_count'];
        $item['identity_sha256'] = $this->physicalIdentity($version, $physical);
        $item['evidence_sha256'] = $this->evidenceIdentity($facts, $marker);
        if ($currentFailed) { $item['reason'] = 'current_manifest_failed'; return $item; }
        if (!$evaluatePolicy) { $item['decision'] = 'delete'; $item['reason'] = 'eligible'; return $item; }
        return $this->policyDecision($item, $policy, $quota, []);
    }

    /**
     * Строки job в ожидаемый состав staging. Документ со статусом applied - это пакет своего
     * хранилища CoreSync (store): файл перенесён в хранилище и сверен либо витрине не нужен (товара
     * на ней нет), staging ему больше не нужен. Старый путь applied у документов не ставит. Документ
     * store, отложенный лимитом, остаётся pending и держит версию, как раньше.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>|null null - небезопасный чекпоинт
     */
    private function expectedFiles(array $files, string $summary): ?array
    {
        $expected = [];
        foreach ($files as $file) {
            $name = $file['name'] ?? null;
            if (!is_string($name) || !$this->allowedFile($name) || isset($expected[$name])
                || !is_string($file['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) !== 1
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !in_array($file['status'] ?? null,
                    [Contract::FILE_VERIFIED, Contract::FILE_APPLIED, Contract::FILE_FAILED], true)
                || ($summary === 'applied' && !in_array($file['status'],
                    [Contract::FILE_VERIFIED, Contract::FILE_APPLIED], true))) {
                return null;
            }
            if (substr($name, 0, 9) === 'document-' && $file['status'] === Contract::FILE_APPLIED) {
                $file['stored'] = true;
            }
            $expected[$name] = $file;
        }

        return $expected;
    }

    /** @param array<string, array<string, mixed>> $expected */
    private function stagedCount(array $expected): int
    {
        $count = 0;
        foreach ($expected as $file) {
            if (empty($file['stored'])) { $count++; }
        }
        return $count;
    }

    private function policyDecision(array $item, array $policy, array $quota, array $representatives): array
    {
        if ($item['reason'] !== 'eligible') { return $item; }
        $class = $policy[$item['status']];
        $finished = strtotime((string) $item['created_at']);
        $aged = $finished !== false && time() - $finished >= $class['max_age_days'] * 86400;
        if (!$aged && empty($quota['count']) && empty($quota['bytes'])) {
            $item['decision'] = 'keep'; $item['reason'] = 'within_policy';
        } elseif ($item['status'] === 'failed' && is_string($item['failure_fingerprint'])
            && ($representatives[$item['failure_fingerprint']] ?? null) === $item['version']) {
            $item['decision'] = 'keep'; $item['reason'] = 'failure_representative';
        } else {
            $item['reason'] = $aged ? 'age_limit' : (!empty($quota['count']) ? 'count_limit' : 'byte_limit');
        }
        return $item;
    }

    /** @return array{bytes:int,files_count:int,identity:array,dir_identity:array} */
    private function measure(string $path, array $expected, string $status, int $version): array
    {
        $physical = $this->rawIdentity($path);
        $seen = [];
        foreach ($physical['identity'] as $row) {
            [$name, , , , , $size, $digest] = $row;
            if (!isset($expected[$name]) || !$this->allowedFile($name)) { throw new \RuntimeException('unexpected_file'); }
            if (($status === 'applied' || in_array($expected[$name]['status'],
                [Contract::FILE_VERIFIED, Contract::FILE_APPLIED], true))
                && ($size !== $expected[$name]['bytes']
                || !hash_equals($expected[$name]['sha256'], $digest))) {
                throw new \RuntimeException('checkpoint_mismatch');
            }
            if (substr($name, 0, 9) === 'document-' && empty($expected[$name]['stored'])
                && !$this->evidence->documentSafe($version, $name, $digest, $size)) {
                throw new \RuntimeException('published_document_unverified');
            }
            $seen[$name] = true;
        }
        if ($status === 'applied') {
            foreach ($expected as $name => $file) {
                // Документ store уже в хранилище: его отсутствие в staging - норма после rename.
                if (!isset($seen[$name]) && empty($file['stored'])) {
                    throw new \RuntimeException('missing_verified_file');
                }
            }
        }
        return $physical;
    }

    /** Hash only direct ordinary files; unknown names are held by measure(), never followed. */
    private function rawIdentity(string $path): array
    {
        $this->checkDeadline();
        $dirStat = @lstat($path);
        if (!is_array($dirStat) || ($dirStat['mode'] & 0170000) !== 0040000 || $dirStat['nlink'] < 1) {
            throw new \RuntimeException('unsafe_path');
        }
        try { $entries = new \DirectoryIterator($path); }
        catch (\Throwable $e) { throw new \RuntimeException('unreadable_package'); }
        $identity = []; $bytes = 0;
        foreach ($entries as $entry) {
            $this->checkDeadline();
            if ($entry->isDot()) { continue; }
            $name = $entry->getFilename();
            $file = $path . '/' . $name;
            $stat = @lstat($file);
            if (!is_array($stat) || is_link($file) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                throw new \RuntimeException('unsafe_file');
            }
            $size = (int) $stat['size'];
            $bytes += $size;
            $this->hashBytes += $size;
            if ($this->hashBytes > self::MAX_HASH_BYTES || count($identity) >= self::MAX_FILES) {
                throw new \RuntimeException('verification_budget');
            }
            $digest = @hash_file('sha256', $file);
            if (!is_string($digest)) { throw new \RuntimeException('unreadable_file'); }
            $identity[] = [$name, $stat['dev'], $stat['ino'], $stat['mtime'], $stat['ctime'], $size, $digest];
        }
        sort($identity);
        return ['bytes' => $bytes, 'files_count' => count($identity), 'identity' => $identity,
            'dir_identity' => [(int) $dirStat['dev'], (int) $dirStat['ino']]];
    }

    private function physicalIdentity(int $version, array $physical): string
    {
        return hash('sha256', (string) json_encode([
            $version, $physical['dir_identity'], $physical['identity'],
        ], JSON_UNESCAPED_SLASHES));
    }

    private function evidenceIdentity(array $facts, $marker): string
    {
        return hash('sha256', (string) json_encode([
            $facts['status'], $facts['job_id'], $facts['phase'], $facts['finished_at'], $facts['files'], $marker,
            ($facts['status'] ?? null) === 'failed' ? $this->failureFingerprint($facts) : null,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function quotaTriggers(string $root, array $versions, array $summary, array $applied,
        array $holds, array $policy, array $previews): array
    {
        $classes = ['applied' => [], 'failed' => []];
        $representatives = [];
        $totalFiles = 0; $complete = true;
        // The selected page was physically verified before the global scan. Reserve its
        // newest failed exemplar even if a later global budget failure stops before it.
        foreach ($previews as $version => $item) {
            if ($item['status'] === 'failed'
                && in_array($item['reason'], ['eligible', 'current_manifest_failed'], true)
                && is_string($item['identity_sha256']) && is_string($item['failure_fingerprint'])
                && (!isset($representatives[$item['failure_fingerprint']])
                    || $version > $representatives[$item['failure_fingerprint']])) {
                $representatives[$item['failure_fingerprint']] = (int) $version;
            }
        }
        // Inspect newest first: an incomplete bounded scan can still reserve the newest
        // proven physical representative and use only a verified lower bound for quotas.
        foreach (array_reverse($versions) as $version) {
            if (microtime(true) > $this->scanDeadline) { $complete = false; break; }
            $class = $summary[$version] ?? null;
            if (!isset($classes[$class]) || in_array($version, $applied, true)
                || !empty($holds[$version]['hold']) || !empty($holds[$version]['pin'])) { continue; }
            $item = $previews[$version] ?? $this->inspect($root, $version, $class, $applied,
                $holds[$version] ?? [], $policy, [], false);
            if ($item['reason'] === 'verification_budget') { $complete = false; break; }
            if ($class === 'failed' && $item['reason'] === 'current_manifest_failed'
                && is_string($item['identity_sha256']) && is_string($item['failure_fingerprint'])
                && (!isset($representatives[$item['failure_fingerprint']])
                    || $version > $representatives[$item['failure_fingerprint']])) {
                $representatives[$item['failure_fingerprint']] = $version;
            }
            if ($item['reason'] !== 'eligible') { continue; }
            $totalFiles += $item['files_count'];
            if ($totalFiles > self::MAX_GLOBAL_FILES) { $complete = false; break; }
            $classes[$class][$version] = $item['size_bytes'];
            if ($class === 'failed' && is_string($item['failure_fingerprint'])
                && (!isset($representatives[$item['failure_fingerprint']])
                    || $version > $representatives[$item['failure_fingerprint']])) {
                $representatives[$item['failure_fingerprint']] = $version;
            }
        }
        foreach ($representatives as $version) {
            unset($classes['failed'][$version]); // Protected physical exemplars are outside limits.
        }
        $triggers = [];
        foreach ($classes as $class => $sizes) {
            krsort($sizes, SORT_NUMERIC);
            $rank = 0; $total = array_sum($sizes);
            foreach ($sizes as $version => $size) {
                $rank++;
                if ($rank > $policy[$class]['max_count']) { $triggers[$version]['count'] = true; }
            }
            $oldest = array_reverse($sizes, true);
            foreach ($oldest as $version => $size) {
                if ($total <= $policy[$class]['max_bytes']) { break; }
                $triggers[$version]['bytes'] = true;
                $total -= $size;
            }
        }
        return [$triggers, $complete, $representatives];
    }

    private function plan(array $items, ?string $cursor, ?string $next, bool $incomplete): array
    {
        $remaining = self::MAX_DELETIONS;
        $totals = ['deletable_count' => 0, 'deletable_bytes' => 0, 'protected_count' => 0, 'unmeasured_count' => 0];
        foreach ($items as &$item) {
            if ($item['reason'] === 'verification_budget') { $incomplete = true; }
            if ($item['decision'] === 'delete' && $remaining-- <= 0) {
                $item['decision'] = 'keep'; $item['reason'] = 'apply_budget';
            }
            if ($item['decision'] === 'delete') {
                $totals['deletable_count']++; $totals['deletable_bytes'] += $item['size_bytes'];
            } else { $totals['protected_count']++; }
            if ($item['identity_sha256'] === null) { $totals['unmeasured_count']++; }
        }
        unset($item);
        $hash = hash('sha256', (string) json_encode([$cursor, $next, $items, $totals, $this->policy()], JSON_UNESCAPED_SLASHES));
        return ['plan_sha256' => $hash, 'items' => $items, 'totals' => $totals,
            'next_cursor' => $next, 'incomplete' => $incomplete];
    }

    private function result(int $deleted, int $bytes, array $errors, ?string $cursor): array
    {
        return ['deleted_count' => $deleted, 'bytes_freed' => $bytes, 'errors' => $errors,
            'next_cursor' => $cursor, 'summary' => $this->status()];
    }

    private function parseCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') { return 0; }
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $cursor) !== 1 || (int) $cursor > 2147483647) {
            throw new \RuntimeException('invalid exchange cursor');
        }
        return (int) $cursor;
    }

    private function allowedFile(string $name): bool
    {
        return preg_match('/\A[a-z0-9-]+\.ndjson\.gz\z/', $name) === 1
            || preg_match('/\Adocument-[a-f0-9]{64}\.pdf\z/', $name) === 1;
    }

    private function stageRoot(): string
    {
        return rtrim((string) $this->config->get('root_dir'), '/\\') . '/files/coresync';
    }

    private function confirmedStageRoot(): ?string
    {
        $base = realpath(rtrim((string) $this->config->get('root_dir'), '/\\'));
        $path = $this->stageRoot();
        if ($base === false || is_link($path) || realpath($path) !== $base . '/files/coresync') { return null; }
        return $path;
    }

    private function stateRoot(): string { return $this->stageRoot() . '/exchange-retention'; }

    private function statePath(string $name): ?string
    {
        if (preg_match('~\A(?:[a-z-]+\.json|manifests/[1-9][0-9]{0,9}\.json)\z~', $name) !== 1) { return null; }
        $stage = $this->confirmedStageRoot();
        if ($stage === null) { return null; }
        $state = $this->stateRoot();
        if (is_link($state) || (is_dir($state) && realpath($state) !== $stage . '/exchange-retention')) { return null; }
        if (strpos($name, 'manifests/') === 0) {
            $parent = $state . '/manifests';
            if (is_link($parent) || (is_dir($parent) && realpath($parent) !== $parent)) { return null; }
        }
        return $state . '/' . $name;
    }

    private function readStateJson(string $name)
    {
        $path = $this->statePath($name);
        if ($path === null || is_link($path) || !is_file($path) || filesize($path) > 1048576) { return null; }
        return json_decode((string) @file_get_contents($path), true);
    }

    private function writeStateJson(string $name, array $value): bool
    {
        $path = $this->statePath($name);
        if ($path === null) { return false; }
        if (!$this->ensurePrivateState()) { return false; }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { return false; }
        if (is_link($dir) || is_link($path)) { return false; }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 1048576) { return false; }
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) { @unlink($temp); return false; }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) { @unlink($temp); return false; }
        return hash_equals(hash('sha256', $json), (string) @hash_file('sha256', $path));
    }

    private function ensurePrivateState(): bool
    {
        if ($this->confirmedStageRoot() === null) { return false; }
        $state = $this->stateRoot();
        if (!is_dir($state) && !@mkdir($state, 0750)) { return false; }
        if (is_link($state) || realpath($state) !== $state) { return false; }
        $deny = $state . '/.htaccess';
        if (is_link($deny) || (!is_file($deny) && @file_put_contents($deny, "Require all denied\n") === false)) {
            return false;
        }
        $stat = @lstat($deny);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && $stat['nlink'] === 1 && @file_get_contents($deny) === "Require all denied\n";
    }

    private function protections(): array
    {
        $path = $this->statePath('protections.json');
        if ($path !== null && !file_exists($path)) { return []; }
        $data = $this->readStateJson('protections.json');
        if (!is_array($data)) { throw new \RuntimeException('exchange protections unavailable'); }
        return $data;
    }

    private function scopeHash(array $cfg): ?string
    {
        $source = trim((string) ($cfg['source_instance'] ?? ''));
        $channel = trim((string) ($cfg['channel_code'] ?? ''));
        $url = trim((string) ($cfg['core_url'] ?? ''));
        $parts = @parse_url($url);
        if ($source === '' || $channel === '' || !Contract::isValidSourceInstance($source)
            || !is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) { return null; }
        $authority = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) { $authority .= ':' . $parts['port']; }
        $authority .= rtrim((string) ($parts['path'] ?? ''), '/');
        return hash('sha256', $authority . "\0" . $source . "\0" . $channel);
    }

    private function manifestSpecs(array $files): ?array
    {
        if ($files === [] || count($files) > self::MAX_FILES) { return null; }
        $specs = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null) || !$this->allowedFile($file['name'])
                || !is_string($file['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) !== 1
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0 || isset($specs[$file['name']])) { return null; }
            $specs[$file['name']] = [$file['sha256'], $file['bytes']];
        }
        ksort($specs, SORT_STRING);
        return $specs;
    }

    private function validManifestMarker($marker, int $version, array $expected): bool
    {
        if (!is_array($marker) || ($marker['schema'] ?? null) !== 1 || ($marker['version'] ?? null) !== $version
            || !is_string($marker['scope_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $marker['scope_hash']) !== 1
            || !is_array($marker['files'] ?? null) || !is_string($marker['digest'] ?? null)) { return false; }
        $specs = [];
        foreach ($expected as $name => $file) { $specs[$name] = [$file['sha256'], $file['bytes']]; }
        ksort($specs, SORT_STRING);
        return $specs === $marker['files']
            && hash_equals($marker['digest'], hash('sha256', (string) json_encode([$marker['scope_hash'], $specs], JSON_UNESCAPED_SLASHES)));
    }

    private function hasNewerManifest(int $version, string $scope): bool
    {
        $latest = $this->readStateJson('latest.json');
        if (!is_array($latest) || !empty($latest['unproven'])
            || ($latest['scope_hash'] ?? null) !== $scope || !is_int($latest['version'] ?? null)
            || $latest['version'] <= $version) { return false; }
        $marker = $this->readStateJson('manifests/' . $latest['version'] . '.json');
        return is_array($marker) && ($marker['version'] ?? null) === $latest['version']
            && ($marker['scope_hash'] ?? null) === $scope
            && ($marker['digest'] ?? null) === ($latest['digest'] ?? null)
            && is_array($marker['files'] ?? null)
            && hash_equals((string) $marker['digest'], hash('sha256', (string) json_encode([
                $scope, $marker['files'],
            ], JSON_UNESCAPED_SLASHES)));

    }

    private function startScan(): void
    {
        $this->hashBytes = 0;
        $this->scanDeadline = microtime(true) + self::MAX_SCAN_SECONDS;
    }

    private function checkDeadline(): void
    {
        if ($this->scanDeadline > 0 && microtime(true) > $this->scanDeadline) {
            throw new \RuntimeException('verification_budget');
        }
    }

    private function preserveDiagnostic(array $item): void
    {
        $facts = $this->evidence->facts((int) $item['version']);
        if (!is_array($facts) || ($facts['status'] ?? null) !== 'failed') {
            throw new \RuntimeException('failed diagnostic state changed');
        }
        $normal = $this->normalError((string) ($facts['error'] ?? ''));
        $fingerprint = $this->failureFingerprint($facts);
        $data = $this->readStateJson('diagnostics.json');
        $path = $this->statePath('diagnostics.json');
        if ($path === null || (($data === null) && (file_exists($path) || is_link($path)))) {
            throw new \RuntimeException('diagnostic journal unreadable');
        }
        if ($data !== null && !is_array($data)) { throw new \RuntimeException('diagnostic journal corrupt'); }
        $entries = is_array($data) ? $data : [];
        $key = $fingerprint;
        $entries[$key] = [
            'fingerprint' => $fingerprint, 'phase' => $facts['phase'], 'error' => $normal,
            'job_id' => $facts['job_id'], 'snapshot_version' => $item['version'],
            'identity_sha256' => $item['identity_sha256'], 'at' => gmdate('c'),
        ];
        $cutoff = time() - self::DIAGNOSTIC_DAYS * 86400;
        $entries = array_filter($entries, static function (array $row) use ($cutoff): bool {
            return strtotime((string) ($row['at'] ?? '')) >= $cutoff;
        });
        uasort($entries, static function (array $a, array $b): int { return strcmp($b['at'], $a['at']); });
        $entries = array_slice($entries, 0, self::MAX_DIAGNOSTICS, true);
        if (!isset($entries[$key]) || !$this->writeStateJson('diagnostics.json', $entries)
            || ($this->readStateJson('diagnostics.json')[$key]['identity_sha256'] ?? null) !== $item['identity_sha256']) {
            throw new \RuntimeException('diagnostic readback failed');
        }
    }

    private function normalError(string $error): string
    {
        $text = strtolower(trim($error));
        $text = preg_replace('~https?://\S+~i', '<url>', $text);
        $text = preg_replace('~(?:/[^\s/]+){2,}~', '<path>', $text);
        $text = preg_replace('/\b[^\s@]+@[^\s@]+\b/', '<email>', $text);
        $text = preg_replace('/\b(?:token|password|secret|authorization|api[_-]?key)\s*[:=]\s*\S+/i', '<credential>', $text);
        $text = preg_replace('/\b(source parent|sku|external id)\s+[a-z0-9][a-z0-9._:-]{0,80}\b/i', '$1 <id>', $text);
        $text = preg_replace('/\b[0-9a-f]{16,}\b/i', '<hash>', $text);
        $text = preg_replace('/\b[a-z0-9_-]{20,}\b/i', '<id>', $text);
        $text = preg_replace('/\b[0-9]+\b/', '<n>', $text);
        return substr((string) $text, 0, 160);
    }

    private function failureFingerprint(array $facts): string
    {
        return hash('sha256', (string) ($facts['phase'] ?? '') . "\0"
            . $this->normalError((string) ($facts['error'] ?? '')));
    }

    private function removeConfirmed(string $root, int $version, string $identity, ?string $evidenceIdentity = null): void
    {
        $path = $root . '/' . $version;
        $state = $this->stateRoot();
        $quarantine = $state . '/quarantine';
        if (!$this->ensurePrivateState()) { throw new \RuntimeException('private retention state unavailable'); }
        if (!is_dir($quarantine) && !@mkdir($quarantine, 0750, true)) {
            throw new \RuntimeException('quarantine unavailable');
        }
        if (is_link($quarantine) || realpath($quarantine) !== $state . '/quarantine') {
            throw new \RuntimeException('unsafe quarantine');
        }
        $target = $quarantine . '/' . $version . '-' . bin2hex(random_bytes(8));
        if (!@rename($path, $target)) { throw new \RuntimeException('atomic package retirement failed'); }
        $started = false;
        $quarantineMarker = null;
        try {
            $facts = $this->evidence->facts($version);
            if (!is_array($facts)) { throw new \RuntimeException('retired package DB evidence changed'); }
            $marker = $this->readStateJson('manifests/' . $version . '.json');
            if ($evidenceIdentity !== null
                && !hash_equals($evidenceIdentity, $this->evidenceIdentity($facts, $marker))) {
                throw new \RuntimeException('post-rename DB evidence changed');
            }
            if (in_array($version, $this->evidence->currentAppliedVersions(), true)
                || $this->evidence->stagingLinked($version)) {
                throw new \RuntimeException('retired package became protected');
            }
            $expected = $this->expectedFiles($facts['files'], (string) $facts['status']);
            if ($expected === null) { throw new \RuntimeException('retired package checkpoint changed'); }
            $physical = $this->measure($target, $expected, $facts['status'], $version);
            if (!hash_equals($identity, $this->physicalIdentity($version, $physical))) {
                throw new \RuntimeException('post-rename identity changed');
            }
            $quarantineMarker = $target . '/.exchange-quarantine.json';
            $receipt = json_encode([
                'schema' => 1, 'version' => $version, 'identity_sha256' => $identity,
                'evidence_sha256' => $evidenceIdentity,
                'status' => $facts['status'], 'files' => $physical['identity'], 'created_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES);
            if (!is_string($receipt) || @file_put_contents($quarantineMarker, $receipt, LOCK_EX) !== strlen($receipt)
                || !hash_equals(hash('sha256', $receipt), (string) @hash_file('sha256', $quarantineMarker))) {
                throw new \RuntimeException('quarantine receipt unavailable');
            }
            @chmod($quarantineMarker, 0600);
            $entries = @scandir($target);
            if (!is_array($entries)) { throw new \RuntimeException('quarantine unreadable'); }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || $name === '.exchange-quarantine.json') { continue; }
                if (!$this->allowedFile($name) || is_link($target . '/' . $name) || !is_file($target . '/' . $name)
                    || !@unlink($target . '/' . $name)) { throw new \RuntimeException('quarantine deletion refused'); }
                $started = true;
            }
            if (!@unlink($quarantineMarker)) { throw new \RuntimeException('quarantine receipt removal failed'); }
            $started = true;
            if (!@rmdir($target)) { throw new \RuntimeException('quarantine directory not removed'); }
        } catch (\Throwable $e) {
            if (!$started && is_dir($target) && !file_exists($path)) {
                if ($quarantineMarker !== null && is_file($quarantineMarker)) { @unlink($quarantineMarker); }
                @rename($target, $path);
            }
            throw $e;
        }
    }

    /** Resume only task-created, identity-bound partial quarantines; unknown entries stop cleanup. */
    private function recoverQuarantine(): array
    {
        $state = $this->stateRoot();
        $quarantine = $state . '/quarantine';
        if (!is_dir($quarantine)) { return ['deleted_count' => 0, 'bytes_freed' => 0]; }
        if (is_link($quarantine) || realpath($quarantine) !== $quarantine) {
            throw new \RuntimeException('unsafe quarantine');
        }
        $deleted = 0; $bytes = 0; $seen = 0;
        foreach (new \DirectoryIterator($quarantine) as $entry) {
            if ($entry->isDot()) { continue; }
            $this->checkDeadline();
            if (++$seen > self::MAX_DELETIONS) { break; }
            $name = $entry->getFilename();
            if (preg_match('/\A([1-9][0-9]{0,9})-[a-f0-9]{16}\z/', $name, $match) !== 1
                || $entry->isLink() || !$entry->isDir()) { throw new \RuntimeException('unknown quarantine entry'); }
            $version = (int) $match[1];
            $path = $quarantine . '/' . $name;
            if (file_exists($this->stageRoot() . '/' . $version) || is_link($this->stageRoot() . '/' . $version)) {
                throw new \RuntimeException('retired version reappeared');
            }
            $receiptPath = $path . '/.exchange-quarantine.json';
            if (is_link($receiptPath) || !is_file($receiptPath) || filesize($receiptPath) > 65536) {
                throw new \RuntimeException('quarantine receipt unavailable');
            }
            $receipt = json_decode((string) @file_get_contents($receiptPath), true);
            if (!is_array($receipt) || ($receipt['schema'] ?? null) !== 1 || ($receipt['version'] ?? null) !== $version
                || !is_array($receipt['files'] ?? null) || !is_string($receipt['evidence_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $receipt['evidence_sha256']) !== 1) {
                throw new \RuntimeException('quarantine receipt invalid');
            }
            if (in_array($version, $this->evidence->currentAppliedVersions(), true)
                || !empty($this->protections()[$version]['hold']) || !empty($this->protections()[$version]['pin'])
                || $this->evidence->stagingLinked($version)) { throw new \RuntimeException('quarantine now protected'); }
            $facts = $this->evidence->facts($version);
            $marker = $this->readStateJson('manifests/' . $version . '.json');
            if (!is_array($facts) || !hash_equals($receipt['evidence_sha256'], $this->evidenceIdentity($facts, $marker))) {
                throw new \RuntimeException('quarantine DB evidence changed');
            }
            if ($facts['status'] === 'failed' && (!is_array($marker)
                || !$this->hasNewerManifest($version, (string) ($marker['scope_hash'] ?? '')))) {
                throw new \RuntimeException('quarantine failed version no longer superseded');
            }
            $original = []; $originalBytes = 0;
            foreach ($receipt['files'] as $row) {
                if (!is_array($row) || count($row) !== 7 || !is_string($row[0]) || !$this->allowedFile($row[0])
                    || !is_string($row[6]) || preg_match('/\A[a-f0-9]{64}\z/', $row[6]) !== 1
                    || isset($original[$row[0]])) { throw new \RuntimeException('quarantine inventory invalid'); }
                $original[$row[0]] = $row;
                $originalBytes += (int) $row[5];
            }
            if ($original === [] || count($original) > self::MAX_FILES) {
                throw new \RuntimeException('quarantine inventory invalid');
            }
            foreach (new \DirectoryIterator($path) as $file) {
                if ($file->isDot() || $file->getFilename() === '.exchange-quarantine.json') { continue; }
                $this->checkDeadline();
                $filename = $file->getFilename();
                $stat = @lstat($file->getPathname());
                if (!isset($original[$filename]) || !is_array($stat) || $file->isLink()
                    || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                    throw new \RuntimeException('quarantine inventory changed');
                }
                $this->hashBytes += (int) $stat['size'];
                if ($this->hashBytes > self::MAX_HASH_BYTES) { throw new \RuntimeException('verification_budget'); }
                $digest = @hash_file('sha256', $file->getPathname());
                $row = [$filename, $stat['dev'], $stat['ino'], $stat['mtime'], $stat['ctime'], (int) $stat['size'], $digest];
                if (!is_string($digest) || $row !== $original[$filename]
                    || (substr($filename, 0, 9) === 'document-'
                        && !$this->evidence->documentSafe($version, $filename, $digest, (int) $stat['size']))) {
                    throw new \RuntimeException('quarantine payload changed');
                }
            }
            foreach (array_keys($original) as $filename) {
                $file = $path . '/' . $filename;
                if (file_exists($file) && !@unlink($file)) { throw new \RuntimeException('quarantine deletion refused'); }
            }
            if (!@unlink($receiptPath) || !@rmdir($path)) { throw new \RuntimeException('quarantine finalization failed'); }
            $deleted++; $bytes += $originalBytes;
        }
        return ['deleted_count' => $deleted, 'bytes_freed' => $bytes];
    }

    private function validatedPolicy(array $value): array
    {
        $enabled = $value['enabled'] ?? false;
        if (!is_bool($enabled)) { throw new \InvalidArgumentException('invalid policy enabled'); }
        $result = ['enabled' => $enabled];
        foreach (['applied', 'failed'] as $class) {
            $row = $value[$class] ?? null;
            if (!is_array($row)) { throw new \InvalidArgumentException('invalid policy class'); }
            foreach (['max_age_days' => [1, 3650], 'max_count' => [2, 1000], 'max_bytes' => [1048576, 1099511627776]] as $key => $bounds) {
                $n = $row[$key] ?? null;
                if (!is_int($n) || $n < $bounds[0] || $n > $bounds[1]) {
                    throw new \InvalidArgumentException('invalid policy ' . $class . ' ' . $key);
                }
                $result[$class][$key] = $n;
            }
        }
        $result['diagnostic_days'] = self::DIAGNOSTIC_DAYS;
        return $result;
    }

    private function warning(string $message): void
    {
        if ($this->logger !== null) { $this->logger->warning($message); }
    }
}
