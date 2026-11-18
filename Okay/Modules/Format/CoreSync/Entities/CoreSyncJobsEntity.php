<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Прогоны синхронизации (jobs). status/фаза/счётчики/cancel-флаг — прогресс и reconnect
 * (UI поллит эту таблицу). Applied-статусы добавит M2.
 */
class CoreSyncJobsEntity extends Entity
{
    protected static $fields = [
        'id',
        'status',
        'snapshot_version',
        'phase',
        'files_total',
        'files_done',
        'bytes_done',
        'error_message',
        'cancel_requested',
        'started_at',
        'finished_at',
        'created',
        'updated',
    ];

    protected static $table = '__format__coresync_jobs';
    protected static $tableAlias = 'csj';
    protected static $defaultOrderFields = [
        'id DESC',
    ];

    public function add($object)
    {
        $object = (object) $object;
        if (empty($object->created)) {
            $object->created = 'NOW()';
        }

        return parent::add($object);
    }

    /**
     * Последняя успешно ПРИМЕНЁННАЯ версия снапшота (M2: status=applied). Источник для VersionGate:
     * версия считается применённой только при статусе applied (held — частично, не применена).
     */
    public function getLastAppliedVersion(): ?int
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['MAX(snapshot_version) AS v'])
            ->from(self::getTable())
            ->where('status = :status')
            ->bindValue('status', Contract::STATUS_APPLIED);

        $value = $select->result('v');
        if ($value === null || $value === false) {
            return null;
        }

        return (int) $value;
    }

    /** @return int[] Newest and previous distinct applied versions, independent of job ID order. */
    public function retentionAppliedVersions(): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['snapshot_version'])
            ->distinct(true)
            ->from(self::getTable())
            ->where('status = :status')
            ->where('snapshot_version IS NOT NULL')
            ->orderBy(['snapshot_version DESC'])
            ->limit(2)
            ->bindValue('status', Contract::STATUS_APPLIED);
        if ($this->db->query($select) === false) { throw new \RuntimeException('CoreSync applied versions unavailable'); }
        return array_map('intval', array_column((array) $this->db->results(), 'snapshot_version'));
    }

    /**
     * Bounded aggregate for numeric staging versions. Mixed job histories are never inferred safe.
     * @param int[] $versions
     * @return array<int,string> version => applied|failed|mixed
     */
    public function retentionStatusSummary(array $versions): array
    {
        $summary = [];
        foreach (array_chunk($versions, 200) as $chunk) {
            if ($chunk === []) { continue; }
            $safe = array_values(array_filter(array_map('intval', $chunk), static function (int $v): bool { return $v > 0; }));
            if ($safe === []) { continue; }
            $select = $this->queryFactory->newSelect()
                ->cols(['snapshot_version', 'COUNT(*) AS n',
                    "SUM(status = 'applied') AS applied_n", "SUM(status = 'failed') AS failed_n"])
                ->from(self::getTable())
                ->where('snapshot_version IN (' . implode(',', $safe) . ')')
                ->groupBy(['snapshot_version']);
            if ($this->db->query($select) === false) { throw new \RuntimeException('CoreSync job summary unavailable'); }
            foreach ((array) $this->db->results() as $row) {
                $version = (int) $row->snapshot_version;
                $n = (int) $row->n;
                $summary[$version] = $n > 0 && (int) $row->applied_n === $n ? 'applied'
                    : ($n > 0 && (int) $row->failed_n === $n ? 'failed' : 'mixed');
            }
        }
        return $summary;
    }

    /**
     * Незавершённый прогон той же версии — точка resume. Повторный запуск продолжает его с места
     * (докачка verified-инвариант + пропуск already-applied файлов) вместо создания нового.
     * held включён: absent мог быть отложен порогом, повтор попробует применить остаток.
     *
     * @return object|null
     */
    public function findResumable(int $version)
    {
        $result = $this->findOne([
            'snapshot_version' => $version,
            'status'           => [
                Contract::STATUS_CANCELLED,
                Contract::STATUS_FAILED,
                Contract::STATUS_APPLYING,
                Contract::STATUS_HELD,
            ],
        ]);

        return $result ?: null;
    }

    /**
     * Есть ли прямо сейчас активный (не завершённый) прогон — для приёмника пинка (SAT-RT §0.2):
     * пинок во время прогона не запускает второй, а помечает «обслужить следующим».
     */
    public function hasActiveRun(): bool
    {
        $job = $this->findOne([
            'status' => [Contract::STATUS_RUNNING, Contract::STATUS_APPLYING],
        ]);

        return !empty($job);
    }

    public function isCancelRequested($jobId): bool
    {
        if (empty($jobId)) {
            return false;
        }
        $job = $this->findOne(['id' => $jobId]);

        return !empty($job) && !empty($job->cancel_requested);
    }

    public function requestCancel($jobId): void
    {
        if (empty($jobId)) {
            return;
        }
        $this->update($jobId, ['cancel_requested' => 1]);
    }

    /**
     * Последний прогон (для статуса на admin-странице / reconnect).
     *
     * @return object|null
     */
    public function findLatest()
    {
        $result = $this->findOne([]);

        return $result ?: null;
    }
}
