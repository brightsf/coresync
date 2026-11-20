<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;

/**
 * Файлы своего хранилища документов: один файл на sha256 брендированной копии из ядра
 * (files/coresync/documents/<sha256>.pdf), сколько бы товаров на него ни ссылалось.
 */
class CoreSyncDocumentFilesEntity extends Entity
{
    protected static $fields = ['id', 'sha256', 'bytes', 'path'];
    protected static $table = '__format__coresync_document_files';
    protected static $tableAlias = 'csdf';
    protected static $defaultOrderFields = ['id ASC'];
}
