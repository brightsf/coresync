<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Durable-список картинок товаров карты (`__format__coresync_images`): {url, url_hash, sort} + состояние
 * скачивания. Персистит желаемый набор картинок ВНЕ staging-ФС (line-item M3 §0.2) — фаза картинок
 * переживает любую чистку staging. Границы: только товары, попавшие в карту владения sync'а.
 *
 * Поля: product_external_id (ключ товара ядра), product_local_id (id витрины), url/url_hash/sort,
 * state (pending|done|failed), attempts (число попыток скачивания), filename (локальное имя после
 * зеркалирования), image_id (id строки ImagesEntity — для позиции/удаления).
 */
class CoreSyncImagesEntity extends Entity
{
    protected static $fields = [
        'id',
        'product_external_id',
        'product_local_id',
        'url',
        'url_hash',
        'sort',
        'state',
        'attempts',
        'filename',
        'image_id',
    ];

    protected static $table = '__format__coresync_images';
    protected static $tableAlias = 'csi';
    protected static $defaultOrderFields = [
        'product_local_id ASC',
        'sort ASC',
    ];

    /**
     * «Полное перепринятие»: все картинки → state=pending, attempts=0 (следующий прогон
     * перезеркалирует). Raw-update — без chain-extensions на больших объёмах.
     */
    public function resetStatesToPending(): void
    {
        $update = $this->queryFactory->newUpdate();
        $update->table(self::getTable())
            ->cols(['state' => Contract::IMAGE_STATE_PENDING, 'attempts' => 0]);
        $this->db->query($update);
    }
}
