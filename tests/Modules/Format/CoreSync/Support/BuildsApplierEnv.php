<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Core\Config;
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
use Okay\Modules\Format\CoreSync\Core\Apply\CategoryImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryContentAdopter;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryFileProbe;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;

require_once __DIR__ . '/ApplierStubs.php';
require_once __DIR__ . '/InMemoryCheckpointStore.php';

/**
 * Общий строитель окружения Applier для feature-тестов M3 (bind/price_stock/картинки): стаб-сущности
 * через EntityFactory-мок + реальный NdjsonGzReader + fake image downloader + tmp-staging .gz.
 */
trait BuildsApplierEnv
{
    /** @var string */
    protected $stagingDir;
    /** @var list<string> */
    protected $tmpDirs = [];

    protected function initStaging(): void
    {
        $this->stagingDir = sys_get_temp_dir() . '/coresync_m3_' . uniqid('', true);
        mkdir($this->stagingDir, 0775, true);
        $this->tmpDirs[] = $this->stagingDir;
    }

    protected function cleanupStaging(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * @param array<string, int> $currencyMap
     * @param CategoryImageDownloader|null $categoryDownloader null — категорийная очередь без загрузчика
     *        (сегодняшний режим всех потребителей трейта); стаб включает категорийную фазу картинок.
     * @return object env (map,cat,brand,feat,fv,prod,var,redir,img,csimg,cscatimg,downloader,categoryDownloader,applier)
     */
    protected function buildEnv(
        array $currencyMap = ['UAH' => 7],
        ?Languages $languages = null,
        ?GalleryContentAdopter $galleryContentAdopter = null,
        bool $withDownloader = true,
        ?CategoryImageDownloader $categoryDownloader = null
    ): object
    {
        $map = new MapEntityStub();
        $cat = new CategoriesEntityStub();
        $brand = new BrandsEntityStub();
        $feat = new FeaturesEntityStub();
        $fv = new FeaturesValuesEntityStub();
        $prod = new ProductsEntityStub();
        $var = new VariantsEntityStub();
        $redir = new RedirectsEntityStub();
        $img = new ImagesEntityStub();
        $csimg = new CoreSyncImagesEntityStub();
        $cscatimg = new CoreSyncCategoryImagesEntityStub();

        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static function (string $class) use ($map, $cat, $brand, $feat, $fv, $prod, $var, $redir, $img, $csimg, $cscatimg) {
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
                case CoreSyncCategoryImagesEntity::class: return $cscatimg;
                case Applier::REDIRECTS_ENTITY_CLASS: return $redir;
            }
            throw new \InvalidArgumentException('Unexpected entity: ' . $class);
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($currencyMap) {
            if ($key === 'coresync_settings') {
                return ['currency_map' => $currencyMap, 'lang_id' => 1];
            }
            if ($key === 'product_routes_template__default') {
                return 'products';
            }
            if ($key === 'category_routes_template__default') {
                return 'catalog';
            }

            return null;
        });

        $downloader = new FakeImageDownloader();
        $applier = new Applier(
            $factory,
            $settings,
            new NdjsonGzReader(),
            $languages,
            null,
            // null-загрузчик — та самая подпись НЕВЫПОЛНЕННОЙ фазы картинок (runImagesPhase выходит
            // сразу, строки остаются pending): именно от неё счётчики обязаны отличать усыновление.
            $withDownloader ? $downloader : null,
            null,
            $categoryDownloader,
            $galleryContentAdopter
        );

        return (object) compact('map', 'cat', 'brand', 'feat', 'fv', 'prod', 'var', 'redir', 'img', 'csimg', 'cscatimg', 'downloader', 'categoryDownloader', 'applier');
    }

    /**
     * Реальный каталог оригиналов галереи витрины: усыновление по содержимому обязано читать НАСТОЯЩИЕ
     * байты (иначе проба меряет мок, а не файл клиента).
     */
    protected function initGalleryRoot(): string
    {
        $root = sys_get_temp_dir() . '/coresync_gallery_' . uniqid('', true);
        mkdir($root, 0775, true);
        $this->tmpDirs[] = $root;

        return $root;
    }

