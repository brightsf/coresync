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

    /** Зеркало CoreSyncImagesEntity::countRetryableFailed() для категорийной очереди. */
    public function countRetryableFailed(int $maxAttempts): int
    {
        if ($maxAttempts <= 0) {
            return 0;
        }

        $select = $this->queryFactory->newSelect();
        $select->cols(['COUNT(*) AS count'])
            ->from(self::getTable())
            ->where('state = :state')
            ->where('attempts < :max_attempts')
            ->bindValues([
                'state' => Contract::IMAGE_STATE_FAILED,
                'max_attempts' => $maxAttempts,
            ]);

        $this->db->query($select);

        return (int) $this->db->result('count');
    }

    /** Зеркало CoreSyncImagesEntity::resetFailedAttempts() для категорийной очереди. */
    public function resetFailedAttempts(): void
    {
        $update = $this->queryFactory->newUpdate();
        $update->table(self::getTable())
            ->cols(['attempts' => 0, 'error_code' => null])
            ->where('state = :state')
            ->bindValue('state', Contract::IMAGE_STATE_FAILED);
        $this->db->query($update);
    }

    /**
     * B. Зеркало CoreSyncImagesEntity::countByState() для категорийных картинок — та же форма ответа,
     * панель складывает оба счёта суммарно.
     *
     * @return array<string, int> state => count, ключи — ровно Contract::IMAGE_STATES
     */
    public function countByState(): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['state', 'COUNT(*) AS count'])
            ->from(self::getTable())
            ->groupBy(['state']);

        $this->db->query($select);
        $rows = $this->db->results('count', 'state');

        $counts = array_fill_keys(Contract::IMAGE_STATES, 0);
        foreach ($rows as $state => $count) {
            if (array_key_exists($state, $counts)) {
                $counts[$state] = (int) $count;
            }
        }

        return $counts;
    }
}
