<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;

/**
 * Карта владения sync'а: границы того, что модуль вправе трогать в витрине.
 * (external_id ядра → local_id Okay; applied_hash/image_state — задел M2/M3.)
 */
class CoreSyncMapEntity extends Entity
{
    protected static $fields = [
        'id',
        'entity_type',
        'external_id',
        'local_id',
        'applied_hash',
        'image_state',
    ];

    protected static $table = '__format__coresync_map';
    protected static $tableAlias = 'csm';
    protected static $defaultOrderFields = [
        'id ASC',
    ];
}