    /** Положить файл галереи витрины и вернуть его имя (basename — как в `ok_images.filename`). */
    protected function putGalleryFile(string $root, string $filename, string $bytes): string
    {
        file_put_contents($root . '/' . $filename, $bytes);

        return $filename;
    }

    /**
     * Живой адоптер по содержимому над реальным каталогом: Config мокается только ради root_dir/
     * original_images_dir, все проверки файла идут настоящие (общий набор с подписанным планом).
     */
    protected function galleryContentAdopter(string $root): GalleryContentAdopter
    {
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            if ($key === 'root_dir') {
                return $root;
            }
            if ($key === 'original_images_dir') {
                return '/';
            }

            return null;
        });

        return new GalleryContentAdopter(new GalleryFileProbe($config));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{0:string, 1:ApplyStats}
     */
    protected function runApply(object $env, array $manifest, ?InMemoryCheckpointStore $checkpoints = null): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $manifest,
            $this->stagingDir,
            $checkpoints ?? new InMemoryCheckpointStore(),
            static function (): bool {
                return false;
            },
            $stats
        );

        return [$status, $stats];
    }

    /**
     * @param callable():bool $cancel
     * @return array{0:string, 1:ApplyStats}
     */
    protected function runApplyWithCancel(object $env, array $manifest, callable $cancel, ?InMemoryCheckpointStore $checkpoints = null): array
    {
        $stats = new ApplyStats();
        $status = $env->applier->apply(
            $manifest,
            $this->stagingDir,
            $checkpoints ?? new InMemoryCheckpointStore(),
            $cancel,
            $stats
        );

        return [$status, $stats];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function productsManifest(string $syncMode = 'full', array $override = []): array
    {
        return array_merge([
            'currency'      => 'UAH',
            'sync_mode'     => $syncMode,
            'absent_policy' => 'out_of_stock',
            'files'         => [['name' => 'products-0001.ndjson.gz']],
        ], $override);
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $images
     */
    protected function productLine(string $externalId, string $slug, string $hash, array $variants, array $images = []): string
    {
        return (string) json_encode([
            'external_id' => $externalId,
            'hash'        => str_pad($hash, 64, '0'),
            'data'        => [
                'name'              => 'P' . $externalId,
                'slug'              => $slug,
                'visible'           => true,
                'brand_external_id' => null,
                'categories'        => ['primary' => null, 'additional' => []],
                'description_html'  => '',
                'seo'               => ['title' => '', 'description' => '', 'keywords' => ''],
                'images'            => $images,
                'feature_values'    => [],
                'variants'          => $variants,
            ],
        ]);
    }

    /**
     * @param int|null $stock
     * @return array<string, mixed>
     */
    protected function variant(string $externalId, string $sku, string $amount, $stock): array
    {
        return [
            'external_id' => $externalId,
            'sku'         => $sku,
            'price'       => ['amount' => $amount, 'currency' => 'UAH'],
            'stock'       => $stock === null ? null : (int) $stock,
        ];
    }

    /**
     * `sha256` — НЕОБЯЗАТЕЛЬНЫЙ ключ снапшота (ядро не всегда может поручиться за байты); null не
     * добавляет ключ вовсе, как в реальной строке до этапа media-content-sha256.
     *
     * @return array<string, mixed>
     */
    protected function image(string $url, string $urlHash, int $sort, ?string $sha256 = null): array
    {
        $image = ['url' => $url, 'url_hash' => str_pad($urlHash, 64, '0'), 'sort' => $sort];
        if ($sha256 !== null) {
            $image['sha256'] = $sha256;
        }

        return $image;
    }

    /**
     * @param list<string> $lines
     */
    protected function gz(string $name, array $lines): void
    {
        $gz = gzopen($this->stagingDir . '/' . $name, 'wb9');
        foreach ($lines as $line) {
            gzwrite($gz, $line . "\n");
        }
        gzclose($gz);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
