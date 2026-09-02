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
 * зеркалирования), image_id (id строки ImagesEntity — для позиции/удаления), content_sha256 (sha256
 * СОДЕРЖИМОГО объекта по обещанию ядра, {@see Contract::IMAGE_CONTENT_SHA256_KEY} — короткое замыкание
 * усыновления ПЕРЕД скачиванием; поле обязано быть в $fields, иначе find() его не выберет).
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
        'content_sha256',
        'error_code',
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
            ->cols(['state' => Contract::IMAGE_STATE_PENDING, 'attempts' => 0, 'error_code' => null]);
        $this->db->query($update);
    }

    /**
     * Сколько failed-строк тик ещё имеет право переиграть (attempts < капа) — предикат добора хвоста.
     * Один COUNT-запрос, зеркальная форма у CoreSyncCategoryImagesEntity.
     */
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

    /**
     * «Сбросить попытки без полного перепринятия» (coresync:retry-images): у failed-строк
     * attempts=0 и error_code=null, state НЕ меняется — строка снова retryable и уйдёт в ближайший
     * тик. Raw-update без chain-extensions, как resetStatesToPending().
     */
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
     * B. «Состояние картинок невидимо» (брифа контекст): COUNT по state, один запрос — панель
     * складывает это со счётом CoreSyncCategoryImagesEntity::countByState() (товарные + категорийные,
     * суммарно). Отсутствующее в выборке состояние возвращается нулём.
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
