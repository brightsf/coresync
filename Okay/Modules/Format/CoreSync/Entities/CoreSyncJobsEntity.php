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
