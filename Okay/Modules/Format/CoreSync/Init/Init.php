<?php

namespace Okay\Modules\Format\CoreSync\Init;

use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\Scheduler\Schedule;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;

class Init extends AbstractInit
{
    const PERMISSION = 'format_coresync';

    const MAP_TABLE       = '__format__coresync_map';
    const JOBS_TABLE      = '__format__coresync_jobs';
    const JOB_FILES_TABLE = '__format__coresync_job_files';

    public function install()
    {
        $this->setBackendMainController('CoreSyncAdmin');

        // Карта владения sync'а (external_id ядра → local_id витрины).
        $mapExternalId = (new EntityField('external_id'))->setTypeVarchar(64, false);
        $this->migrateCustomTable(self::MAP_TABLE, [
            (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
            (new EntityField('entity_type'))->setTypeVarchar(16, false)->setIndexUnique(null, $mapExternalId),
            $mapExternalId,
            (new EntityField('local_id'))->setTypeInt(11, true),
            (new EntityField('applied_hash'))->setTypeVarchar(64, true),
            (new EntityField('image_state'))->setTypeVarchar(16, true),
        ]);

        // Прогоны синхронизации (прогресс/статус/отмена/reconnect).
        $this->migrateCustomTable(self::JOBS_TABLE, [
            (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
            (new EntityField('status'))->setTypeEnum(Contract::JOB_STATUSES, false)
                ->setDefault(Contract::STATUS_CREATED)->setIndex(),
            (new EntityField('snapshot_version'))->setTypeInt(11, true)->setIndex(),
            (new EntityField('phase'))->setTypeVarchar(32, true),
            (new EntityField('files_total'))->setTypeInt(11, false)->setDefault(0),
            (new EntityField('files_done'))->setTypeInt(11, false)->setDefault(0),
            (new EntityField('bytes_done'))->setTypeInt(20, false)->setDefault(0),
            (new EntityField('error_message'))->setTypeText()->setNullable(),
            (new EntityField('cancel_requested'))->setTypeTinyInt(1, false)->setDefault(0),
            (new EntityField('started_at'))->setTypeDatetime(true),
            (new EntityField('finished_at'))->setTypeDatetime(true),
            (new EntityField('created'))->setTypeTimestamp(false),
            (new EntityField('updated'))->setTypeTimestamp(false),
        ]);

        // Чекпоинт файлов набора (resume/докачка).
        $this->migrateCustomTable(self::JOB_FILES_TABLE, [
            (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
            (new EntityField('job_id'))->setTypeInt(11, false)->setIndex(),
            (new EntityField('name'))->setTypeVarchar(255, false),
            (new EntityField('sha256_expected'))->setTypeVarchar(64, false),
            (new EntityField('bytes'))->setTypeInt(20, false)->setDefault(0),
            (new EntityField('status'))->setTypeEnum(Contract::FILE_STATUSES, false)
                ->setDefault(Contract::FILE_PENDING)->setIndex(),
        ]);
    }

    public function init()
    {
        $this->registerBackendController('CoreSyncAdmin');
        $this->addBackendControllerPermission('CoreSyncAdmin', self::PERMISSION);

        $this->extendBackendMenu('left_catalog', [
            'format_coresync__left_catalog__title' => ['CoreSyncAdmin'],
        ]);

        // Крон: свежесть «часы» (каждые 30 минут), без наложений.
        $this->registerSchedule(
            (new Schedule([SyncRunner::class, 'run']))
                ->name('CoreSync snapshot pull')
                ->time('*/30 * * * *')
                ->overlap(false)
                ->timeout(3600)
        );
    }
}
