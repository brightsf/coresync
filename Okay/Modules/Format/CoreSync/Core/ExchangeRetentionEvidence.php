<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncDocumentsEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Okay\Modules\Format\ProductDocuments\Entities\ProductDocumentsEntity;

/** Read-only native Okay evidence. Failure or an oversized history means "unknown", never delete. */
class ExchangeRetentionEvidence
{
    private const MAX_FILES = 128;
    /** @var EntityFactory */ private $entities;
    /** @var Settings */ private $settings;
    /** @var Config */ private $config;
    /** @var Database */ private $db;
    /** @var QueryFactory */ private $queries;

    public function __construct(EntityFactory $entities, Settings $settings, Config $config, Database $db, QueryFactory $queries)
    {
        $this->entities = $entities;
        $this->settings = $settings;
        $this->config = $config;
        $this->db = $db;
        $this->queries = $queries;
    }

    /** @return int[] */
    public function currentAppliedVersions(): array
    {
        return $this->entities->get(CoreSyncJobsEntity::class)->retentionAppliedVersions();
    }

    /** @param int[] $versions @return array<int,string> */
    public function statusSummary(array $versions): array
    {
        return $this->entities->get(CoreSyncJobsEntity::class)->retentionStatusSummary($versions);
    }

    /** @return array<string,mixed>|null */
    public function facts(int $version): ?array
    {
        /** @var CoreSyncJobsEntity $jobs */
        $jobs = $this->entities->get(CoreSyncJobsEntity::class);
        if ((int) $jobs->count(['snapshot_version' => $version]) !== 1) { return null; }
        $job = $jobs->findOne(['snapshot_version' => $version]);
        if (!$job || empty($job->id) || empty($job->finished_at)) { return null; }
        /** @var CoreSyncJobFilesEntity $jobFiles */
        $jobFiles = $this->entities->get(CoreSyncJobFilesEntity::class);
        $count = (int) $jobFiles->count(['job_id' => $job->id]);
        if ($count < 1 || $count > self::MAX_FILES || $count !== (int) $job->files_total) { return null; }
        $rows = $jobFiles->find(['job_id' => $job->id, 'limit' => self::MAX_FILES + 1]);
        if (!is_array($rows) || count($rows) !== $count) { return null; }
        $files = [];
        foreach ($rows as $row) {
            $files[] = [
                'name' => (string) $row->name, 'sha256' => (string) $row->sha256_expected,
                'bytes' => (int) $row->bytes, 'status' => (string) $row->status,
            ];
        }
        return [
            'status' => (string) $job->status, 'phase' => (string) $job->phase,
            'job_id' => (int) $job->id, 'finished_at' => (string) $job->finished_at,
            'error' => $job->error_message === null ? null : (string) $job->error_message,
            'files' => $files,
        ];
    }

    /** Any native ProductDocuments filename that still points at this staging version vetoes deletion. */
    public function stagingLinked(int $version): bool
    {
        $select = $this->queries->newSelect()
            ->cols(['COUNT(*) AS n'])
            ->from(ProductDocumentsEntity::getTable())
            ->where('filename LIKE :staging_path')
            ->bindValue('staging_path', '%coresync/' . $version . '/%');
        if ($this->db->query($select) === false) { throw new \RuntimeException('document link check unavailable'); }
        return (int) $this->db->result('n') > 0;
    }

    /** Published PDF and both native ownership/read models must be intact before retiring staging. */
    public function documentSafe(int $version, string $name, string $sha256, int $bytes): bool
    {
        if ($this->stagingLinked($version)) { return false; }
        /** @var CoreSyncDocumentsEntity $owned */
        $owned = $this->entities->get(CoreSyncDocumentsEntity::class);
        /** @var ProductDocumentsEntity $public */
        $public = $this->entities->get(ProductDocumentsEntity::class);
        $ownedCount = (int) $owned->count(['sha256' => $sha256]);
        $publicCount = (int) $public->count(['filename' => $name]);
        // A failed download/apply may never have published its PDF. Native metadata proves
        // no public reference; the retired payload can be safely fetched again on replay.
        if ($ownedCount === 0 && $publicCount === 0) { return true; }
        if ($ownedCount === 0 || $publicCount === 0) { return false; }
        if ($ownedCount > 64) { return false; } // Unbounded shared ownership is not a cleanup proof.
        $ownedRows = $owned->find(['sha256' => $sha256, 'limit' => 65]);
        if (!is_array($ownedRows) || count($ownedRows) !== $ownedCount) { return false; }
        foreach ($ownedRows as $row) {
            $published = $public->findOne(['id' => (int) $row->document_id]);
            if (!$published || (string) $published->filename !== $name
                || (string) $published->hash !== $sha256
                || (int) $published->product_id !== (int) $row->product_local_id
                || (int) $row->bytes !== $bytes) { return false; }
        }
        $relative = trim((string) $this->settings->get('documents_dir'));
        if ($relative === '') { $relative = 'files/documents/'; }
        $relative = trim($relative, '/');
        if (preg_match('~\A[a-zA-Z0-9_./-]+\z~', $relative) !== 1
            || strpos('/' . $relative . '/', '/../') !== false || strpos($relative, '//') !== false) {
            return false;
        }
        $root = realpath(rtrim((string) $this->config->get('root_dir'), '/\\'));
        $directory = $root === false ? false : realpath($root . '/' . $relative);
        if ($directory === false || $directory !== $root . '/' . $relative
            || strpos($directory . '/', $root . '/files/coresync/') === 0) { return false; }
        $path = $directory . '/' . $name;
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || (int) $stat['size'] !== $bytes || is_link($path)) { return false; }
        $digest = @hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals($sha256, $digest)) { return false; }
        $handle = @fopen($path, 'rb');
        $signature = is_resource($handle) ? fread($handle, 5) : false;
        if (is_resource($handle)) { fclose($handle); }
        if ($signature !== '%PDF-') { return false; }
        return true;
    }
}
