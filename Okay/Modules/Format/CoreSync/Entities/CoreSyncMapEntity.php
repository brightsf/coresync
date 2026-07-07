<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Карта владения sync'а: границы того, что модуль вправе трогать в витрине.
 * (external_id ядра → local_id Okay; applied_hash — skip-инвариант; image_state — coarse-флаг картинок.)
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

    /**
     * «Полное перепринятие» (лечение дрифта): applied_hash всех строк → NULL, image_state товаров →
     * pending. Следующий прогон переприменяет всё той же версией (raw-update — не дёргаем
     * chain-extensions на десятках тысяч строк).
     */
    public function resetForReapply(): void
    {
        $resetHash = $this->queryFactory->newUpdate();
        $resetHash->table(self::getTable())->set('applied_hash', null); // → SET applied_hash = NULL
        $this->db->query($resetHash);

        $resetImages = $this->queryFactory->newUpdate();
        $resetImages->table(self::getTable())
            ->cols(['image_state' => Contract::IMAGE_STATE_PENDING]) // bound-значение
            ->where('entity_type = :type')
            ->where('image_state IS NOT NULL')
            ->bindValue('type', Contract::ENTITY_PRODUCT);
        $this->db->query($resetImages);
    }
}
