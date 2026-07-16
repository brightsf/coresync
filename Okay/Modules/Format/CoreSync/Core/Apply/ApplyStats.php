<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Modules\Format\CoreSync\Core\Contract;

/**
 * Счётчики apply-прогона (payload apply-report по контракту).
 * full: upserted/updated/skipped/deactivated/errors/imagesPending/imagesFailed.
 * price_stock: updated/skipped/stockZeroed/skippedNewProducts/skippedNewVariants.
 * bind: bound/unmatched/conflicts (+ conflictSamples — сэмпл SKU в error-детали).
 */
class ApplyStats
{
    /** @var int созданные записи */
    public $upserted = 0;
    /** @var int обновлённые записи */
    public $updated = 0;
    /** @var int пропущенные (hash совпал) */
    public $skipped = 0;
    /** @var int деактивированные варианты/товары (stock=0 / visible=0) */
    public $deactivated = 0;
    /** @var int ошибки строк */
    public $errors = 0;
    /** @var int товары с картинками, отложенными на фазу картинок */
    public $imagesPending = 0;
    /** @var int картинки, которые не удалось скачать (ретрай в следующем прогоне) */
    public $imagesFailed = 0;
    /** @var int записи карты, отсутствующие в снапшоте (для held-отчёта) */
    public $absentCount = 0;

    // --- price_stock ---
    /** @var int связанные варианты, пропавшие из снапшота → stock=0 */
    public $stockZeroed = 0;
    /** @var int новые товары снапшота, не создаваемые в price_stock */
    public $skippedNewProducts = 0;
    /** @var int новые варианты существующих товаров, не создаваемые в price_stock */
    public $skippedNewVariants = 0;

    // --- bind ---
    /** @var int связанные пары (товар/вариант) */
    public $bound = 0;
    /** @var int SKU снапшота без совпадения в каталоге */
    public $unmatched = 0;
    /** @var int конфликты связывания (дубль SKU / варианты разъехались) */
    public $conflicts = 0;
    /** @var list<string> сэмпл проблемных SKU (до BIND_CONFLICT_SAMPLE_MAX) */
    public $conflictSamples = [];

    /**
     * Полный/price_stock payload apply-report (аддитивные ключи — контракт «обнови модуль» не ломается).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'upserted'             => $this->upserted,
            'updated'              => $this->updated,
            'skipped'              => $this->skipped,
            'deactivated'          => $this->deactivated,
            'errors'               => $this->errors,
            'images_pending'       => $this->imagesPending,
            'images_failed'        => $this->imagesFailed,
            'stock_zeroed'         => $this->stockZeroed,
            'skipped_new_products' => $this->skippedNewProducts,
            'skipped_new_variants' => $this->skippedNewVariants,
        ];
    }

    /**
     * Payload bind-отчёта. `outcome` делает исход РАЗЛИЧИМЫМ для оператора в ядре: до этого «связано 0
     * из N» и «связано всё» приезжали одинаково успешным bound с одними счётчиками, на которые никто не
     * смотрит (у прочих фаз есть пороги, у bind — нет). Ключ аддитивный и едет внутри свободного stats:
     * контракт apply-report (snapshot_version/status/stats/error_message) не меняется.
     *
     * @return array<string, mixed>
     */
    public function bindToArray(): array
    {
        return [
            'bound'            => $this->bound,
            'unmatched'        => $this->unmatched,
            'conflicts'        => $this->conflicts,
            'conflict_samples' => $this->conflictSamples,
            'outcome'          => $this->bound > 0
                ? Contract::BIND_OUTCOME_LINKED
                : Contract::BIND_OUTCOME_NOTHING_LINKED,
        ];
    }
}
