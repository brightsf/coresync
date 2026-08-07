<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/** One durable desired image descriptor per core category. */
class CoreSyncCategoryImagesEntity extends Entity
{
    protected static $fields = [
        'id',
        'category_external_id',
        'category_local_id',
        'source_instance',
        'source_id',
        'url',
        'sha256',
        'mime',
        'bytes',
        'state',
        'attempts',
        'filename',
        'error_code',
    ];

    protected static $table = '__format__coresync_category_images';
    protected static $tableAlias = 'csci';
    protected static $defaultOrderFields = ['category_local_id ASC'];

    public function resetStatesToPending(): void
    {
        $update = $this->queryFactory->newUpdate();
        $update->table(self::getTable())->cols([
            'state' => Contract::IMAGE_STATE_PENDING,
            'attempts' => 0,
            'error_code' => null,
        ]);
        $this->db->query($update);
    }
}
