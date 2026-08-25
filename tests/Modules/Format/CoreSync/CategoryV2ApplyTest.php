<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\CategoryV2Validator;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BrandsEntityStub;
use Tests\Modules\Format\CoreSync\Support\CategoriesEntityStub;
use Tests\Modules\Format\CoreSync\Support\CoreSyncImagesEntityStub;
use Tests\Modules\Format\CoreSync\Support\CoreSyncCategoryImagesEntityStub;
use Tests\Modules\Format\CoreSync\Support\FakeCategoryImageDownloader;
use Tests\Modules\Format\CoreSync\Support\FakeImageDownloader;
use Tests\Modules\Format\CoreSync\Support\FeaturesEntityStub;
use Tests\Modules\Format\CoreSync\Support\FeaturesValuesEntityStub;
use Tests\Modules\Format\CoreSync\Support\ImagesEntityStub;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;
use Tests\Modules\Format\CoreSync\Support\MapEntityStub;
use Tests\Modules\Format\CoreSync\Support\ProductsEntityStub;
use Tests\Modules\Format\CoreSync\Support\RedirectsEntityStub;
use Tests\Modules\Format\CoreSync\Support\VariantsEntityStub;

require_once __DIR__ . '/Support/ApplierStubs.php';
require_once __DIR__ . '/Support/InMemoryCheckpointStore.php';

class CategoryV2ApplyTest extends TestCase
{
    /** @var string */
    private $staging;

    protected function setUp(): void
    {
        $this->staging = sys_get_temp_dir() . '/coresync_category_v2_' . uniqid('', true);
        mkdir($this->staging, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->staging . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->staging);
    }

    public function testV2BindsByVerifiedSourceIdAndWritesSparseTranslationsByHrefLang(): void
    {
        $env = $this->env('grundfos');
        $env->cat->currentLangId = 1;
        $localId = (int) $env->cat->add([
            'external_id' => '77',
            'url' => 'legacy-slug',
            'parent_id' => 0,
            'name' => 'Старое RU',
            'annotation' => 'Старая аннотация',
            'description' => 'Старое описание RU',
        ]);
        $env->cat->currentLangId = 2;
        $env->cat->update($localId, ['name' => 'Старе UK', 'description' => 'Старий опис UK']);
        $env->cat->currentLangId = 3;
        $env->cat->addCalls = [];
        $env->cat->updateCalls = [];

        $this->gz([$this->row()]);
        [$status, $stats] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(0, $stats->errors);
        $this->assertCount(1, $env->cat->rows, 'source-id bind updates the existing row before slug fallback');
        $this->assertSame('900', $env->cat->rows[$localId]['coresync_external_id']);
        $this->assertSame('nasosy', $env->cat->rows[$localId]['url']);
        $this->assertSame('Насосы', $env->cat->languageRows[$localId][1]['name']);
        $this->assertSame('', $env->cat->languageRows[$localId][1]['annotation']);
        $this->assertSame('Старое описание RU', $env->cat->languageRows[$localId][1]['description'], 'omitted field is unchanged');
        $this->assertSame('Насоси', $env->cat->languageRows[$localId][2]['name']);
        $this->assertSame('Старий опис UK', $env->cat->languageRows[$localId][2]['description'], 'omitted field is unchanged');
        $this->assertSame(3, $env->cat->currentLangId, 'previous Okay language is restored');
        $this->assertSame($localId, $env->map->findOne(['entity_type' => 'category', 'external_id' => '900'])->local_id);
    }

