<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Усыновление галереи витрины ПО СОДЕРЖИМОМУ: подключаемая витрина, у которой картинки уже лежат,
 * обязана оставить их на месте — ноль скачиваний, ноль новых строк `ok_images`, ноль изменений файлов —
 * и при этом ПОЛОЖИТЕЛЬНО доказать, что усыновление отработало (durable-строки указывают на
 * СУЩЕСТВОВАВШИЕ ДО прогона `image_id`, счётчик усыновлённых равен их числу).
 *
 * Голого «скачиваний ноль» недостаточно: это же подпись невыполненной фазы картинок (без загрузчика
 * она выходит сразу, оставляя строки в pending) — на этот случай есть отдельная спека.
 */
class GalleryContentAdoptionTest extends TestCase
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

    public function testFirstConnectAdoptsExistingGalleryWithoutDownloadsOrNewRows(): void
    {
        $root = $this->initGalleryRoot();
        $bytesA = 'client-bytes-A';
        $bytesB = 'client-bytes-B';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytesA);
        $this->putGalleryFile($root, 'legacy-b.jpg', $bytesB);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        // position 0 и 7: численного равенства position витрины и sort снапшота нет (RECON §1),
        // усыновление обязано опираться на содержимое, а не на позиции.
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $imageB = $this->seedGalleryRow($env, $productId, 'legacy-b.jpg', 7);
        $preExisting = array_keys($env->img->rows);
        $before = $this->galleryWrites($env);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/new-base/a.jpg', 'hashA', 1, hash('sha256', $bytesA)),
                $this->image('https://cdn/new-base/b.jpg', 'hashB', 2, hash('sha256', $bytesB)),
            ]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $env->downloader->requested, 'ноль скачиваний');
        $this->assertSame($before, $this->galleryWrites($env), 'ok_images не пополнена, не изменена, не почищена');
        $this->assertSame($preExisting, array_keys($env->img->rows), 'строки галереи витрины те же');

        // ПОЛОЖИТЕЛЬНОЕ доказательство: усыновление отработало, а не «фаза не выполнилась».
        $this->assertSame(2, $stats->imagesAdopted);
        $this->assertSame(0, $stats->imagesDownloaded);
        $this->assertSame(0, $stats->imagesAdoptionNoHash);
        $this->assertSame(0, $stats->imagesAdoptionMissed);
        $adopted = $this->durableByUrlHash($env);
        foreach ([str_pad('hashA', 64, '0') => [$imageA, 'legacy-a.jpg'], str_pad('hashB', 64, '0') => [$imageB, 'legacy-b.jpg']] as $urlHash => $expected) {
            $row = $adopted[$urlHash];
            $this->assertSame(Contract::IMAGE_STATE_DONE, $row['state']);
            $this->assertSame($expected[0], (int) $row['image_id'], 'усыновлён СУЩЕСТВОВАВШИЙ ДО прогона image_id');
            $this->assertContains($expected[0], $preExisting);
            $this->assertSame($expected[1], (string) $row['filename']);
        }
        $this->assertSame(
            Contract::IMAGE_STATE_DONE,
            $env->map->findOne(['entity_type' => 'product', 'external_id' => '1'])->image_state
        );
    }

    public function testOneBrokenContentHashSendsExactlyThatRowToDownload(): void
    {
        $root = $this->initGalleryRoot();
        $bytesA = 'client-bytes-A';
        $bytesB = 'client-bytes-B';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytesA);
        $this->putGalleryFile($root, 'legacy-b.jpg', $bytesB);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $imageB = $this->seedGalleryRow($env, $productId, 'legacy-b.jpg', 1);
        $before = $this->galleryWrites($env);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, hash('sha256', $bytesA)),
                // Хеш годной формы, но НЕ от байтов витрины: ровно одна строка обязана уйти в скачивание.
                $this->image('https://cdn/b.jpg', 'hashB', 2, str_repeat('b', 64)),
            ]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(['https://cdn/b.jpg'], $env->downloader->requested, 'скачивается РОВНО сломанная строка');
        $this->assertSame(1, $stats->imagesAdopted);
        // Разведение диагнозов: «хеш есть, байт у товара нет» ≠ «хеш не приехал». Слить их в один
        // счётчик значит сделать «32 не усыновилось» неотличимым от «32 приехали без хеша».
        $this->assertSame(1, $stats->imagesAdoptionMissed);
        $this->assertSame(0, $stats->imagesAdoptionNoHash, 'хеш приехал — это не отказ по контракту');
        $this->assertSame(1, $stats->imagesDownloaded);
        $this->assertSame($before['adds'] + 1, count($env->img->addCalls), 'скачанная картинка добавила ОДНУ строку');
        $this->assertSame($before['deletes'], count($env->img->deleteCalls), 'ничего не удалено');

        $durable = $this->durableByUrlHash($env);
        $this->assertSame($imageA, (int) $durable[str_pad('hashA', 64, '0')]['image_id'], 'целая строка усыновила свой файл');
        $this->assertNotSame($imageA, (int) $durable[str_pad('hashB', 64, '0')]['image_id']);
        $this->assertNotSame($imageB, (int) $durable[str_pad('hashB', 64, '0')]['image_id'], 'чужой файл товара не усыновлён');
        $this->assertArrayHasKey($imageB, $env->img->rows, 'несовпавший файл клиента остался на месте');
    }

    public function testPublicUrlBaseChangeKeepsGalleryAndDownloadsNothing(): void
    {
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($this->initGalleryRoot()));
        $shaA = hash('sha256', 'core-bytes-A');
        $shaB = hash('sha256', 'core-bytes-B');
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('http://host.docker.internal:9000/media/a.jpg', 'devHashA', 1, $shaA),
                $this->image('http://host.docker.internal:9000/media/b.jpg', 'devHashB', 2, $shaB),
            ]),
        ]);
        [, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(2, $stats1->imagesDownloaded, 'первый прогон честно скачал');
        $installedIds = array_keys($env->img->rows);
        $installedRows = $env->img->rows;
        $requestedAfterFirst = $env->downloader->requested;
        $writesAfterFirst = $this->galleryWrites($env);

        // Сменилась только БАЗА публичного адреса ядра ⇒ url_hash ВСЕХ строк другой, содержимое то же.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://platform.example/media/a.jpg', 'prodHashA', 1, $shaA),
                $this->image('https://platform.example/media/b.jpg', 'prodHashB', 2, $shaB),
            ]),
        ]);
        [$status, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame($writesAfterFirst, $this->galleryWrites($env), 'ноль удалений/добавлений/правок ok_images');
        $this->assertSame($requestedAfterFirst, $env->downloader->requested, 'ноль новых скачиваний');
        $this->assertSame(0, $stats2->imagesDownloaded);
        $this->assertSame(2, $stats2->imagesAdopted, 'установленные файлы перенесены на новые durable-строки');
        $this->assertSame($installedRows, $env->img->rows, 'файлы витрины не тронуты');

        $durable = $this->durableByUrlHash($env);
        $this->assertSame(
            [str_pad('prodHashA', 64, '0'), str_pad('prodHashB', 64, '0')],
            array_keys($durable),
            'durable-строки пересобраны на новые url_hash, старых не осталось'
        );
        $carried = [];
        foreach ($durable as $row) {
            $this->assertSame(Contract::IMAGE_STATE_DONE, $row['state']);
            $carried[] = (int) $row['image_id'];
        }
        sort($carried);
        $this->assertSame($installedIds, $carried, 'перенесены РОВНО те же строки галереи');
    }

    public function testIdenticalBytesOnTwoProductsAdoptTheirOwnGalleryRow(): void
    {
        $root = $this->initGalleryRoot();
        $shared = 'byte-identical-image';
        $this->putGalleryFile($root, 'first.jpg', $shared);
        $this->putGalleryFile($root, 'second.jpg', $shared);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productOne = $this->seedBoundProduct($env, '1', 'phone-one');
        $productTwo = $this->seedBoundProduct($env, '2', 'phone-two');
        $imageOne = $this->seedGalleryRow($env, $productOne, 'first.jpg', 0);
        $imageTwo = $this->seedGalleryRow($env, $productTwo, 'second.jpg', 0);
        $before = $this->galleryWrites($env);

        $sha = hash('sha256', $shared);
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone-one', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/one.jpg', 'hashOne', 1, $sha),
            ]),
            $this->productLine('2', 'phone-two', 'h-new', [$this->variant('v2', 'SKU-B', '10.00', 1)], [
                $this->image('https://cdn/two.jpg', 'hashTwo', 1, $sha),
            ]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame([], $env->downloader->requested);
        $this->assertSame($before, $this->galleryWrites($env));
        $this->assertSame(2, $stats->imagesAdopted);

        $durable = [];
        foreach ($env->csimg->rows as $row) {
            $durable[(string) $row['product_external_id']] = $row;
        }
        $this->assertSame($imageOne, (int) $durable['1']['image_id'], 'товар 1 усыновил СВОЮ строку');
        $this->assertSame($imageTwo, (int) $durable['2']['image_id'], 'товар 2 усыновил СВОЮ строку');
        $this->assertSame($productOne, (int) $env->img->rows[$imageOne]['product_id']);
        $this->assertSame($productTwo, (int) $env->img->rows[$imageTwo]['product_id']);
        // Ни одна durable-строка не указывает на image_id чужого товара.
        foreach ($env->csimg->rows as $row) {
            $galleryRow = $env->img->rows[(int) $row['image_id']];
            $this->assertSame(
                (int) $row['product_local_id'],
                (int) $galleryRow['product_id'],
                'durable-строка обязана указывать на картинку СВОЕГО товара'
            );
        }
    }

    /**
     * @dataProvider unusableContentHashes
     * @param string|null $sha256
     */
    public function testSnapshotRowWithoutUsableContentHashIsNeverAdopted($sha256, string $why): void
    {
        $root = $this->initGalleryRoot();
        $bytes = 'client-bytes-A';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytes);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $before = $this->galleryWrites($env);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, $sha256),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->imagesAdopted, $why);
        $this->assertSame(1, $stats->imagesAdoptionNoHash, $why);
        $this->assertSame(0, $stats->imagesAdoptionMissed, 'непригодный хеш — не «не нашли», а «нечем искать»');
        $this->assertSame(['https://cdn/a.jpg'], $env->downloader->requested, 'строка без обещания качается');
        $this->assertSame(1, $stats->imagesDownloaded);
        $row = array_values($env->csimg->rows)[0];
        $this->assertNotSame($imageA, (int) $row['image_id'], 'файл клиента НЕ усыновлён ни при каких условиях');
        $this->assertSame($before['adds'] + 1, count($env->img->addCalls));
    }

    /** @return array<string, array{0:string|null, 1:string}> */
    public function unusableContentHashes(): array
    {
        return [
            'ключа нет вовсе'   => [null, 'ядро не поручилось за байты ⇒ усыновлять нельзя, качать'],
            'верхний регистр'   => [strtoupper(hash('sha256', 'client-bytes-A')), 'вне контракта: ровно 64 hex в нижнем регистре'],
            'короче 64'         => [substr(hash('sha256', 'client-bytes-A'), 0, 63), 'обрезанный хеш не годен'],
            'пустая строка'     => ['', 'пусто НИКОГДА не значит «усыновить что угодно»'],
        ];
    }

    public function testFullyAdoptedProductKeepsClientChosenMainImage(): void
    {
        $root = $this->initGalleryRoot();
        $bytesA = 'client-bytes-A';
        $bytesB = 'client-bytes-B';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytesA);
        $this->putGalleryFile($root, 'legacy-b.jpg', $bytesB);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $imageB = $this->seedGalleryRow($env, $productId, 'legacy-b.jpg', 1);
        // Выбор клиента: главная НЕ на минимальной позиции и НЕ на минимальном sort.
        $env->prod->rows[$productId]['main_image_id'] = $imageB;

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, hash('sha256', $bytesA)),
                $this->image('https://cdn/b.jpg', 'hashB', 2, hash('sha256', $bytesB)),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(2, $stats->imagesAdopted, 'товар усыновлён ЦЕЛИКОМ');
        $this->assertSame(0, $stats->imagesDownloaded);
        $this->assertSame($imageB, (int) $env->prod->rows[$productId]['main_image_id'], 'выбор клиента сохранён');
        foreach ($env->prod->updateCalls as $call) {
            $this->assertArrayNotHasKey('main_image_id', $call[1], 'путь усыновления не пишет main_image_id вовсе');
        }
        $this->assertNotSame($imageA, (int) $env->prod->rows[$productId]['main_image_id']);
    }

    public function testMixedProductStillAssignsMainImageByMinimalSort(): void
    {
        $root = $this->initGalleryRoot();
        $bytesA = 'client-bytes-A';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytesA);
        $this->putGalleryFile($root, 'legacy-b.jpg', 'client-bytes-B');
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $imageB = $this->seedGalleryRow($env, $productId, 'legacy-b.jpg', 1);
        $env->prod->rows[$productId]['main_image_id'] = $imageB;

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, hash('sha256', $bytesA)),
                // Вторая строка не усыновляется (обещания нет) ⇒ товар СМЕШАННЫЙ: одна скачана.
                $this->image('https://cdn/b.jpg', 'hashB', 2),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(1, $stats->imagesAdopted);
        $this->assertSame(1, $stats->imagesDownloaded);
        // Зафиксированное поведение смешанного товара: пришла НОВАЯ картинка от ядра, порядок ядра
        // авторитетен ⇒ главная переезжает на done-строку с минимальным sort (та, что усыновлена).
        $mainUpdates = [];
        foreach ($env->prod->updateCalls as $call) {
            if (array_key_exists('main_image_id', $call[1])) {
                $mainUpdates[] = (int) $call[1]['main_image_id'];
            }
        }
        $this->assertSame([$imageA], $mainUpdates, 'ровно одна установка главной — на минимальный sort');
        $this->assertSame($imageA, (int) $env->prod->rows[$productId]['main_image_id']);
        $this->assertNotSame($imageB, (int) $env->prod->rows[$productId]['main_image_id']);
    }

    public function testAdoptionHonoursCancellationBetweenProductsAndResumes(): void
    {
        $root = $this->initGalleryRoot();
        $this->putGalleryFile($root, 'one.jpg', 'bytes-one');
        $this->putGalleryFile($root, 'two.jpg', 'bytes-two');
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productOne = $this->seedBoundProduct($env, '1', 'phone-one');
        $productTwo = $this->seedBoundProduct($env, '2', 'phone-two');
        $imageOne = $this->seedGalleryRow($env, $productOne, 'one.jpg', 0);
        $imageTwo = $this->seedGalleryRow($env, $productTwo, 'two.jpg', 0);
        $before = $this->galleryWrites($env);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone-one', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/one.jpg', 'hashOne', 1, hash('sha256', 'bytes-one')),
            ]),
            $this->productLine('2', 'phone-two', 'h-new', [$this->variant('v2', 'SKU-B', '10.00', 1)], [
                $this->image('https://cdn/two.jpg', 'hashTwo', 1, hash('sha256', 'bytes-two')),
            ]),
        ]);

        // Отмена срабатывает, как только усыновлена первая durable-строка (в фазе усыновления
        // durable-строки только обновляются: на первом подключении текстовая фаза их ДОБАВЛЯЕТ).
        $adopted = 0;
        $env->csimg->onUpdate = static function () use (&$adopted): void {
            $adopted++;
        };
        [$status1, $stats1] = $this->runApplyWithCancel($env, $this->productsManifest(), static function () use (&$adopted): bool {
            return $adopted >= 1;
        });

        $this->assertSame(Contract::STATUS_CANCELLED, $status1);
        $this->assertSame(1, $stats1->imagesAdopted, 'успело усыновиться ровно одно');
        $this->assertSame(0, $stats1->imagesDownloaded, 'отмена не даёт фазе картинок ничего скачать');
        $states = $this->durableStateByExternal($env);
        $this->assertSame([Contract::IMAGE_STATE_DONE, Contract::IMAGE_STATE_PENDING], array_values($states));

        // Resume: hash товаров не менялся (текст skip), фаза усыновления добивает остаток.
        $env->csimg->onUpdate = null;
        [$status2, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(2, $stats2->skipped, 'текстовая фаза действительно пропустила оба товара');
        $this->assertSame(1, $stats2->imagesAdopted, 'дожато ровно недоделанное');
        $this->assertSame(0, $stats2->imagesDownloaded);
        $this->assertSame([], $env->downloader->requested, 'за оба прогона ни одного скачивания');
        $this->assertSame($before, $this->galleryWrites($env));
        $durable = [];
        foreach ($env->csimg->rows as $row) {
            $durable[(string) $row['product_external_id']] = (int) $row['image_id'];
        }
        $this->assertSame(['1' => $imageOne, '2' => $imageTwo], $durable);
    }

    /**
     * Неподтверждённая durable-запись переноса. Направление fail-safe несущее: `false` от durable-слоя
     * НЕ даёт права удалить строку `ok_images` и файл клиента. Осиротевшая строка галереи видна в галерее и снимается РУКАМИ (свипа по строкам `ok_images` без durable-владельца в модуле нет: все удаления идут по известному `image_id`); следующий прогон её не размножает,
     * следующим прогоном, удалённый файл клиента — нет.
     */
    public function testUnconfirmedTransferKeepsClientFileInsteadOfDeletingIt(): void
    {
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($this->initGalleryRoot()));
        $sha = hash('sha256', 'core-bytes-A');
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('http://host.docker.internal:9000/media/a.jpg', 'devHashA', 1, $sha),
            ]),
        ]);
        [, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(1, $stats1->imagesDownloaded, 'первый прогон установил картинку');
        $installedId = (int) array_keys($env->img->rows)[0];

        // Перенос на новую durable-строку не подтверждается durable-слоем (Okay CRUD отдаёт false, и
        // ядро эту ошибку глотает) — ровно в тот момент, когда файл клиента уже мог бы быть удалён.
        $env->csimg->onUpdate = static function (int $id, array $patch) use ($env, $installedId): void {
            if (($patch['state'] ?? null) === Contract::IMAGE_STATE_DONE
                && (int) ($patch['image_id'] ?? 0) === $installedId) {
                $env->csimg->returnFalseOnUpdate = true;
            }
        };

        // Сменилась только база публичного адреса ⇒ старая строка «пропала», новая ждёт переноса.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://platform.example/media/a.jpg', 'prodHashA', 1, $sha),
            ]),
        ]);
        [$status, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status, 'неподтверждённый перенос не роняет прогон');
        $this->assertSame([], $env->img->deleteCalls, 'файл клиента НЕ удаляется при неподтверждённом переносе');
        $this->assertArrayHasKey($installedId, $env->img->rows, 'строка галереи клиента на месте');
        $this->assertSame(0, $stats2->imagesAdopted, 'неподтверждённый перенос не считается усыновлением');

        // Принятая цена fail-safe: строка галереи осталась без durable-владельца, а картинка приехала
        // скачиванием. Дубль виден в галерее и снимается руками, следующий прогон его НЕ размножает;
        // потеря файла клиента необратима. Поправка приёмки 2026-08-17: прежняя формулировка
        // «восстановим следующим прогоном» измеримо неверна — автоматического свипа нет.
        $this->assertSame(1, $stats2->imagesDownloaded);
        $durable = array_values($env->csimg->rows);
        $this->assertCount(1, $durable);
        $this->assertNotSame($installedId, (int) $durable[0]['image_id'], 'durable не претендует на неподтверждённую картинку');
        $this->assertCount(2, $env->img->rows, 'осиротевшая строка + скачанная замена: обратимый руками исход против необратимой потери файла');
    }

    /**
     * Неподтверждённая durable-запись усыновления: строка обязана остаться на скачивание и НЕ
     * притворяться усыновлённой, иначе отчёт врёт (усыновлено и скачано одновременно), а durable
     * при этом ни на что не указывает.
     */
    public function testUnconfirmedAdoptionLeavesRowForDownloadAndClaimsNothing(): void
    {
        $root = $this->initGalleryRoot();
        $bytes = 'client-bytes-A';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytes);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $env->csimg->onUpdate = static function (int $id, array $patch) use ($env, $imageA): void {
            if (($patch['state'] ?? null) === Contract::IMAGE_STATE_DONE
                && (int) ($patch['image_id'] ?? 0) === $imageA) {
                $env->csimg->returnFalseOnUpdate = true;
            }
        };

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, hash('sha256', $bytes)),
            ]),
        ]);
        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->imagesAdopted, 'неподтверждённая запись — не усыновление');
        $this->assertSame(1, $stats->imagesAdoptionMissed, 'исход назван, а не растворён');
        $this->assertSame(1, $stats->imagesDownloaded, 'строка осталась на скачивание');
        $this->assertSame([], $env->img->deleteCalls, 'файл клиента не тронут');
        $this->assertArrayHasKey($imageA, $env->img->rows);
        $row = array_values($env->csimg->rows)[0];
        $this->assertNotSame($imageA, (int) $row['image_id'], 'durable не претендует на картинку без подтверждённой записи');
    }

    public function testAlreadyOwnedGalleryRowIsNeverAdoptedTwice(): void
    {
        $root = $this->initGalleryRoot();
        $bytes = 'client-bytes-A';
        $this->putGalleryFile($root, 'legacy-a.jpg', $bytes);
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);
        $sha = hash('sha256', $bytes);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, $sha),
            ]),
        ]);
        [, $stats1] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(1, $stats1->imagesAdopted);

        // Ядро прислало ВТОРУЮ строку с тем же содержимым. Единственный файл товара уже принадлежит
        // первой durable-строке: два владельца на один image_id ⇒ цикл удаления снёс бы живую картинку.
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h2', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, $sha),
                $this->image('https://cdn/a-copy.jpg', 'hashCopy', 2, $sha),
            ]),
        ]);
        [, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats2->imagesAdopted, 'занятая картинка не усыновляется второй раз');
        $this->assertSame(1, $stats2->imagesAdoptionMissed);
        $this->assertSame(1, $stats2->imagesDownloaded, 'вторая строка честно скачана');
        $owners = [];
        foreach ($env->csimg->rows as $row) {
            $owners[] = (int) $row['image_id'];
        }
        $this->assertSame(count($owners), count(array_unique($owners)), 'ни один image_id не имеет двух владельцев');
        $this->assertContains($imageA, $owners);
    }

    public function testUnsafeGalleryFileIsNotAdoptedAndFallsBackToDownload(): void
    {
        $root = $this->initGalleryRoot();
        $bytes = 'client-bytes-A';
        $this->putGalleryFile($root, 'target.jpg', $bytes);
        // Симлинк на годные байты: общий набор проверок файла обязан отказать до сверки хеша.
        symlink($root . '/target.jpg', $root . '/legacy-a.jpg');
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root));
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $imageA = $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, hash('sha256', $bytes)),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->imagesAdopted, 'симлинк не усыновляется, даже если байты совпадают');
        $this->assertSame(1, $stats->imagesAdoptionMissed);
        $this->assertSame(1, $stats->imagesDownloaded);
        $row = array_values($env->csimg->rows)[0];
        $this->assertNotSame($imageA, (int) $row['image_id']);
    }

    public function testMissingDownloaderIsDistinguishableFromAdoption(): void
    {
        $root = $this->initGalleryRoot();
        $this->putGalleryFile($root, 'legacy-a.jpg', 'client-bytes-A');
        // Загрузчика нет: фаза картинок выходит сразу. «Ноль скачиваний» тут — подпись НЕВЫПОЛНЕННОЙ
        // фазы, и счётчики обязаны отличать её от усыновления.
        $env = $this->buildEnv(['UAH' => 7], null, $this->galleryContentAdopter($root), false);
        $productId = $this->seedBoundProduct($env, '1', 'phone');
        $this->seedGalleryRow($env, $productId, 'legacy-a.jpg', 0);

        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h-new', [$this->variant('v1', 'SKU-A', '10.00', 1)], [
                $this->image('https://cdn/a.jpg', 'hashA', 1, str_repeat('c', 64)),
            ]),
        ]);
        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(0, $stats->imagesDownloaded, 'скачиваний ноль — но по другой причине');
        $this->assertSame(0, $stats->imagesAdopted, 'и усыновлений тоже ноль: это НЕ успех');
        $this->assertSame(1, $stats->imagesAdoptionMissed);
        $this->assertSame(
            Contract::IMAGE_STATE_PENDING,
            (string) array_values($env->csimg->rows)[0]['state'],
            'строка осталась pending — подпись невыполненной фазы'
        );
    }

    public function testApplyReportExportsAdoptionCounters(): void
    {
        $stats = new ApplyStats();
        $stats->imagesAdopted = 3;
        $stats->imagesAdoptionNoHash = 2;
        $stats->imagesAdoptionMissed = 1;
        $stats->imagesDownloaded = 4;

        $payload = $stats->toArray();

        $this->assertSame(3, $payload['images_adopted']);
        $this->assertSame(2, $payload['images_adoption_no_hash']);
        $this->assertSame(1, $payload['images_adoption_missed']);
        $this->assertSame(4, $payload['images_downloaded']);
    }

    // ------------------------------------------------------------------ helpers

    /** Товар витрины, уже связанный картой (как после bind): применение пойдёт как update. */
    private function seedBoundProduct(object $env, string $externalId, string $slug): int
    {
        $localId = (int) $env->prod->add(['url' => $slug, 'name' => 'seeded ' . $externalId]);
        $env->map->add([
            'entity_type'  => 'product',
            'external_id'  => $externalId,
            'local_id'     => $localId,
            'applied_hash' => null,
            'image_state'  => null,
        ]);

        return $localId;
    }

    /** Строка галереи витрины, существующая ДО прогона (файл уже лежит на диске). */
    private function seedGalleryRow(object $env, int $productId, string $filename, int $position): int
    {
        return (int) $env->img->add([
            'product_id' => $productId,
            'filename'   => $filename,
            'position'   => $position,
        ]);
    }

    /** @return array<string, int> счётчики записей в ok_images (добавления/правки/удаления) */
    private function galleryWrites(object $env): array
    {
        return [
            'adds'    => count($env->img->addCalls),
            'updates' => count($env->img->updateCalls),
            'deletes' => count($env->img->deleteCalls),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function durableByUrlHash(object $env): array
    {
        $out = [];
        foreach ($env->csimg->rows as $row) {
            $out[(string) $row['url_hash']] = $row;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function durableStateByExternal(object $env): array
    {
        $out = [];
        foreach ($env->csimg->rows as $row) {
            $out[(string) $row['product_external_id']] = (string) $row['state'];
        }
        ksort($out);

        return $out;
    }
}
