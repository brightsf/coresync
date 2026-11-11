<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;

/** CoreSync ownership only. Manual ProductDocuments rows never appear in this table. */
class CoreSyncDocumentsEntity extends Entity
{
    protected static $fields = [
        'id', 'product_external_id', 'product_local_id', 'document_external_id', 'document_id',
        'sha256', 'bytes', 'title', 'position', 'original_filename', 'language', 'document_type',
    ];
    protected static $table = '__format__coresync_documents';
    protected static $tableAlias = 'csd';
    protected static $defaultOrderFields = ['product_local_id ASC', 'position ASC', 'id ASC'];
}
