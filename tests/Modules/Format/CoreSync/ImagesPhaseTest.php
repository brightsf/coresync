<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Догоняющая фаза картинок (M3 §2, только full): новый/изменённый url_hash → download+ImagesEntity
 * (позиции по sort, main = первый); удалённая картинка → удаление строки; fail → счётчик+старое цело+
 * ретрай в следующем прогоне; отмена между товарами + resume; durable-список независим от staging.
 */
class ImagesPhaseTest extends TestCase
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

    public function testNewImagesDownloadedWithPositionsAndMain(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
                $this->image('https://cdn/b.jpg', 'hashB', 1),
            ]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(2, count($env->img->addCalls), 'обе картинки зеркалированы');
        $this->assertSame(1, (int) $env->img->addCalls[0]['position'], 'позиция по sort (1-based: Okay трактует 0 как «не задано»)');
        $this->assertSame(2, (int) $env->img->addCalls[1]['position']);
        $this->assertSame(1, $stats->imagesPending, 'товар с картинками помечен pending на фазе текста');
        $this->assertSame(0, $stats->imagesFailed);

        // main_image = картинка с минимальным sort (первый add, id=1).
        $this->assertTrue($this->hasMainImageUpdate($env->prod, 1), 'main_image = первый по sort');
        // durable-строки → done.
        foreach ($env->csimg->rows as $row) {
            $this->assertSame(Contract::IMAGE_STATE_DONE, $row['state']);
        }
    }

    public function testChangedUrlHashRedownloadsAndDropsOld(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $addsAfterFirst = count($env->img->addCalls);

        // Картинка сменилась (новый url_hash) + hash товара изменился.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/b.jpg', 'hashB', 0),
            ]),
        ]);
        [$status] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, count($env->img->deleteCalls), 'старая картинка удалена из ImagesEntity');
        $this->assertSame($addsAfterFirst + 1, count($env->img->addCalls), 'новая картинка скачана');
        $this->assertContains('https://cdn/b.jpg', $env->downloader->requested);
    }

    public function testRemovedImageDeletesImagesEntityRow(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
                $this->image('https://cdn/b.jpg', 'hashB', 1),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());

        // Картинка B удалена из снапшота (hash товара изменился).
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());

        $this->assertSame(1, count($env->img->deleteCalls), 'удалённая из снапшота картинка → строка ImagesEntity удалена');
        // Осталась одна durable-строка (hashA).
        $this->assertSame(1, count($env->csimg->rows));
    }

    public function testFailedDownloadCountsKeepsOldAndRetriesNextRun(): void
    {
        $env = $this->buildEnv();
        $env->downloader->failUrls = ['https://cdn/a.jpg']; // 1-й прогон падает
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
            ]),
        ]);
        [$status1, $stats1] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status1, 'неудача картинки НЕ блокирует прогон');
        $this->assertSame(1, $stats1->imagesFailed);
        $this->assertSame([], $env->img->addCalls, 'ImagesEntity не пополнена при неудаче');
        $failedRow = array_values($env->csimg->rows)[0];
        $this->assertSame(Contract::IMAGE_STATE_FAILED, $failedRow['state']);
        $this->assertSame(1, (int) $failedRow['attempts']);

        // 2-й прогон: hash товара НЕ менялся (текст skip), но фаза картинок ретраит failed-строку.
        $env->downloader->failUrls = [];
        [$status2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(1, count($env->img->addCalls), 'ретрай следующим прогоном зеркалирует картинку');
        $this->assertSame(Contract::IMAGE_STATE_DONE, array_values($env->csimg->rows)[0]['state']);
    }

    public function testCancelBetweenProductsThenResume(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phoneA', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [$this->image('https://cdn/a.jpg', 'hashA', 0)]),
            $this->productLine('2', 'phoneB', 'h2', [$this->variant('v2', 'SKU-B', '10.00', 1)], [$this->image('https://cdn/b.jpg', 'hashB', 0)]),
        ]);

        // Отмена срабатывает, как только скачана первая картинка (между товарами в фазе картинок).
        $cancel = static function () use ($env): bool {
            return count($env->downloader->requested) >= 1;
        };
        [$status1] = $this->runApplyWithCancel($env, $this->productsManifest(), $cancel);

        $this->assertSame(Contract::STATUS_CANCELLED, $status1);
        $this->assertSame(1, count($env->img->addCalls), 'скачана только первая картинка');
        $notDone = 0;
        foreach ($env->csimg->rows as $row) {
            if ($row['state'] !== Contract::IMAGE_STATE_DONE) {
                $notDone++;
            }
        }
        $this->assertSame(1, $notDone, 'вторая картинка осталась недокачанной');

        // Resume: обычный прогон продолжает с недокачанного (текст skip, фаза картинок добивает).
        [$status2] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(2, count($env->img->addCalls), 'resume докачал вторую картинку');
    }

    public function testImagePhaseWorksFromDurableListWithEmptyStaging(): void
    {
        // 1-й прогон: картинка «не скачалась» → durable-строка failed (текст применён, staging ещё есть).
        $env = $this->buildEnv();
        $env->downloader->failUrls = ['https://cdn/a.jpg'];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [$this->image('https://cdn/a.jpg', 'hashA', 0)]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $this->assertSame([], $env->img->addCalls, 'после неудачи картинка не зеркалирована');

        // Чистим staging (файлы набора удалены) и запускаем прогон БЕЗ product-файлов в манифесте.
        $this->cleanupStaging();
        $this->initStaging(); // пустой staging-каталог
        $env->downloader->failUrls = [];

        [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        // Картинка зеркалирована из durable-списка (staging пуст) — фаза не зависит от retained staging.
        $this->assertSame(1, count($env->img->addCalls), 'фаза картинок работает по durable-списку при пустом staging');
        $this->assertSame(0, $stats->imagesFailed);
    }

    private function hasMainImageUpdate($prod, int $expectedImageId): bool
    {
        foreach ($prod->updateCalls as $call) {
            if (array_key_exists('main_image_id', $call[1]) && (int) $call[1]['main_image_id'] === $expectedImageId) {
                return true;
            }
        }

        return false;
    }
}