    /** @dataProvider rejectedRows */
    public function testIdentityOrSharedSlugFailureMutatesNothing(array $row): void
    {
        $env = $this->env('grundfos');
        $before = $env->cat->rows;
        $this->gz([$row]);

        [$status, $stats] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->errors);
        $this->assertSame($before, $env->cat->rows);
        $this->assertSame([], $env->cat->addCalls);
        $this->assertSame([], $env->cat->updateCalls);
        $this->assertSame([], $env->map->rows);
        $this->assertSame([], $env->categoryImages->rows);
        $this->assertSame([], $env->categoryDownloader->requested);
    }

    public function testCategoryImageDescriptorIsDurableAndRetryIsIndependentOfMapHash(): void
    {
        $env = $this->env('grundfos');
        $row = $this->row();
        $row['data']['image'] = [
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
        ];
        $env->categoryDownloader->fail = true;
        $this->gz([$row]);

        [$status1, $stats1] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_FAILED, $status1, 'image failure must not be reported applied');
        $this->assertSame(1, $stats1->categoryImagesPending);
        $this->assertSame(1, $stats1->categoryImagesFailed);
        $durable = array_values($env->categoryImages->rows)[0];
        $this->assertSame(Contract::IMAGE_STATE_FAILED, $durable['state']);
        $this->assertSame('test_failure', $durable['error_code']);
        $this->assertNotFalse($env->map->findOne(['entity_type' => 'category', 'external_id' => '900']));

        // The text row is now a hash skip, but durable failed state must still retry.
        $env->categoryDownloader->fail = false;
        [$status2, $stats2] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(0, $stats2->categoryImagesFailed);
        $durable = array_values($env->categoryImages->rows)[0];
        $this->assertSame(Contract::IMAGE_STATE_DONE, $durable['state']);
        $this->assertSame($env->categoryDownloader->filename, $env->cat->rows[(int) $durable['category_local_id']]['image']);
        $this->assertCount(2, $env->categoryDownloader->requested);
    }

    public function testPendingOnlyEntrypointCatchesUpCategoryWithoutRedownloadingDoneProduct(): void
    {
        $env = $this->env('grundfos');
        $categoryId = (int) $env->cat->add([
            'external_id' => '900',
            'url' => 'nasosy',
            'parent_id' => 0,
            'name' => 'Насосы',
        ]);
        $env->csimg->add([
            'product_external_id' => 'product-1',
            'product_local_id' => 1,
            'url' => 'https://cdn.example/product.jpg',
            'url_hash' => str_repeat('a', 64),
            'sort' => 0,
            'state' => Contract::IMAGE_STATE_DONE,
            'attempts' => 1,
            'filename' => 'existing.jpg',
            'image_id' => 10,
            'content_sha256' => null,
        ]);
        $env->categoryImages->add([
            'category_external_id' => '900',
            'category_local_id' => $categoryId,
            'source_instance' => 'grundfos',
            'source_id' => '77',
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
            'state' => Contract::IMAGE_STATE_PENDING,
            'attempts' => 0,
            'filename' => null,
            'error_code' => null,
        ]);

        $stats = new ApplyStats();
        $status = $env->applier->applyPendingImages(static function (): bool {
            return false;
        }, $stats, 2);

        self::assertSame(Contract::STATUS_APPLIED, $status);
        self::assertSame([], $env->imageDownloader->requested, 'done product images are not downloaded again');
        self::assertCount(1, $env->categoryDownloader->requested);
        $categoryImage = array_values($env->categoryImages->rows)[0];
        self::assertSame(Contract::IMAGE_STATE_DONE, $categoryImage['state']);
        self::assertNull($categoryImage['error_code']);
        self::assertSame($env->categoryDownloader->filename, $env->cat->rows[$categoryId]['image']);
        self::assertSame(1, $stats->categoryImagesPending);
        self::assertSame(0, $stats->categoryImagesFailed);
    }

    public function testPendingOnlyEntrypointPropagatesCategoryCancellation(): void
    {
        $env = $this->env('grundfos');
        $categoryId = (int) $env->cat->add([
            'external_id' => '900',
            'url' => 'nasosy',
            'parent_id' => 0,
            'name' => 'Насосы',
        ]);
        $env->categoryImages->add([
            'category_external_id' => '900',
            'category_local_id' => $categoryId,
            'source_instance' => 'grundfos',
            'source_id' => '77',
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
            'state' => Contract::IMAGE_STATE_PENDING,
            'attempts' => 0,
            'filename' => null,
            'error_code' => null,
        ]);

        $stats = new ApplyStats();
        $status = $env->applier->applyPendingImages(static function (): bool {
            return true;
        }, $stats, 2);

        self::assertSame(Contract::STATUS_CANCELLED, $status);
        self::assertSame([], $env->categoryDownloader->requested);
        self::assertSame(Contract::IMAGE_STATE_PENDING, array_values($env->categoryImages->rows)[0]['state']);
    }

    public function testDesiredImageIsDurableBeforeCategoryMapHashCheckpoint(): void
    {
        $env = $this->env('grundfos');
        $row = $this->row();
        $row['data']['image'] = [
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
        ];
        $this->gz([$row]);

        $this->applySnapshot($env);

        $this->assertContains('category_image_add', $env->events);
        $this->assertContains('map_add', $env->events);
        $this->assertLessThan(
            array_search('map_add', $env->events, true),
            array_search('category_image_add', $env->events, true),
            'durable pending descriptor precedes the applied_hash checkpoint'
        );
    }

    public function testImageNullDoesNotRemoveOrRedownloadExistingCategoryImage(): void
    {
        $env = $this->env('grundfos');
        $row = $this->row();
        $row['data']['image'] = [
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
        ];
        $this->gz([$row]);
        $this->applySnapshot($env);
        $durableBefore = $env->categoryImages->rows;
        $imageBefore = array_values($env->cat->rows)[0]['image'];
        $requestsBefore = count($env->categoryDownloader->requested);

        $row['hash'] = str_repeat('c', 64);
        $row['data']['image'] = null;
        $this->gz([$row]);
        [$status] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame($durableBefore, $env->categoryImages->rows);
        $this->assertSame($imageBefore, array_values($env->cat->rows)[0]['image']);
        $this->assertSame($requestsBefore, count($env->categoryDownloader->requested));
        $this->assertSame([], $env->categoryDownloader->deleted);
    }

    public function testFailedReplacementKeepsOldImageUntilVerifiedReplacementSucceeds(): void
    {
        $env = $this->env('grundfos');
        $row = $this->row();
        $row['data']['image'] = [
            'url' => 'https://cdn.example/old.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
        ];
        $env->categoryDownloader->filename = 'coresync_category_aaaaaaaaaaaaaaaa_11111111111111111111.jpg';
        $this->gz([$row]);
        $this->applySnapshot($env);
        $localId = (int) array_values($env->categoryImages->rows)[0]['category_local_id'];
        $oldFilename = $env->cat->rows[$localId]['image'];

        $row['hash'] = str_repeat('c', 64);
        $row['data']['image'] = [
            'url' => 'https://cdn.example/new.jpg',
            'sha256' => str_repeat('d', 64),
            'mime' => 'image/jpeg',
            'bytes' => 456,
        ];
        $env->categoryDownloader->filename = 'coresync_category_aaaaaaaaaaaaaaaa_22222222222222222222.jpg';
        $env->categoryDownloader->fail = true;
        $this->gz([$row]);

        [$failedStatus] = $this->applySnapshot($env);
        $this->assertSame(Contract::STATUS_FAILED, $failedStatus);
        $this->assertSame($oldFilename, $env->cat->rows[$localId]['image']);
        $this->assertSame($oldFilename, array_values($env->categoryImages->rows)[0]['filename']);
        $this->assertSame([], $env->categoryDownloader->deleted);

        $env->categoryDownloader->fail = false;
        [$successStatus] = $this->applySnapshot($env);
        $this->assertSame(Contract::STATUS_APPLIED, $successStatus);
        $this->assertSame($env->categoryDownloader->filename, $env->cat->rows[$localId]['image']);
        $this->assertSame([$oldFilename], $env->categoryDownloader->deleted);
    }

    public function testMissingCategoryDownloaderCannotProduceFalseSuccess(): void
    {
        $env = $this->env('grundfos', false);
        $row = $this->row();
        $row['data']['image'] = [
            'url' => 'https://cdn.example/category.jpg',
            'sha256' => str_repeat('b', 64),
            'mime' => 'image/jpeg',
            'bytes' => 123,
        ];
        $this->gz([$row]);

        [$status, $stats] = $this->applySnapshot($env);

        $this->assertSame(Contract::STATUS_FAILED, $status);
        $this->assertSame(1, $stats->categoryImagesFailed);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function rejectedRows(): array
    {
        $wrongInstance = $this->row();
        $wrongInstance['data']['source_identity']['instance'] = 'other';
        $conflictingSlug = $this->row();
        $conflictingSlug['data']['translations'][1]['slug'] = 'nasosi';
        $translationsObject = $this->row();
        $translationsObject['data']['translations'] = [
            'ru' => ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy'],
        ];
        $translationsSparse = $this->row();
        $translationsSparse['data']['translations'] = [
            1 => ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy'],
        ];
        $numericJsonObject = $this->row();
        $numericJsonObject['data']['translations'] = (object) [
            '0' => ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy'],
        ];

        return [
            'wrong instance' => [$wrongInstance],
            'conflicting slug' => [$conflictingSlug],
            'translation object' => [$translationsObject],
            'sparse translation list' => [$translationsSparse],
            'numeric sequential JSON object' => [$numericJsonObject],
        ];
    }

    /** @return object */
    private function env(string $sourceInstance, bool $withCategoryDownloader = true): object
    {
        $map = new MapEntityStub();
        $cat = new CategoriesEntityStub();
        $brand = new BrandsEntityStub();
        $feat = new FeaturesEntityStub();
        $fv = new FeaturesValuesEntityStub();
        $prod = new ProductsEntityStub();
        $var = new VariantsEntityStub();
        $img = new ImagesEntityStub();
        $csimg = new CoreSyncImagesEntityStub();
        $categoryImages = new CoreSyncCategoryImagesEntityStub();
        $imageDownloader = new FakeImageDownloader();
        $categoryDownloader = $withCategoryDownloader ? new FakeCategoryImageDownloader() : null;
        $events = [];
        $record = static function (string $event) use (&$events): void { $events[] = $event; };
        $map->onWrite = $record;
        $categoryImages->onWrite = $record;
        $redir = new RedirectsEntityStub();

        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static function (string $class) use ($map, $cat, $brand, $feat, $fv, $prod, $var, $img, $csimg, $categoryImages, $redir) {
            switch ($class) {
                case CoreSyncMapEntity::class: return $map;
                case CategoriesEntity::class: return $cat;
                case BrandsEntity::class: return $brand;
                case FeaturesEntity::class: return $feat;
                case FeaturesValuesEntity::class: return $fv;
                case ProductsEntity::class: return $prod;
                case VariantsEntity::class: return $var;
                case ImagesEntity::class: return $img;
                case CoreSyncImagesEntity::class: return $csimg;
                case CoreSyncCategoryImagesEntity::class: return $categoryImages;
                case Applier::REDIRECTS_ENTITY_CLASS: return $redir;
            }
            throw new \InvalidArgumentException('Unexpected entity: ' . $class);
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($sourceInstance) {
            return $key === Contract::SETTINGS_KEY
                ? ['currency_map' => ['UAH' => 7], 'lang_id' => 3, 'source_instance' => $sourceInstance]
                : null;
        });

        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn([
            1 => (object) ['id' => 1, 'href_lang' => 'ru'],
            2 => (object) ['id' => 2, 'href_lang' => 'uk'],
            3 => (object) ['id' => 3, 'href_lang' => 'en'],
        ]);
        $languages->method('getLangId')->willReturnCallback(static function () use ($cat) {
            return $cat->currentLangId;
        });
        $languages->method('setLangId')->willReturnCallback(static function ($id) use ($cat): void {
            $cat->currentLangId = (int) $id;
        });

        $applier = new Applier(
            $factory,
            $settings,
            new NdjsonGzReader(),
            $languages,
            null,
            $imageDownloader,
            new CategoryV2Validator(),
            $categoryDownloader
        );

        $environment = (object) compact('map', 'cat', 'csimg', 'imageDownloader', 'categoryImages', 'categoryDownloader', 'applier');
        $environment->events = &$events;

        return $environment;
    }

    /** @return array{string, ApplyStats} */
    private function applySnapshot(object $env): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            [
                'schema_version' => '2.0.0',
                'currency' => 'UAH',
                'sync_mode' => 'full',
                'absent_policy' => 'out_of_stock',
                'files' => [['name' => 'categories.ndjson.gz']],
            ],
            $this->staging,
            new InMemoryCheckpointStore(),
            static function (): bool { return false; },
            $stats,
            2,
            'grundfos'
        );

        return [$status, $stats];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function gz(array $rows): void
    {
        $gz = gzopen($this->staging . '/categories.ndjson.gz', 'wb9');
        foreach ($rows as $row) {
            gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
        }
        gzclose($gz);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'external_id' => '900',
            'hash' => str_repeat('a', 64),
            'data' => [
                'parent_external_id' => null,
                'position' => 7,
                'is_active' => true,
                'source_identity' => ['namespace' => 'okaysat', 'instance' => 'grundfos', 'entity' => 'category', 'id' => '77'],
                'translations' => [
                    ['language' => 'ru', 'name' => 'Насосы', 'slug' => 'nasosy', 'annotation_html' => ''],
                    ['language' => 'uk', 'name' => 'Насоси', 'slug' => 'nasosy'],
                ],
                'image' => null,
            ],
        ];
    }
}
