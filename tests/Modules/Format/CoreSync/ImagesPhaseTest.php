<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryContentAdopter;
use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
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

    public function testPendingImagesEntrypointRunsOnlyDurableImagesPhase(): void
    {
        self::assertTrue(
            method_exists(Applier::class, 'applyPendingImages'),
            'Applier needs a public pending-images-only entrypoint'
        );

        $adopter = $this->createMock(GalleryContentAdopter::class);
        $adopter->expects(self::never())->method('root');
        $adopter->expects(self::never())->method('match');
        $env = $this->buildEnv([], null, $adopter);

        // Seed only the durable image inputs. An unrelated mapped product/variant makes absent/full
        // mutations observable, while an empty currency map makes reaching the currency gate fatal.
        $env->prod->rows[1] = ['id' => 1, 'visible' => 1, 'main_image_id' => null];
        $env->prod->rows[2] = ['id' => 2, 'visible' => 1, 'main_image_id' => null];
        $env->var->rows[9] = ['id' => 9, 'product_id' => 2, 'external_id' => 'v2', 'stock' => 7];
        $env->map->add([
            'entity_type' => Contract::ENTITY_PRODUCT, 'external_id' => '1', 'local_id' => 1,
            'applied_hash' => str_repeat('a', 64), 'image_state' => Contract::IMAGE_STATE_PENDING,
        ]);
        $env->map->add([
            'entity_type' => Contract::ENTITY_PRODUCT, 'external_id' => '2', 'local_id' => 2,
            'applied_hash' => str_repeat('b', 64), 'image_state' => Contract::IMAGE_STATE_DONE,
        ]);
        $env->map->add([
            'entity_type' => Contract::ENTITY_VARIANT, 'external_id' => 'v2', 'local_id' => 9,
            'applied_hash' => str_repeat('c', 64), 'image_state' => null,
        ]);
        $env->csimg->add([
            'product_external_id' => '1', 'product_local_id' => 1,
            'url' => 'https://cdn/pending.jpg', 'url_hash' => str_repeat('d', 64), 'sort' => 0,
            'state' => Contract::IMAGE_STATE_PENDING, 'attempts' => 0,
            'filename' => null, 'image_id' => null, 'content_sha256' => null,
        ]);
        $mapRowsBefore = count($env->map->rows);
        $mapWritesBefore = count($env->map->writeLog);

        $stats = new ApplyStats();
        $status = $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $stats);

        self::assertSame(Contract::STATUS_APPLIED, $status);
        self::assertSame(['https://cdn/pending.jpg'], $env->downloader->requested);
        self::assertSame(Contract::IMAGE_STATE_DONE, array_values($env->csimg->rows)[0]['state']);
        self::assertSame(1, $stats->imagesDownloaded);

        self::assertSame(0, $env->cat->mutations(), 'full category phase must stay unreachable');
        self::assertSame(0, $env->brand->mutations(), 'full brand phase must stay unreachable');
        self::assertSame(0, $env->feat->mutations(), 'full feature phase must stay unreachable');
        self::assertSame([], $env->fv->addCalls, 'full feature-value phase must stay unreachable');
        self::assertSame([], $env->var->updateCalls, 'absent/price phases must not touch variants');
        self::assertSame(7, $env->var->rows[9]['stock']);
        self::assertSame(0, $env->redir->mutations(), 'full redirects phase must stay unreachable');
        self::assertCount(1, $env->prod->updateCalls, 'only main-image refresh may touch the product');
        self::assertSame([1, ['main_image_id' => 1]], $env->prod->updateCalls[0]);
        self::assertSame($mapRowsBefore, count($env->map->rows), 'bind must not create map rows');
        self::assertSame($mapWritesBefore + 1, count($env->map->writeLog), 'only coarse image state is updated');
        foreach ($env->map->rows as $row) {
            self::assertNotSame(Contract::ENTITY_BIND_MARKER, $row['entity_type'] ?? null);
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

    public function testContentDriftAtSameUrlRequeuesAndReplacesDoneImage(): void
    {
        $oldSha = str_repeat('a', 64);
        $newSha = str_repeat('b', 64);
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0, $oldSha),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $before = array_values($env->csimg->rows)[0];
        $oldImageId = (int) $before['image_id'];
        $env->csimg->rows[$before['id']]['attempts'] = 5;
        $requestsBefore = count($env->downloader->requested);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0, $newSha),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame($requestsBefore + 1, count($env->downloader->requested), 'same URL with new bytes is downloaded again');
        $this->assertSame(1, $stats->imagesDownloaded);
        $this->assertSame($newSha, $after[Contract::IMAGE_CONTENT_SHA256_FIELD]);
        $this->assertSame(Contract::IMAGE_STATE_DONE, $after['state']);
        $this->assertSame(1, (int) $after['attempts'], 'drift resets attempts before the retry');
        $this->assertNotSame($oldImageId, (int) $after['image_id']);
        $this->assertContains($oldImageId, $env->img->deleteCalls, 'old managed row is removed only after replacement');
        $this->assertCount(1, $env->img->rows);
    }

    /** @dataProvider contentShaWithoutDriftSignal */
    public function testEmptyOrMissingContentShaDoesNotResetDoneImage(?string $snapshotSha): void
    {
        $oldSha = str_repeat('a', 64);
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0, $oldSha),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $before = array_values($env->csimg->rows)[0];
        $requestsBefore = count($env->downloader->requested);
        $durableUpdates = 0;
        $env->csimg->onUpdate = static function () use (&$durableUpdates): void {
            $durableUpdates++;
        };

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0, $snapshotSha),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame($requestsBefore, count($env->downloader->requested), 'missing sha is not a drift signal');
        $this->assertSame(0, $stats->imagesDownloaded);
        $this->assertSame(0, $durableUpdates, 'done durable row is untouched without a snapshot sha');
        $this->assertSame($oldSha, $after[Contract::IMAGE_CONTENT_SHA256_FIELD]);
        $this->assertSame(Contract::IMAGE_STATE_DONE, $after['state']);
        $this->assertSame($before['attempts'], $after['attempts']);
        $this->assertSame($before['image_id'], $after['image_id']);
    }

    /** @return array<string, array{0:string|null}> */
    public function contentShaWithoutDriftSignal(): array
    {
        return [
            'missing key' => [null],
            'empty value' => [''],
        ];
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

    public function testFullReapplyReplacesManagedImageWithoutGrowingGallery(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 3),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());
        $firstDurable = array_values($env->csimg->rows)[0];
        $firstImageId = (int) $firstDurable['image_id'];
        $env->csimg->rows[$firstDurable['id']]['state'] = Contract::IMAGE_STATE_PENDING;
        $this->setProductImageState($env, Contract::IMAGE_STATE_PENDING);

        $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        $after = array_values($env->csimg->rows)[0];
        $this->assertCount(1, $env->img->rows, 'replacement leaves one managed Okay image row');
        $this->assertNotSame($firstImageId, (int) $after['image_id']);
        $this->assertArrayNotHasKey($firstImageId, $env->img->rows, 'old managed row is removed after install');
        $this->assertContains($firstImageId, $env->img->deleteCalls);
        $this->assertSame(4, (int) $env->img->rows[$after['image_id']]['position'], 'sort survives reapply');
        $this->assertTrue($this->hasMainImageUpdate($env->prod, (int) $after['image_id']));
        $this->assertSame(Contract::IMAGE_STATE_DONE, $this->productImageState($env));

        $env->csimg->rows[$after['id']]['state'] = Contract::IMAGE_STATE_PENDING;
        $this->setProductImageState($env, Contract::IMAGE_STATE_PENDING);
        $this->runApply($env, $this->productsManifest('full', ['files' => []]));
        $this->assertCount(1, $env->img->rows, 'repeat full reapply remains gallery-idempotent');
    }

    public function testFailedOrThrowingReplacementPreservesInstalledImageAndDurablePointer(): void
    {
        foreach (['failUrls', 'throwUrls'] as $failureMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $oldFilename = (string) $before['filename'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->downloader->{$failureMode} = ['https://cdn/a.jpg'];

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame($oldImageId, (int) $after['image_id']);
            $this->assertSame($oldFilename, (string) $after['filename']);
            $this->assertArrayHasKey($oldImageId, $env->img->rows);
            $this->assertNotContains($oldImageId, $env->img->deleteCalls);
        }
    }

    public function testReplacementRollsBackNewImageWhenDurableInstallThrows(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $oldImageId = (int) $before['image_id'];
        $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
        $thrown = false;
        $env->csimg->onUpdate = static function (int $id, array $patch) use (&$thrown): void {
            if (!$thrown && ($patch['state'] ?? null) === Contract::IMAGE_STATE_DONE) {
                $thrown = true;
                throw new \RuntimeException('injected durable install failure');
            }
        };

        [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(1, $stats->imagesFailed);
        $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
        $this->assertSame($oldImageId, (int) $after['image_id']);
        $this->assertCount(1, $env->img->rows);
        $this->assertArrayHasKey($oldImageId, $env->img->rows);
        $this->assertArrayNotHasKey(2, $env->img->rows);
    }

    public function testReplacementCleansDownloadedFileWhenImagesEntityAddThrows(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $oldImageId = (int) $before['image_id'];
        $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
        $env->img->throwOnAdd = true;

        [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(1, $stats->imagesFailed);
        $this->assertSame($oldImageId, (int) $after['image_id']);
        $this->assertCount(1, $env->img->rows);
        $this->assertCount(1, $env->downloader->deletedOwned, 'fresh unowned file is removed');
        $this->assertArrayNotHasKey($env->downloader->deletedOwned[0], $env->downloader->ownedFiles);
    }

    public function testOldDeleteFalseOrThrowRollsBackReplacementWhenOldRowIsIntact(): void
    {
        foreach (['returnFalseOnDeleteIds', 'throwOnDeleteIds'] as $failureMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->img->{$failureMode} = [$oldImageId];

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame($oldImageId, (int) $after['image_id']);
            $this->assertCount(1, $env->img->rows);
            $this->assertArrayNotHasKey(2, $env->img->rows);
            $this->assertArrayHasKey($oldImageId, $env->img->rows);
        }
    }

    public function testFailedOldDeleteKeepsLiveReplacementWhenDurableRestoreFalseOrThrows(): void
    {
        foreach (['false', 'throw'] as $restoreMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->img->returnFalseOnDeleteIds = [$oldImageId];
            $env->csimg->onUpdate = static function (int $id, array $patch) use ($env, $oldImageId, $restoreMode): void {
                $isOldPointerRestore = ($patch['state'] ?? null) === Contract::IMAGE_STATE_FAILED
                    && (int) ($patch['image_id'] ?? 0) === $oldImageId;
                if (!$isOldPointerRestore) {
                    return;
                }
                if ($restoreMode === 'throw') {
                    throw new \RuntimeException('injected durable restore failure');
                }
                $env->csimg->returnFalseOnUpdate = true;
            };

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame(2, (int) $after['image_id'], 'failed compensation keeps durable on live replacement');
            $this->assertArrayHasKey(2, $env->img->rows, 'replacement row must not be deleted before confirmed restore');
            $this->assertArrayHasKey($oldImageId, $env->img->rows);
            $this->assertNotContains(2, $env->img->deleteCalls);
            $this->assertArrayHasKey((string) $after['filename'], $env->downloader->ownedFiles);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $this->productImageState($env));
        }
    }

    public function testReviewerProbeRepeatedFailedDurableRestorationCannotPointAtDeletedReplacement(): void
    {
        foreach (['false', 'throw'] as $restoreMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->img->returnFalseOnDeleteIds = [$oldImageId];
            $env->csimg->onUpdate = static function (int $id, array $patch) use ($env, $restoreMode): void {
                if (($patch['state'] ?? null) !== Contract::IMAGE_STATE_FAILED) {
                    return;
                }
                if ($restoreMode === 'throw') {
                    throw new \RuntimeException('injected repeated durable compensation failure');
                }
                $env->csimg->returnFalseOnUpdate = true;
            };

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(2, (int) $after['image_id']);
            $this->assertSame(Contract::IMAGE_STATE_DONE, $after['state'], 'both fail-closed durable writes were unconfirmed');
            $this->assertArrayHasKey((int) $after['image_id'], $env->img->rows, 'durable must never point at a deleted replacement');
            $this->assertNotContains(2, $env->img->deleteCalls);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $this->productImageState($env), 'coarse marker fails closed independently');
        }
    }

    public function testAddFalseAndDurableUpdateFalseRemainRetryableWithoutLeaks(): void
    {
        foreach (['add', 'durable'] as $failureMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            if ($failureMode === 'add') {
                $env->img->returnFalseOnAdd = true;
            } else {
                $env->csimg->returnFalseOnUpdate = true;
            }

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame($oldImageId, (int) $after['image_id']);
            $this->assertCount(1, $env->img->rows);
            if ($failureMode === 'add') {
                $this->assertCount(1, $env->downloader->deletedOwned, 'add false cleans the unowned download');
                $this->assertArrayNotHasKey($env->downloader->deletedOwned[0], $env->downloader->ownedFiles);
            }
        }
    }

    public function testDoubleCleanupFailureNeverRestoresDurableToTheWrongImage(): void
    {
        foreach (['false', 'throw'] as $rollbackMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->img->returnFalseOnDeleteIds = [$oldImageId];
            if ($rollbackMode === 'false') {
                $env->img->returnFalseOnDeleteIds[] = 2;
            } else {
                $env->img->throwOnDeleteIds = [2];
            }

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame($oldImageId, (int) $after['image_id'], 'confirmed durable restore remains truthful when replacement cleanup fails');
            $this->assertArrayHasKey(2, $env->img->rows);
        }
    }

    public function testDurableFailureAndReplacementCleanupFailureKeepTruthfulLivePointer(): void
    {
        foreach (['false', 'throw'] as $cleanupMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $oldImageId = (int) $before['image_id'];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $thrown = false;
            $env->csimg->onUpdate = static function (int $id, array $patch) use (&$thrown): void {
                if (!$thrown && ($patch['state'] ?? null) === Contract::IMAGE_STATE_DONE) {
                    $thrown = true;
                    throw new \RuntimeException('durable switch threw after replacement add');
                }
            };
            if ($cleanupMode === 'false') {
                $env->img->returnFalseOnDeleteIds = [2];
            } else {
                $env->img->throwOnDeleteIds = [2];
            }

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $after = array_values($env->csimg->rows)[0];
            $this->assertSame(1, $stats->imagesFailed);
            $this->assertSame(Contract::IMAGE_STATE_FAILED, $after['state']);
            $this->assertSame($oldImageId, (int) $after['image_id']);
            $this->assertArrayHasKey($oldImageId, $env->img->rows);
            $this->assertArrayHasKey(2, $env->img->rows);
        }
    }

    public function testOwnedFileCleanupFailureIsObservableInFakePostcondition(): void
    {
        foreach (['returnFalseOnDeleteOwned', 'throwOnDeleteOwned'] as $cleanupMode) {
            $env = $this->installedImageEnv();
            $before = array_values($env->csimg->rows)[0];
            $env->csimg->rows[$before['id']]['state'] = Contract::IMAGE_STATE_PENDING;
            $env->img->returnFalseOnAdd = true;
            $env->downloader->{$cleanupMode} = true;

            [, $stats] = $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $this->assertSame(1, $stats->imagesFailed);
            $this->assertCount(1, $env->downloader->deletedOwned, 'cleanup was attempted');
            $this->assertArrayHasKey($env->downloader->deletedOwned[0], $env->downloader->ownedFiles, 'failed cleanup remains detectable');
        }
    }

    public function testAlreadyDoneRowsRefreshCoarseMarkerButMixedAndEmptyNeverBecomeDone(): void
    {
        $done = $this->installedImageEnv();
        $this->setProductImageState($done, Contract::IMAGE_STATE_PENDING);
        $downloadsBefore = count($done->downloader->requested);
        $this->runApply($done, $this->productsManifest('full', ['files' => []]));
        $this->assertSame(Contract::IMAGE_STATE_DONE, $this->productImageState($done));
        $this->assertSame($downloadsBefore, count($done->downloader->requested), 'done rows are reconciled without download');

        $mixed = $this->installedImageEnv();
        $row = array_values($mixed->csimg->rows)[0];
        $mixed->csimg->add([
            'product_external_id' => '1', 'product_local_id' => 1,
            'url' => 'https://cdn/b.jpg', 'url_hash' => str_repeat('b', 64), 'sort' => 1,
            'state' => Contract::IMAGE_STATE_FAILED, 'attempts' => 1, 'filename' => null, 'image_id' => null,
        ]);
        $mixed->downloader->failUrls = ['https://cdn/b.jpg'];
        $this->setProductImageState($mixed, Contract::IMAGE_STATE_PENDING);
        $this->runApply($mixed, $this->productsManifest('full', ['files' => []]));
        $this->assertNotSame(Contract::IMAGE_STATE_DONE, $this->productImageState($mixed));
        $this->assertSame(Contract::IMAGE_STATE_DONE, $mixed->csimg->rows[$row['id']]['state']);

        $empty = $this->buildEnv();
        $empty->map->add([
            'entity_type' => Contract::ENTITY_PRODUCT, 'external_id' => '1', 'local_id' => 1,
            'applied_hash' => str_repeat('h', 64), 'image_state' => Contract::IMAGE_STATE_PENDING,
        ]);
        $this->runApply($empty, $this->productsManifest('full', ['files' => []]));
        $this->assertSame(Contract::IMAGE_STATE_PENDING, $this->productImageState($empty), 'empty desired image set is not done');
    }

    public function testDoneStateWithoutLiveDurablePointerCannotMarkProductDone(): void
    {
        foreach (['image_id', 'filename'] as $missingField) {
            $env = $this->installedImageEnv();
            $row = array_values($env->csimg->rows)[0];
            $env->csimg->rows[$row['id']][$missingField] = null;
            $this->setProductImageState($env, Contract::IMAGE_STATE_PENDING);

            $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $this->assertNotSame(
                Contract::IMAGE_STATE_DONE,
                $this->productImageState($env),
                "done durable row without {$missingField} is invalid"
            );
        }
    }

    public function testDonePointerMustMatchActualOkayImageOwnershipAndFilename(): void
    {
        foreach (['product_id', 'filename'] as $driftField) {
            $env = $this->installedImageEnv();
            $row = array_values($env->csimg->rows)[0];
            $imageId = (int) $row['image_id'];
            $env->img->rows[$imageId][$driftField] = $driftField === 'product_id' ? 999 : 'foreign.jpg';
            $this->setProductImageState($env, Contract::IMAGE_STATE_PENDING);

            $this->runApply($env, $this->productsManifest('full', ['files' => []]));

            $this->assertNotSame(Contract::IMAGE_STATE_DONE, $this->productImageState($env));
        }
    }

    public function testCoarseMarkerRequiresExactExternalAndLocalProductPair(): void
    {
        $env = $this->installedImageEnv();
        $mapId = null;
        foreach ($env->map->rows as $id => $row) {
            if (($row['entity_type'] ?? null) === Contract::ENTITY_PRODUCT && ($row['external_id'] ?? null) === '1') {
                $mapId = $id;
                $env->map->rows[$id]['local_id'] = 999;
                $env->map->rows[$id]['image_state'] = Contract::IMAGE_STATE_PENDING;
            }
        }
        $this->assertNotNull($mapId);

        $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        $this->assertSame(Contract::IMAGE_STATE_PENDING, $env->map->rows[$mapId]['image_state']);
    }

    public function testModuleOwnedPositionChangesWithDurableSort(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $imageId = (int) $before['image_id'];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 4),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(4, (int) $after['sort']);
        $this->assertSame(5, (int) $env->img->rows[$imageId]['position']);
        $report = $stats->toArray();
        $this->assertArrayHasKey('positions_preserved', $report);
        $this->assertSame(0, $report['positions_preserved']);
        $this->assertCount(1, $env->img->rows);
    }

    public function testClientPositionIsPreservedWhenDurableSortChanges(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $imageId = (int) $before['image_id'];
        $env->img->rows[$imageId]['position'] = 0;
        $updatesBefore = count($env->img->updateCalls);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $this->attachLogger($env, $logger);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 4),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(4, (int) $after['sort'], 'durable desired order still advances');
        $this->assertSame(0, (int) $env->img->rows[$imageId]['position'], '0-based client position is untouched');
        $this->assertSame($updatesBefore, count($env->img->updateCalls));
        $this->assertSame(1, $stats->positionsPreserved);
        $this->assertSame(1, $stats->toArray()['positions_preserved']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('product_id=1', $warnings[0]);
        $this->assertStringContainsString('image_id=' . $imageId, $warnings[0]);
    }

    public function testClientPositionIdTailIsPreservedWhenDurableSortChanges(): void
    {
        // Доминирующий живой хвост: клиент оставляет position равным id строки галереи.
        $this->assertClientPositionPreservedOnSortDrift(0, 83729, 83729);
    }

    public function testClientOneBasedPositionIsPreservedWhenDurableSortChanges(): void
    {
        // Усыновлённая 1-based строка: durable sort=1, клиентская position=1, module-owned было бы 2.
        $this->assertClientPositionPreservedOnSortDrift(1, 1, 1);
    }

    public function testMissingManagedImageFailsClosedWhenDurableSortChanges(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $imageId = (int) $before['image_id'];
        unset($env->img->rows[$imageId]);
        $updatesBefore = count($env->img->updateCalls);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $this->attachLogger($env, $logger);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 4),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(4, (int) $after['sort']);
        $this->assertSame($updatesBefore, count($env->img->updateCalls), 'missing managed row cannot prove module ownership');
        $this->assertSame(1, $stats->positionsPreserved);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('image_id=' . $imageId, $warnings[0]);
    }

    public function testNormalRepeatKeepsInstalledPointerFilenameAndGalleryCardinality(): void
    {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $requests = count($env->downloader->requested);
        $adds = count($env->img->addCalls);

        $this->runApply($env, $this->productsManifest('full', ['files' => []]));

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame($requests, count($env->downloader->requested));
        $this->assertSame($adds, count($env->img->addCalls));
        $this->assertSame($before['image_id'], $after['image_id']);
        $this->assertSame($before['filename'], $after['filename']);
        $this->assertCount(1, $env->img->rows);
    }

    private function installedImageEnv(): object
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 0),
            ]),
        ]);
        $this->runApply($env, $this->productsManifest());

        return $env;
    }

    private function assertClientPositionPreservedOnSortDrift(
        int $oldDurableSort,
        int $imageId,
        int $clientPosition
    ): void {
        $env = $this->installedImageEnv();
        $before = array_values($env->csimg->rows)[0];
        $currentImageId = (int) $before['image_id'];
        if ($imageId !== $currentImageId) {
            $image = $env->img->rows[$currentImageId];
            unset($env->img->rows[$currentImageId]);
            $image['id'] = $imageId;
            $env->img->rows[$imageId] = $image;
            $env->csimg->rows[$before['id']]['image_id'] = $imageId;
        }
        $env->csimg->rows[$before['id']]['sort'] = $oldDurableSort;
        $env->img->rows[$imageId]['position'] = $clientPosition;
        $updatesBefore = count($env->img->updateCalls);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $this->attachLogger($env, $logger);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 4),
            ]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $after = array_values($env->csimg->rows)[0];
        $this->assertSame(4, (int) $after['sort']);
        $this->assertSame($clientPosition, (int) $env->img->rows[$imageId]['position']);
        $this->assertSame($updatesBefore, count($env->img->updateCalls));
        $this->assertSame(1, $stats->positionsPreserved);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('image_id=' . $imageId, $warnings[0]);
    }

    private function setProductImageState(object $env, string $state): void
    {
        foreach ($env->map->rows as $id => $row) {
            if (($row['entity_type'] ?? null) === Contract::ENTITY_PRODUCT && ($row['external_id'] ?? null) === '1') {
                $env->map->rows[$id]['image_state'] = $state;
            }
        }
    }

    private function productImageState(object $env): ?string
    {
        foreach ($env->map->rows as $row) {
            if (($row['entity_type'] ?? null) === Contract::ENTITY_PRODUCT && ($row['external_id'] ?? null) === '1') {
                return $row['image_state'] ?? null;
            }
        }

        return null;
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

    private function attachLogger(object $env, LoggerInterface $logger): void
    {
        $property = new ReflectionProperty($env->applier, 'logger');
        $property->setAccessible(true);
        $property->setValue($env->applier, $logger);
    }
}
