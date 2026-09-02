<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;
use Tests\Modules\Format\CoreSync\Support\FakeCategoryImageDownloader;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Кап попыток на пути ТИКА (D-CORESYNC-FAILED-TAIL-NOT-RETRIED, половина 2).
 *
 * Тик (applyPendingImages) переигрывает failed-строку, пока attempts < Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS,
 * и НЕ трогает исчерпанную вовсе: не качает, не считает попыткой, не пишет строку — иначе мёртвая
 * ссылка донора (404) переигрывается каждым тиком крона вечно. Полный apply (новая версия,
 * force-reapply) кап не применяет: исчерпанная строка там переигрывается как и раньше.
 */
class ImageRetryTailTest extends TestCase
{
    use BuildsApplierEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initStaging();
    }

    protected function tearDown(): void
    {
        $this->cleanupStaging();
        parent::tearDown();
    }

    /** B1. Тик: исчерпанная товарная строка не трогается байт-в-байт, retryable рядом — качается. */
    public function testTickSkipsExhaustedProductImageAndStillRetriesTheOneUnderTheCap(): void
    {
        $env = $this->buildEnv();
        $env->prod->rows[1] = ['id' => 1, 'visible' => 1, 'main_image_id' => null];
        $env->prod->rows[2] = ['id' => 2, 'visible' => 1, 'main_image_id' => null];
        $exhaustedId = (int) $env->csimg->add($this->durableRow(
            '1',
            1,
            'https://cdn/exhausted.jpg',
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS
        ));
        $retryableId = (int) $env->csimg->add($this->durableRow(
            '2',
            2,
            'https://cdn/retryable.jpg',
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS - 1
        ));
        $exhaustedBefore = $env->csimg->rows[$exhaustedId];

        $stats = new ApplyStats();
        $status = $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $stats);

        self::assertSame(Contract::STATUS_APPLIED, $status);
        self::assertSame(
            ['https://cdn/retryable.jpg'],
            $env->downloader->requested,
            'исчерпанная строка тиком не скачивается, retryable рядом — скачивается'
        );
        self::assertSame(
            $exhaustedBefore,
            $env->csimg->rows[$exhaustedId],
            'исчерпанная строка не изменена: ни attempts, ни state, ни error_code'
        );
        self::assertSame(0, $stats->imagesFailed, 'исчерпанная строка не считается неудачной попыткой');
        self::assertSame(1, $stats->imagesDownloaded);
        self::assertSame(Contract::IMAGE_STATE_DONE, $env->csimg->rows[$retryableId]['state']);
        self::assertSame(
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS,
            (int) $env->csimg->rows[$retryableId]['attempts'],
            'удачный ретрай под капом считает свою попытку'
        );
    }

    /** B2. То же для КАТЕГОРИЙНОЙ очереди (schema major 2, своя выборка pending+failed). */
    public function testTickSkipsExhaustedCategoryImageAndStillRetriesTheOneUnderTheCap(): void
    {
        $categoryDownloader = new FakeCategoryImageDownloader();
        $env = $this->buildEnv(['UAH' => 7], null, null, true, $categoryDownloader);
        $exhaustedCategoryId = (int) $env->cat->add([
            'external_id' => '900',
            'url' => 'nasosy',
            'parent_id' => 0,
            'name' => 'Насосы',
        ]);
        $retryableCategoryId = (int) $env->cat->add([
            'external_id' => '901',
            'url' => 'truby',
            'parent_id' => 0,
            'name' => 'Трубы',
        ]);
        $exhaustedId = (int) $env->cscatimg->add($this->durableCategoryRow(
            '900',
            $exhaustedCategoryId,
            'https://cdn/category-exhausted.jpg',
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS
        ));
        $retryableId = (int) $env->cscatimg->add($this->durableCategoryRow(
            '901',
            $retryableCategoryId,
            'https://cdn/category-retryable.jpg',
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS - 1
        ));
        $exhaustedBefore = $env->cscatimg->rows[$exhaustedId];

        $stats = new ApplyStats();
        $status = $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $stats, 2);

        self::assertSame(Contract::STATUS_APPLIED, $status);
        self::assertCount(1, $categoryDownloader->requested, 'исчерпанная категорийная строка не скачивается');
        self::assertSame(
            'https://cdn/category-retryable.jpg',
            $categoryDownloader->requested[0]['descriptor']['url']
        );
        self::assertSame(
            $exhaustedBefore,
            $env->cscatimg->rows[$exhaustedId],
            'исчерпанная категорийная строка не изменена'
        );
        self::assertSame(1, $stats->categoryImagesPending, 'исчерпанная строка не попадает в attempted');
        self::assertSame(0, $stats->categoryImagesFailed);
        self::assertSame(Contract::IMAGE_STATE_DONE, $env->cscatimg->rows[$retryableId]['state']);
    }

    /**
     * B3 (замок, зелёный ДО и ПОСЛЕ капа). Кап живёт только на пути тика: полный apply переигрывает
     * исчерпанную строку как и раньше — иначе «перепринять» перестало бы чинить хвост.
     */
    public function testFullApplyStillReplaysExhaustedProductImage(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());

        $rowId = (int) array_keys($env->csimg->rows)[0];
        $env->csimg->rows[$rowId]['state'] = Contract::IMAGE_STATE_FAILED;
        $env->csimg->rows[$rowId]['attempts'] = Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS + 4;
        $env->csimg->rows[$rowId]['error_code'] = 'download_failed';
        $requestsBefore = count($env->downloader->requested);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        self::assertSame(Contract::STATUS_APPLIED, $status);
        self::assertSame(
            $requestsBefore + 1,
            count($env->downloader->requested),
            'полный apply не применяет кап тика'
        );
        self::assertSame(1, $stats->imagesDownloaded);
        self::assertSame(Contract::IMAGE_STATE_DONE, $env->csimg->rows[$rowId]['state']);
        self::assertSame(
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS + 5,
            (int) $env->csimg->rows[$rowId]['attempts'],
            'семантика attempts в полном apply не меняется'
        );
    }

    /** B4. Неудача на тике считает попытку; на кап-й неудаче строка становится exhausted и следующий тик её не берёт. */
    public function testTickFailureBurnsAnAttemptAndTheCapClosesTheRowForLaterTicks(): void
    {
        $url = 'https://cdn/dead-link.jpg';
        $env = $this->buildEnv();
        $env->prod->rows[1] = ['id' => 1, 'visible' => 1, 'main_image_id' => null];
        $env->downloader->failUrls = [$url];
        $rowId = (int) $env->csimg->add($this->durableRow(
            '1',
            1,
            $url,
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS - 1
        ));

        $failingStats = new ApplyStats();
        $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $failingStats);

        self::assertSame([$url], $env->downloader->requested, 'последняя попытка под капом ещё делается');
        self::assertSame(1, $failingStats->imagesFailed);
        self::assertSame(
            Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS,
            (int) $env->csimg->rows[$rowId]['attempts'],
            'неудача тика сжигает попытку'
        );
        self::assertSame(Contract::IMAGE_STATE_FAILED, $env->csimg->rows[$rowId]['state']);
        $exhaustedRow = $env->csimg->rows[$rowId];

        // Донор «починился», но строка уже исчерпана: следующий тик её не трогает.
        $env->downloader->failUrls = [];
        $nextTickStats = new ApplyStats();
        $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $nextTickStats);

        self::assertSame([$url], $env->downloader->requested, 'исчерпанная строка больше не качается тиком');
        self::assertSame(0, $nextTickStats->imagesDownloaded);
        self::assertSame(0, $nextTickStats->imagesFailed);
        self::assertSame($exhaustedRow, $env->csimg->rows[$rowId]);
    }

    /** @return array<string, mixed> */
    private function durableRow(string $productExternalId, int $productLocalId, string $url, int $attempts): array
    {
        return [
            'product_external_id' => $productExternalId,
            'product_local_id' => $productLocalId,
            'url' => $url,
            'url_hash' => str_pad('hash' . $productExternalId, 64, '0'),
            'sort' => 0,
            'state' => Contract::IMAGE_STATE_FAILED,
            'attempts' => $attempts,
            'filename' => null,
            'image_id' => null,
            'content_sha256' => null,
            'error_code' => 'download_failed',
        ];
    }

    /** @return array<string, mixed> */
    private function durableCategoryRow(
        string $categoryExternalId,
        int $categoryLocalId,
        string $url,
        int $attempts
    ): array {
        return [
            'category_external_id' => $categoryExternalId,
            'category_local_id' => $categoryLocalId,
            'source_instance' => 'grundfos',
            'source_id' => $categoryExternalId,
            'url' => $url,
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
            'state' => Contract::IMAGE_STATE_FAILED,
            'attempts' => $attempts,
            'filename' => null,
            'error_code' => 'download_failed',
        ];
    }
}
