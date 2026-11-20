<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;

/**
 * Документы товара из ядра в своём хранилище: привязка ядра (document_external_id) к товару,
 * подпись и порядок, ссылка на файл по sha256. Строк не из ядра здесь не бывает.
 */
class CoreSyncProductDocumentsEntity extends Entity
{
    protected static $fields = [
        'id', 'product_external_id', 'product_local_id', 'document_external_id', 'title', 'position',
        'language', 'document_type', 'sha256',
    ];
    protected static $table = '__format__coresync_product_documents';
    protected static $tableAlias = 'cspd';
    protected static $defaultOrderFields = ['product_local_id ASC', 'position ASC', 'id ASC'];
}
