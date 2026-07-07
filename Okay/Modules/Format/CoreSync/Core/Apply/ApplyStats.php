<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

/**
 * Счётчики apply-прогона (payload apply-report по контракту).
 * upserted — созданные записи; updated — обновлённые; skipped — пропущенные (hash совпал);
 * deactivated — деактивированные варианты/товары (stock=0 / visible=0); errors — ошибки строк;
 * imagesPending — товары с картинками, отложенными в M3; absentCount — записи карты, отсутствующие
 * в снапшоте (для held-отчёта).
 */
class ApplyStats
{
    /** @var int */
    public $upserted = 0;
    /** @var int */
    public $updated = 0;
    /** @var int */
    public $skipped = 0;
    /** @var int */
    public $deactivated = 0;
    /** @var int */
    public $errors = 0;
    /** @var int */
    public $imagesPending = 0;
    /** @var int */
    public $absentCount = 0;

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'upserted'       => $this->upserted,
            'updated'        => $this->updated,
            'skipped'        => $this->skipped,
            'deactivated'    => $this->deactivated,
            'errors'         => $this->errors,
            'images_pending' => $this->imagesPending,
        ];
    }
}
