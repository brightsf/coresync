<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Settings;
use Okay\Core\Translit;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CurrencyNotMappedException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;
use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Psr\Log\LoggerInterface;

/**
 * Применение сверенного снапшота в БД витрины Okay. Три режима, выбор в apply():
 *  - bind: карта пуста (0 строк product/variant) И каталог непуст → связывание по SKU варианта,
 *    каталог НЕ пишется (границы владения фиксируются, значения — нет);
 *  - price_stock: трогает ТОЛЬКО price/stock связанных вариантов (variant-карта); ничего не создаёт;
 *  - full (дефолт): фазы categories → brands → features → products(+варианты, variant-карта) →
 *    redirects → absent → картинки (догоняющая фаза). Идемпотентность через карту (MapGateway).
 *
 * url диктует ядро (пишется ЯВНО + пост-проверка). Валюта без маппинга → fail-closed ДО применения.
 * Класс НЕ final и резолвит сущности через EntityFactory — тесты подставляют стаб-сущности.
 */
class Applier
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var Settings */
    private $settings;
    /** @var NdjsonGzReader */
    private $reader;
    /** @var Languages|null */
    private $languages;
    /** @var LoggerInterface|null */
    private $logger;
    /** @var ImageDownloader|null догоняющая фаза картинок (null → фаза пропускается, rows остаются pending) */
    private $imageDownloader;

    // Резолвятся в boot() (на прогон).
    /** @var MapGateway */
    private $map;
    /** @var CategoriesEntity */
    private $categoriesEntity;
    /** @var BrandsEntity */
    private $brandsEntity;
    /** @var FeaturesEntity */
    private $featuresEntity;
    /** @var FeaturesValuesEntity */
    private $featuresValuesEntity;
    /** @var ProductsEntity */
    private $productsEntity;
    /** @var VariantsEntity */
    private $variantsEntity;
    /** @var ImagesEntity */
    private $imagesEntity;
    /** @var CoreSyncImagesEntity durable-список картинок (вне staging) */
    private $coresyncImagesEntity;
    /** @var mixed RedirectsEntity|null (модуль Format/Redirects может быть не установлен) */
    private $redirectsEntity;

    /** @var array<string, int> currency_map: код валюты → currency_id */
    private $currencyMap = [];
    /** @var int currency_id валюты манифеста (после currency-гейта) */
    private $manifestCurrencyId = 0;

    const REDIRECTS_ENTITY_CLASS = 'Okay\\Modules\\Format\\Redirects\\Entities\\RedirectsEntity';

    public function __construct(
        EntityFactory $entityFactory,
        Settings $settings,
        NdjsonGzReader $reader,
        ?Languages $languages = null,
        ?LoggerInterface $logger = null,
        ?ImageDownloader $imageDownloader = null
    ) {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->reader = $reader;
        $this->languages = $languages;
        $this->logger = $logger;
        $this->imageDownloader = $imageDownloader;
    }

    /**
     * Применить снапшот. Возвращает терминальный статус job'а.
     *
     * @param array<string, mixed> $manifest
     * @param callable():bool      $isCancelled кооперативная отмена между файлами/товарами
     * @return string Contract::STATUS_APPLIED | STATUS_HELD | STATUS_BOUND | STATUS_CANCELLED | STATUS_FAILED
     */
    public function apply(
        array $manifest,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        ApplyStats $stats
    ): string {
        $this->boot();

        // --- Bind-фаза: карта пуста (product+variant) И каталог непуст → связывание, каталог не пишется ---
        if ($this->shouldBind()) {
            return $this->runBind($manifest, $stagingDir, $isCancelled, $stats);
        }

        $mode = (string) ($manifest['sync_mode'] ?? Contract::SYNC_MODE_FULL);

        // --- Currency-гейт: fail-closed ВСЕГО прогона ДО применения (full и price_stock пишут цену) ---
        try {
            $this->resolveCurrency($manifest);
        } catch (CurrencyNotMappedException $e) {
            $this->error('CoreSync apply: ' . $e->getMessage());

            return Contract::STATUS_FAILED;
        }

        if ($mode === Contract::SYNC_MODE_PRICE_STOCK) {
            return $this->applyPriceStock($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
        }

        return $this->applyFull($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
    }

    // ================================================================ full

    /**
     * @param array<string, mixed> $manifest
     */
    private function applyFull(
        array $manifest,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        ApplyStats $stats
    ): string {
        $seenProducts = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($isCancelled()) {
                $this->info('CoreSync apply: отмена между файлами');

                return Contract::STATUS_CANCELLED;
            }

            $name = $file['name'];
            $role = $file['role'];

            if ($checkpoints->getStatus($name) === Contract::FILE_APPLIED) {
                continue;
            }

            $path = rtrim($stagingDir, '/') . '/' . $name;
            if (!is_file($path)) {
                $this->warning('CoreSync apply: файл отсутствует в staging: ' . $name);
                continue;
            }

            $errorsBefore = $stats->errors;
            try {
                $rows = $this->applyFile($role, $path, $stats, $seenProducts);
            } catch (NdjsonReadException $e) {
                $this->error('CoreSync apply: ' . $e->getMessage());

                return Contract::STATUS_FAILED;
            }

            $phaseErrors = $stats->errors - $errorsBefore;
            if ($rows > 0 && ($phaseErrors / $rows) > Contract::PHASE_MAX_ERROR_RATIO) {
                $this->error(sprintf(
                    'CoreSync apply: фаза %s — ошибок %d из %d (>%d%%), job failed',
                    $role,
                    $phaseErrors,
                    $rows,
                    (int) (Contract::PHASE_MAX_ERROR_RATIO * 100)
                ));

                return Contract::STATUS_FAILED;
            }

            $checkpoints->setStatus($name, Contract::FILE_APPLIED);
        }

        // --- absent-фаза (только записи карты, отсутствующие в снапшоте) ---
        if ($isCancelled()) {
            return Contract::STATUS_CANCELLED;
        }
        $held = $this->applyAbsent($manifest, $stats, $seenProducts);

        // --- картинки — догоняющая фаза после текста (витрина уже актуальна по ценам/остаткам) ---
        if ($this->runImagesPhase($isCancelled, $stats) === Contract::STATUS_CANCELLED) {
            return Contract::STATUS_CANCELLED;
        }

        return $held ? Contract::STATUS_HELD : Contract::STATUS_APPLIED;
    }

    // ================================================================ bind

    private function shouldBind(): bool
    {
        $mapEmpty = ($this->map->count(Contract::ENTITY_PRODUCT) + $this->map->count(Contract::ENTITY_VARIANT)) === 0;
        if (!$mapEmpty) {
            return false;
        }

        return (int) $this->productsEntity->count() > 0;
    }

    /**
     * Связывание строк снапшота с записями Okay по SKU варианта (товар выводится из связанного
     * варианта). Заполняет карту (product+variant, applied_hash=NULL); КАТАЛОГ НЕ ПИШЕТСЯ.
     *
     * @param array<string, mixed> $manifest
     */
    private function runBind(array $manifest, string $stagingDir, callable $isCancelled, ApplyStats $stats): string
    {
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($file['role'] !== Contract::ENTITY_PRODUCT) {
                continue; // bind матчит только товары/варианты; словари/редиректы не трогает
            }
            if ($isCancelled()) {
                return Contract::STATUS_CANCELLED;
            }
            $path = rtrim($stagingDir, '/') . '/' . $file['name'];
            if (!is_file($path)) {
                $this->warning('CoreSync bind: файл отсутствует в staging: ' . $file['name']);
                continue;
            }
            try {
                $this->reader->each($path, function (array $line) use ($stats): void {
                    $this->bindProductLine($line, $stats);
                });
            } catch (NdjsonReadException $e) {
                $this->error('CoreSync bind: ' . $e->getMessage());

                return Contract::STATUS_FAILED;
            }
        }

        return Contract::STATUS_BOUND;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function bindProductLine(array $line, ApplyStats $stats): void
    {
        $productExternal = (string) $line['external_id'];
        $data = (array) $line['data'];
        $variants = (array) ($data['variants'] ?? []);

        $matched = [];        // variantExternal => localVariantId
        $localProductIds = []; // set of local product ids among matched variants
        foreach ($variants as $variant) {
            $variant = (array) $variant;
            $variantExternal = (string) ($variant['external_id'] ?? '');
            $sku = (string) ($variant['sku'] ?? '');
            if ($variantExternal === '' || $sku === '') {
                continue;
            }

            $rows = $this->variantsEntity->find(['sku' => $sku]); // точное, регистрозависимое
            $count = count($rows);
            if ($count === 0) {
                $stats->unmatched++;
                $this->bindSample($stats, $sku);
                continue;
            }
            if ($count > 1) {
                $stats->conflicts++; // дубль SKU в Okay
                $this->bindSample($stats, $sku . ' (дубль SKU)');
                continue;
            }
            $row = reset($rows);
            $matched[$variantExternal] = (int) $row->id;
            $localProductIds[(int) $row->product_id] = true;
        }

        if (empty($matched)) {
            return; // нечего связывать (unmatched/conflicts уже посчитаны)
        }
        if (count($localProductIds) > 1) {
            // Варианты товара разъехались по разным local-товарам → конфликт, не связываем.
            $stats->conflicts += count($matched);
            $this->bindSample($stats, $productExternal . ' (варианты разъехались)');

            return;
        }

        $localProductId = (int) array_key_first($localProductIds);
        $this->map->recordBind(Contract::ENTITY_PRODUCT, $productExternal, $localProductId);
        $stats->bound++;
        foreach ($matched as $variantExternal => $localVariantId) {
            $this->map->recordBind(Contract::ENTITY_VARIANT, (string) $variantExternal, $localVariantId);
            $stats->bound++;
        }
    }

    private function bindSample(ApplyStats $stats, string $sample): void
    {
        if (count($stats->conflictSamples) < Contract::BIND_CONFLICT_SAMPLE_MAX) {
            $stats->conflictSamples[] = $sample;
        }
    }

    // ================================================================ price_stock

    /**
     * Режим «Обновление цен/наличия»: только products-чанки; для связанных вариантов обновляет
     * ТОЛЬКО price/stock. Ничего не создаёт (счётчики skipped_new_*). Пропавший связанный вариант →
     * stock=0 (порог >20% связанных товаров → обнуление пропущено, held).
     *
     * @param array<string, mixed> $manifest
     */
    private function applyPriceStock(
        array $manifest,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        ApplyStats $stats
    ): string {
        $seenProducts = [];
        $seenVariants = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($file['role'] !== Contract::ENTITY_PRODUCT) {
                continue; // categories/brands/features/redirects скачаны, но НЕ применяются
            }
            if ($isCancelled()) {
                return Contract::STATUS_CANCELLED;
            }
            $name = $file['name'];
            if ($checkpoints->getStatus($name) === Contract::FILE_APPLIED) {
                continue;
            }
            $path = rtrim($stagingDir, '/') . '/' . $name;
            if (!is_file($path)) {
                $this->warning('CoreSync price_stock: файл отсутствует в staging: ' . $name);
                continue;
            }
            try {
                $this->reader->each($path, function (array $line) use ($stats, &$seenProducts, &$seenVariants): void {
                    $this->priceStockProductLine($line, $stats, $seenProducts, $seenVariants);
                });
            } catch (NdjsonReadException $e) {
                $this->error('CoreSync price_stock: ' . $e->getMessage());

                return Contract::STATUS_FAILED;
            }
            $checkpoints->setStatus($name, Contract::FILE_APPLIED);
        }

        if ($isCancelled()) {
            return Contract::STATUS_CANCELLED;
        }

        return $this->priceStockZeroVanished($stats, $seenProducts, $seenVariants)
            ? Contract::STATUS_HELD
            : Contract::STATUS_APPLIED;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, string>   $seenProducts by-ref
     * @param array<int, string>   $seenVariants by-ref
     */
    private function priceStockProductLine(array $line, ApplyStats $stats, array &$seenProducts, array &$seenVariants): void
    {
        $productExternal = (string) $line['external_id'];
        $data = (array) $line['data'];

        $productRow = $this->map->find(Contract::ENTITY_PRODUCT, $productExternal);
        if ($productRow === null) {
            $stats->skippedNewProducts++; // новый товар — не создаём

            return;
        }
        $seenProducts[] = $productExternal;

        foreach ((array) ($data['variants'] ?? []) as $variant) {
            $variant = (array) $variant;
            $variantExternal = (string) ($variant['external_id'] ?? '');
            if ($variantExternal === '') {
                continue;
            }

            $variantRow = $this->map->find(Contract::ENTITY_VARIANT, $variantExternal);
            if ($variantRow === null) {
                $stats->skippedNewVariants++; // новый вариант существующего товара — не создаём

                continue;
            }
            $seenVariants[] = $variantExternal;

            $hash = $this->variantHash($variant);
            if ((string) $variantRow->applied_hash === $hash) {
                $stats->skipped++; // цена/сток не изменились — идемпотентный skip

                continue;
            }

            $price = (array) ($variant['price'] ?? []);
            $variantCurrency = (string) ($price['currency'] ?? '');
            $currencyId = $this->currencyMap[$variantCurrency] ?? $this->manifestCurrencyId;
            // ТОЛЬКО price/stock/currency — контент/имя/категории/картинки НЕ трогаем.
            $this->variantsEntity->update((int) $variantRow->local_id, [
                'price'       => (string) ($price['amount'] ?? '0'),
                'stock'       => (int) ($variant['stock'] ?? 0), // явный int (0=0, не NULL=∞)
                'currency_id' => (int) $currencyId,
            ]);
            $this->map->setHash($variantRow, $hash);
            $stats->updated++;
        }
    }

    /**
     * Пропавшие связанные варианты → stock=0. Порог: absent-товаров >20% связанных → обнуление
     * пропущено (held), цены/стоки присутствующих применены.
     *
     * @param array<int, string> $seenProducts
     * @param array<int, string> $seenVariants
     * @return bool held
     */
    private function priceStockZeroVanished(ApplyStats $stats, array $seenProducts, array $seenVariants): bool
    {
        $mappedProducts = $this->map->allLocalIds(Contract::ENTITY_PRODUCT);
        if (empty($mappedProducts)) {
            return false;
        }

        $seenP = array_fill_keys($seenProducts, true);
        $absentProducts = 0;
        foreach ($mappedProducts as $externalId => $localId) {
            if (!isset($seenP[$externalId])) {
                $absentProducts++;
            }
        }
        $stats->absentCount = $absentProducts;
        if ($absentProducts > 0 && ($absentProducts / count($mappedProducts)) > Contract::ABSENT_MAX_RATIO) {
            $this->warning(sprintf(
                'CoreSync price_stock: absent %d из %d (>%d%%) — обнуление стока пропущено (held)',
                $absentProducts,
                count($mappedProducts),
                (int) (Contract::ABSENT_MAX_RATIO * 100)
            ));

            return true;
        }

        $seenV = array_fill_keys($seenVariants, true);
        foreach ($this->map->allLocalIds(Contract::ENTITY_VARIANT) as $externalId => $localVariantId) {
            if (!isset($seenV[$externalId])) {
                $this->variantsEntity->update((int) $localVariantId, ['stock' => 0]); // явный 0; visible не трогаем
                $stats->stockZeroed++;
                $stats->deactivated++;
            }
        }

        return false;
    }

    // ================================================================ boot / currency

    private function boot(): void
    {
        $this->map = new MapGateway($this->entityFactory->get(CoreSyncMapEntity::class));
        $this->categoriesEntity = $this->entityFactory->get(CategoriesEntity::class);
        $this->brandsEntity = $this->entityFactory->get(BrandsEntity::class);
        $this->featuresEntity = $this->entityFactory->get(FeaturesEntity::class);
        $this->featuresValuesEntity = $this->entityFactory->get(FeaturesValuesEntity::class);
        $this->productsEntity = $this->entityFactory->get(ProductsEntity::class);
        $this->variantsEntity = $this->entityFactory->get(VariantsEntity::class);
        $this->imagesEntity = $this->entityFactory->get(ImagesEntity::class);
        $this->coresyncImagesEntity = $this->entityFactory->get(CoreSyncImagesEntity::class);

        $this->redirectsEntity = null;
        if (class_exists(self::REDIRECTS_ENTITY_CLASS)) {
            $this->redirectsEntity = $this->entityFactory->get(self::REDIRECTS_ENTITY_CLASS);
        }

        $cfg = $this->config();
        if ($this->languages !== null && !empty($cfg['lang_id'])) {
            $this->languages->setLangId((int) $cfg['lang_id']);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @throws CurrencyNotMappedException
     */
    private function resolveCurrency(array $manifest): void
    {
        $cfg = $this->config();
        $this->currencyMap = [];
        foreach ((array) ($cfg['currency_map'] ?? []) as $code => $currencyId) {
            $this->currencyMap[(string) $code] = (int) $currencyId;
        }

        $manifestCurrency = (string) ($manifest['currency'] ?? '');
        if ($manifestCurrency === '' || !isset($this->currencyMap[$manifestCurrency])) {
            throw new CurrencyNotMappedException(sprintf(
                'валюта манифеста "%s" не сопоставлена локальному currency_id (настройка currency_map)',
                $manifestCurrency
            ));
        }
        $this->manifestCurrencyId = $this->currencyMap[$manifestCurrency];
    }

    /**
     * @param array<int, array<string, mixed>> $seenProducts собранные external_id товаров снапшота (by-ref)
     * @return int число валидных строк файла (для порога ошибок фазы)
     */
    private function applyFile(string $role, string $path, ApplyStats $stats, array &$seenProducts): int
    {
        switch ($role) {
            case Contract::ENTITY_CATEGORY:
                return $this->applyCategoriesFile($path, $stats);
            case Contract::ENTITY_BRAND:
                return $this->applyBrandsFile($path, $stats);
            case Contract::ENTITY_FEATURE:
                return $this->applyFeaturesFile($path, $stats);
            case Contract::ENTITY_PRODUCT:
                return $this->applyProductsFile($path, $stats, $seenProducts);
            case Contract::ENTITY_REDIRECT:
                return $this->applyRedirectsFile($path, $stats);
        }

        return 0;
    }

    // ---------------------------------------------------------------- categories

    private function applyCategoriesFile(string $path, ApplyStats $stats): int
    {
        $read = $this->reader->readAll($path);
        $stats->errors += $read['stats']['broken'];

        $queue = $read['lines'];
        $progress = true;
        while (!empty($queue) && $progress) {
            $progress = false;
            $deferred = [];
            foreach ($queue as $line) {
                if ($this->applyCategoryLine($line, $stats) === 'defer') {
                    $deferred[] = $line;
                } else {
                    $progress = true;
                }
            }
            $queue = $deferred;
        }
        foreach ($queue as $line) {
            $this->warning('CoreSync apply: категория ' . ($line['external_id'] ?? '?') . ' — родитель не применён');
            $stats->errors++;
        }

        return $read['stats']['valid'];
    }

    /**
     * @param array<string, mixed> $line
     * @return string 'done' | 'defer'
     */
    private function applyCategoryLine(array $line, ApplyStats $stats): string
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_CATEGORY, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            $stats->skipped++;

            return 'done';
        }

        $parentId = 0;
        $parentExternal = $data['parent_external_id'] ?? null;
        if ($parentExternal !== null) {
            $resolved = $this->map->localId(Contract::ENTITY_CATEGORY, (string) $parentExternal);
            if ($resolved === null) {
                return 'defer';
            }
            $parentId = $resolved;
        }

        $slug = (string) ($data['slug'] ?? '');
        $imageState = !empty($data['image_url']) ? Contract::IMAGE_STATE_PENDING : null;
        $fields = [
            'url'              => $slug,
            'parent_id'        => $parentId,
            'position'         => (int) ($data['position'] ?? 0),
            'visible'          => !empty($data['is_active']) ? 1 : 0,
            'external_id'      => $externalId,
            'name'             => (string) ($data['name'] ?? ''),
            'annotation'       => (string) ($data['annotation_html'] ?? ''),
            'description'      => (string) ($data['description_html'] ?? ''),
            'meta_title'       => (string) ($data['seo_title'] ?? ''),
            'meta_keywords'    => (string) ($data['seo_keywords'] ?? ''),
            'meta_description' => (string) ($data['seo_description'] ?? ''),
        ];

        if ($decision === Contract::MAP_CREATE) {
            $localId = (int) $this->categoriesEntity->add($fields);
            if (!$this->urlMatches($this->categoriesEntity, $localId, $slug)) {
                $this->slugMutationError('category', $externalId, $slug, $stats);

                return 'done';
            }
            $this->map->recordCreate(Contract::ENTITY_CATEGORY, $externalId, $localId, $hash, $imageState);
            $stats->upserted++;
            if ($imageState !== null) {
                $stats->imagesPending++;
            }

            return 'done';
        }

        $localId = (int) $row->local_id;
        $this->categoriesEntity->update($localId, $fields);
        if (!$this->urlMatches($this->categoriesEntity, $localId, $slug)) {
            $this->slugMutationError('category', $externalId, $slug, $stats);

            return 'done';
        }
        $this->map->recordUpdate($row, $localId, $hash, $imageState);
        $stats->updated++;
        if ($imageState !== null) {
            $stats->imagesPending++;
        }

        return 'done';
    }

    // ---------------------------------------------------------------- brands

    private function applyBrandsFile(string $path, ApplyStats $stats): int
    {
        $read = $this->reader->each($path, function (array $line) use ($stats): void {
            $this->applyBrandLine($line, $stats);
        });
        $stats->errors += $read['broken'];

        return $read['valid'];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function applyBrandLine(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_BRAND, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            $stats->skipped++;

            return;
        }

        $slug = (string) ($data['slug'] ?? '');
        $fields = [
            'url'     => $slug,
            'visible' => !empty($data['is_active']) ? 1 : 0,
            'name'    => (string) ($data['name'] ?? ''),
        ];

        if ($decision === Contract::MAP_CREATE) {
            $localId = (int) $this->brandsEntity->add($fields);
            if (!$this->urlMatches($this->brandsEntity, $localId, $slug)) {
                $this->slugMutationError('brand', $externalId, $slug, $stats);

                return;
            }
            $this->map->recordCreate(Contract::ENTITY_BRAND, $externalId, $localId, $hash);
            $stats->upserted++;

            return;
        }

        $localId = (int) $row->local_id;
        $this->brandsEntity->update($localId, $fields);
        if (!$this->urlMatches($this->brandsEntity, $localId, $slug)) {
            $this->slugMutationError('brand', $externalId, $slug, $stats);

            return;
        }
        $this->map->recordUpdate($row, $localId, $hash);
        $stats->updated++;
    }

    // ---------------------------------------------------------------- features

    private function applyFeaturesFile(string $path, ApplyStats $stats): int
    {
        $read = $this->reader->each($path, function (array $line) use ($stats): void {
            $this->applyFeatureLine($line, $stats);
        });
        $stats->errors += $read['broken'];

        return $read['valid'];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function applyFeatureLine(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_FEATURE, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            $stats->skipped++;

            return;
        }

        $fields = [
            'name'        => (string) ($data['name'] ?? ''),
            'in_filter'   => !empty($data['filterable']) ? 1 : 0,
            'visible'     => 1,
            'external_id' => $externalId,
        ];

        if ($decision === Contract::MAP_CREATE) {
            $localId = (int) $this->featuresEntity->add($fields);
            $this->map->recordCreate(Contract::ENTITY_FEATURE, $externalId, $localId, $hash);
            $stats->upserted++;

            return;
        }

        $localId = (int) $row->local_id;
        $this->featuresEntity->update($localId, $fields);
        $this->map->recordUpdate($row, $localId, $hash);
        $stats->updated++;
    }

    // ---------------------------------------------------------------- products (+ variants + images)

    /**
     * @param array<int, string> $seenProducts by-ref: external_id всех товаров снапшота (для absent)
     */
    private function applyProductsFile(string $path, ApplyStats $stats, array &$seenProducts): int
    {
        $read = $this->reader->each($path, function (array $line) use ($stats, &$seenProducts): void {
            $seenProducts[] = (string) $line['external_id'];
            $this->applyProductLine($line, $stats);
        });
        $stats->errors += $read['broken'];

        return $read['valid'];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function applyProductLine(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_PRODUCT, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            $stats->skipped++;

            return;
        }

        $brandId = 0;
        $brandExternal = $data['brand_external_id'] ?? null;
        if ($brandExternal !== null) {
            $resolved = $this->map->localId(Contract::ENTITY_BRAND, (string) $brandExternal);
            if ($resolved === null) {
                $this->warning('CoreSync apply: товар ' . $externalId . ' — бренд ' . $brandExternal . ' не в карте');
                $stats->errors++;

                return;
            }
            $brandId = $resolved;
        }

        $categories = (array) ($data['categories'] ?? []);
        $mainCategoryId = null;
        $primaryExternal = $categories['primary'] ?? null;
        if ($primaryExternal !== null) {
            $mainCategoryId = $this->map->localId(Contract::ENTITY_CATEGORY, (string) $primaryExternal);
            if ($mainCategoryId === null) {
                $this->warning('CoreSync apply: товар ' . $externalId . ' — primary-категория не в карте');
                $stats->errors++;

                return;
            }
        }

        $slug = (string) ($data['slug'] ?? '');
        $seo = (array) ($data['seo'] ?? []);
        $images = (array) ($data['images'] ?? []);
        $imageState = !empty($images) ? Contract::IMAGE_STATE_PENDING : null;

        $fields = [
            'url'              => $slug,
            'brand_id'         => $brandId,
            'visible'          => !empty($data['visible']) ? 1 : 0,
            'external_id'      => $externalId,
            'name'             => (string) ($data['name'] ?? ''),
            'description'      => (string) ($data['description_html'] ?? ''),
            'meta_title'       => (string) ($seo['title'] ?? ''),
            'meta_keywords'    => (string) ($seo['keywords'] ?? ''),
            'meta_description' => (string) ($seo['description'] ?? ''),
        ];
        if ($mainCategoryId !== null) {
            $fields['main_category_id'] = $mainCategoryId;
        }

        if ($decision === Contract::MAP_CREATE) {
            $localId = (int) $this->productsEntity->add($fields);
            if (!$this->urlMatches($this->productsEntity, $localId, $slug)) {
                $this->slugMutationError('product', $externalId, $slug, $stats);

                return;
            }
            $this->map->recordCreate(Contract::ENTITY_PRODUCT, $externalId, $localId, $hash, $imageState);
            $stats->upserted++;
        } else {
            $localId = (int) $row->local_id;
            $this->productsEntity->update($localId, $fields);
            if (!$this->urlMatches($this->productsEntity, $localId, $slug)) {
                $this->slugMutationError('product', $externalId, $slug, $stats);

                return;
            }
            $this->map->recordUpdate($row, $localId, $hash, $imageState);
            $stats->updated++;
        }

        $this->reconcileProductCategories($localId, $categories, $mainCategoryId);
        $this->reconcileProductFeatureValues($localId, (array) ($data['feature_values'] ?? []));
        $this->reconcileVariants($localId, (array) ($data['variants'] ?? []), $stats);
        $this->reconcileImages($localId, $externalId, $images, $stats);
    }

    /**
     * @param array<string, mixed> $categories
     */
    private function reconcileProductCategories(int $productId, array $categories, ?int $mainCategoryId): void
    {
        $desired = [];
        if ($mainCategoryId !== null) {
            $desired[$mainCategoryId] = 0;
        }
        $position = 1;
        foreach ((array) ($categories['additional'] ?? []) as $addExternal) {
            $cid = $this->map->localId(Contract::ENTITY_CATEGORY, (string) $addExternal);
            if ($cid !== null && !isset($desired[$cid])) {
                $desired[$cid] = $position++;
            }
        }

        $existing = [];
        foreach ($this->categoriesEntity->getProductCategories([$productId]) as $linkRow) {
            $existing[] = (int) $linkRow->category_id;
        }

        foreach ($desired as $categoryId => $pos) {
            if (!in_array($categoryId, $existing, true)) {
                $this->categoriesEntity->addProductCategory($productId, $categoryId, $pos);
            }
        }

        $stale = array_values(array_diff($existing, array_keys($desired)));
        if (!empty($stale)) {
            $this->categoriesEntity->deleteProductCategory($productId, $stale);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $featureValues
     */
    private function reconcileProductFeatureValues(int $productId, array $featureValues): void
    {
        $this->featuresValuesEntity->deleteProductValue($productId);

        foreach ($featureValues as $pair) {
            $pair = (array) $pair;
            $featureExternal = (string) ($pair['feature_external_id'] ?? '');
            $value = (string) ($pair['value'] ?? '');
            if ($featureExternal === '' || $value === '') {
                continue;
            }
            $featureId = $this->map->localId(Contract::ENTITY_FEATURE, $featureExternal);
            if ($featureId === null) {
                continue;
            }

            $translit = Translit::translitAlpha($value);
            $valueId = 0;
            foreach ($this->featuresValuesEntity->find(['feature_id' => $featureId, 'translit' => $translit]) as $existingValue) {
                $valueId = (int) $existingValue->id;
                break;
            }
            if ($valueId === 0) {
                $valueId = (int) $this->featuresValuesEntity->add([
                    'feature_id' => $featureId,
                    'value'      => $value,
                    'translit'   => $translit,
                ]);
            }
            if ($valueId > 0) {
                $this->featuresValuesEntity->addProductValue($productId, $valueId);
            }
        }
    }

    /**
     * Варианты товара: upsert по external_id. stock ЯВНЫМ числом (0=0, НЕ NULL=∞). Исчезнувший
     * вариант → stock=0. Каждый вариант получает СВОЮ строку карты (entity_type=variant) с per-variant
     * hash — основа bind/price_stock (variant-grain, line-item M3 §0.1).
     *
     * @param array<int, array<string, mixed>> $variants
     */
    private function reconcileVariants(int $productId, array $variants, ApplyStats $stats): void
    {
        $existingByExternal = []; // external_id => variantRow витрины (легаси/уже синхронизированные)
        foreach ($this->variantsEntity->find(['product_id' => $productId]) as $variantRow) {
            $existingByExternal[(string) $variantRow->external_id] = $variantRow;
        }

        $snapshotIds = [];
        foreach ($variants as $variant) {
            $variant = (array) $variant;
            $variantExternal = (string) ($variant['external_id'] ?? '');
            if ($variantExternal === '') {
                continue;
            }
            $snapshotIds[$variantExternal] = true;

            $price = (array) ($variant['price'] ?? []);
            $variantCurrency = (string) ($price['currency'] ?? '');
            $currencyId = $this->currencyMap[$variantCurrency] ?? $this->manifestCurrencyId;

            $fields = [
                'product_id'  => $productId,
                'sku'         => (string) ($variant['sku'] ?? ''),
                'price'       => (string) ($price['amount'] ?? '0'),
                'stock'       => (int) ($variant['stock'] ?? 0),
                'currency_id' => (int) $currencyId,
                'external_id' => $variantExternal, // проставляем ключ ядра (после bind — впервые)
            ];

            // Локализация варианта: variant-карта (bound/синхронизированный) → external_id витрины → создать.
            $mapRow = $this->map->find(Contract::ENTITY_VARIANT, $variantExternal);
            if ($mapRow !== null && $mapRow->local_id !== null) {
                $localVariantId = (int) $mapRow->local_id;
                $this->variantsEntity->update($localVariantId, $fields);
            } elseif (isset($existingByExternal[$variantExternal])) {
                $localVariantId = (int) $existingByExternal[$variantExternal]->id;
                $this->variantsEntity->update($localVariantId, $fields);
            } else {
                $localVariantId = (int) $this->variantsEntity->add($fields);
            }

            $this->recordVariantMap($variantExternal, $localVariantId, $this->variantHash($variant));
        }

        // Исчезнувшие варианты (по ключу ядра) существующего товара → stock=0 (деактивация без удаления).
        foreach ($existingByExternal as $variantExternal => $variantRow) {
            if ($variantExternal !== '' && !isset($snapshotIds[$variantExternal])) {
                $this->variantsEntity->update((int) $variantRow->id, ['stock' => 0]);
                $stats->deactivated++;
            }
        }
    }

    private function recordVariantMap(string $variantExternal, int $localVariantId, string $hash): void
    {
        $row = $this->map->find(Contract::ENTITY_VARIANT, $variantExternal);
        if ($row === null) {
            $this->map->recordCreate(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId, $hash);
        } else {
            $this->map->recordUpdate($row, $localVariantId, $hash);
        }
    }

    /**
     * Детерминированный per-variant content-hash (price/stock/sku). Общий для full и price_stock:
     * основа skip-идемпотентности price_stock (изменилась ли цена/сток без пере-записи всего).
     *
     * @param array<string, mixed> $variant
     */
    private function variantHash(array $variant): string
    {
        $price = (array) ($variant['price'] ?? []);
        $canonical = [
            'sku'      => (string) ($variant['sku'] ?? ''),
            'amount'   => (string) ($price['amount'] ?? '0'),
            'currency' => (string) ($price['currency'] ?? ''),
            'stock'    => (int) ($variant['stock'] ?? 0),
        ];

        return hash('sha256', (string) json_encode($canonical));
    }

    /**
     * Durable-реконсиляция списка картинок товара (вне staging). desired (снапшот) vs existing
     * (durable-таблица) по url_hash: новый → pending-строка; пропавший → удаление строки + ImagesEntity.
     * Скачивание — в догоняющей фазе (runImagesPhase).
     *
     * @param array<int, array<string, mixed>> $images
     */
    private function reconcileImages(int $productId, string $productExternal, array $images, ApplyStats $stats): void
    {
        $desired = []; // url_hash => {url, sort}
        foreach ($images as $img) {
            $img = (array) $img;
            $urlHash = (string) ($img['url_hash'] ?? '');
            $url = (string) ($img['url'] ?? '');
            if ($urlHash === '' || $url === '') {
                continue;
            }
            $desired[$urlHash] = ['url' => $url, 'sort' => (int) ($img['sort'] ?? 0)];
        }

        $existing = []; // url_hash => durable row
        foreach ($this->coresyncImagesEntity->find(['product_external_id' => $productExternal]) as $imgRow) {
            $existing[(string) $imgRow->url_hash] = $imgRow;
        }

        foreach ($desired as $urlHash => $info) {
            if (isset($existing[$urlHash])) {
                $rowObj = $existing[$urlHash];
                $patch = [];
                if ((int) $rowObj->sort !== $info['sort']) {
                    $patch['sort'] = $info['sort'];
                    if (!empty($rowObj->image_id)) {
                        $this->imagesEntity->update((int) $rowObj->image_id, ['position' => $info['sort']]);
                    }
                }
                if ((int) $rowObj->product_local_id !== $productId) {
                    $patch['product_local_id'] = $productId;
                }
                if (!empty($patch)) {
                    $this->coresyncImagesEntity->update((int) $rowObj->id, $patch);
                }
            } else {
                $this->coresyncImagesEntity->add([
                    'product_external_id' => $productExternal,
                    'product_local_id'    => $productId,
                    'url'                 => $info['url'],
                    'url_hash'            => $urlHash,
                    'sort'                => $info['sort'],
                    'state'               => Contract::IMAGE_STATE_PENDING,
                    'attempts'            => 0,
                    'filename'            => null,
                    'image_id'            => null,
                ]);
            }
        }

        // Удалённые из снапшота картинки товара → удаление строк ImagesEntity + durable.
        foreach ($existing as $urlHash => $rowObj) {
            if (!isset($desired[$urlHash])) {
                if (!empty($rowObj->image_id)) {
                    $this->imagesEntity->delete((int) $rowObj->image_id);
                }
                $this->coresyncImagesEntity->delete((int) $rowObj->id);
            }
        }

        // imagesPending — товар с ещё не зеркалированными (не done) картинками.
        $pending = false;
        foreach ($this->coresyncImagesEntity->find(['product_external_id' => $productExternal]) as $imgRow) {
            if ((string) $imgRow->state !== Contract::IMAGE_STATE_DONE) {
                $pending = true;
                break;
            }
        }
        if ($pending) {
            $stats->imagesPending++;
        }
    }

    // ---------------------------------------------------------------- images phase

    /**
     * Догоняющая фаза картинок (только full). По durable-списку: pending/failed → скачивание,
     * позиции ImagesEntity по sort, первый (min sort) = main_image. Неудача → images_failed,
     * старое цело, ретрай в следующем прогоне (attempts++). Кооперативная отмена между товарами.
     *
     * @return string Contract::STATUS_CANCELLED | STATUS_APPLIED (не терминальный — индикатор отмены)
     */
    private function runImagesPhase(callable $isCancelled, ApplyStats $stats): string
    {
        if ($this->imageDownloader === null) {
            return Contract::STATUS_APPLIED; // без загрузчика фаза не выполняется (rows остаются pending)
        }

        // Группируем недокачанные (не done) строки по товару.
        $byProduct = []; // localId => list<row>
        $externalOf = []; // localId => productExternal
        foreach ($this->coresyncImagesEntity->find([]) as $imgRow) {
            if ((string) $imgRow->state === Contract::IMAGE_STATE_DONE) {
                continue;
            }
            $localId = (int) $imgRow->product_local_id;
            if ($localId <= 0) {
                continue;
            }
            $byProduct[$localId][] = $imgRow;
            $externalOf[$localId] = (string) $imgRow->product_external_id;
        }

        foreach ($byProduct as $localId => $rows) {
            if ($isCancelled()) {
                $this->info('CoreSync images: отмена между товарами');

                return Contract::STATUS_CANCELLED;
            }

            usort($rows, static function ($a, $b): int {
                return (int) $a->sort <=> (int) $b->sort;
            });

            foreach ($rows as $imgRow) {
                $filename = $this->imageDownloader->download((string) $imgRow->url);
                if ($filename === null) {
                    $this->coresyncImagesEntity->update((int) $imgRow->id, [
                        'state'    => Contract::IMAGE_STATE_FAILED,
                        'attempts' => (int) $imgRow->attempts + 1,
                    ]);
                    $stats->imagesFailed++;
                    continue;
                }
                $imageId = (int) $this->imagesEntity->add([
                    'product_id' => $localId,
                    'filename'   => $filename,
                    'position'   => (int) $imgRow->sort,
                ]);
                $this->coresyncImagesEntity->update((int) $imgRow->id, [
                    'state'    => Contract::IMAGE_STATE_DONE,
                    'attempts' => (int) $imgRow->attempts + 1,
                    'filename' => $filename,
                    'image_id' => $imageId,
                ]);
            }

            $this->assignMainImage($localId);
            $this->refreshProductImageState($localId, $externalOf[$localId] ?? '');
        }

        return Contract::STATUS_APPLIED;
    }

    /**
     * main_image = скачанная (done) картинка с минимальным sort.
     */
    private function assignMainImage(int $productId): void
    {
        $best = null;
        foreach ($this->coresyncImagesEntity->find(['product_local_id' => $productId]) as $imgRow) {
            if ((string) $imgRow->state !== Contract::IMAGE_STATE_DONE || empty($imgRow->image_id)) {
                continue;
            }
            if ($best === null || (int) $imgRow->sort < (int) $best->sort) {
                $best = $imgRow;
            }
        }
        if ($best !== null) {
            $this->productsEntity->update($productId, ['main_image_id' => (int) $best->image_id]);
        }
    }

    private function refreshProductImageState(int $productId, string $productExternal): void
    {
        if ($productExternal === '') {
            return;
        }
        $anyPending = false;
        $anyFailed = false;
        foreach ($this->coresyncImagesEntity->find(['product_local_id' => $productId]) as $imgRow) {
            $state = (string) $imgRow->state;
            if ($state === Contract::IMAGE_STATE_FAILED) {
                $anyFailed = true;
            } elseif ($state !== Contract::IMAGE_STATE_DONE) {
                $anyPending = true;
            }
        }
        $state = $anyPending
            ? Contract::IMAGE_STATE_PENDING
            : ($anyFailed ? Contract::IMAGE_STATE_FAILED : Contract::IMAGE_STATE_DONE);
        $this->map->updateImageState($productExternal, $state);
    }

    // ---------------------------------------------------------------- redirects

    private function applyRedirectsFile(string $path, ApplyStats $stats): int
    {
        if ($this->redirectsEntity === null) {
            $this->warning('CoreSync apply: модуль Format/Redirects не установлен — редиректы пропущены');

            return 0;
        }

        $read = $this->reader->each($path, function (array $line) use ($stats): void {
            $this->applyRedirectLine($line, $stats);
        });
        $stats->errors += $read['broken'];

        return $read['valid'];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function applyRedirectLine(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_REDIRECT, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            $stats->skipped++;

            return;
        }

        $entityType = (string) ($data['entity_type'] ?? '');
        $requestUrl = $this->frontUrl($entityType, (string) ($data['old_slug'] ?? ''));
        $resultUrl = $this->frontUrl($entityType, (string) ($data['new_slug'] ?? ''));
        if ($requestUrl === '' || $resultUrl === '') {
            $stats->errors++;

            return;
        }

        $existing = $this->redirectsEntity->findOne(['request_url' => $requestUrl]);
        $fields = [
            'request_url' => $requestUrl,
            'result_url'  => $resultUrl,
            'status_code' => 301,
            'status'      => 1,
        ];
        if (!empty($existing)) {
            $localId = (int) $existing->id;
            $this->redirectsEntity->update($localId, $fields);
        } else {
            $localId = (int) $this->redirectsEntity->add($fields);
        }

        if ($decision === Contract::MAP_CREATE) {
            $this->map->recordCreate(Contract::ENTITY_REDIRECT, $externalId, $localId, $hash);
            $stats->upserted++;
        } else {
            $this->map->recordUpdate($row, $localId, $hash);
            $stats->updated++;
        }
    }

    private function frontUrl(string $entityType, string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        if ($entityType === 'product') {
            $prefix = (string) $this->settings->get('product_routes_template__default');

            return '/' . ($prefix !== '' ? $prefix : 'products') . '/' . $slug;
        }
        if ($entityType === 'category') {
            $prefix = (string) $this->settings->get('category_routes_template__default');

            return '/' . ($prefix !== '' ? $prefix : 'catalog') . '/' . $slug;
        }

        return '';
    }

    // ---------------------------------------------------------------- absent

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, string>   $seenProducts external_id товаров снапшота
     * @return bool true — фаза held (порог превышен, absent пропущен)
     */
    private function applyAbsent(array $manifest, ApplyStats $stats, array $seenProducts): bool
    {
        $mapped = $this->map->allLocalIds(Contract::ENTITY_PRODUCT);
        if (empty($mapped)) {
            return false;
        }

        $seen = array_fill_keys($seenProducts, true);
        $absent = [];
        foreach ($mapped as $externalId => $localId) {
            if (!isset($seen[$externalId])) {
                $absent[$externalId] = $localId;
            }
        }
        $absentCount = count($absent);
        $stats->absentCount = $absentCount;
        if ($absentCount === 0) {
            return false;
        }

        if (($absentCount / count($mapped)) > Contract::ABSENT_MAX_RATIO) {
            $this->warning(sprintf(
                'CoreSync apply: absent %d из %d (>%d%%) — фаза absent пропущена (held)',
                $absentCount,
                count($mapped),
                (int) (Contract::ABSENT_MAX_RATIO * 100)
            ));

            return true;
        }

        $policy = (string) ($manifest['absent_policy'] ?? 'out_of_stock');
        foreach ($absent as $localId) {
            if ($policy === 'hide') {
                $this->productsEntity->update($localId, ['visible' => 0]);
            } else {
                foreach ($this->variantsEntity->find(['product_id' => $localId]) as $variantRow) {
                    $this->variantsEntity->update((int) $variantRow->id, ['stock' => 0]);
                }
            }
            $stats->deactivated++;
        }

        return false;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param mixed $entity сущность с get()/findOne()
     */
    private function urlMatches($entity, int $localId, string $expectedSlug): bool
    {
        if ($localId <= 0) {
            return false;
        }
        $saved = $entity->findOne(['id' => $localId]);
        if (empty($saved) || !isset($saved->url)) {
            return false;
        }

        return (string) $saved->url === $expectedSlug;
    }

    private function slugMutationError(string $type, string $externalId, string $slug, ApplyStats $stats): void
    {
        $this->warning(sprintf(
            'CoreSync apply: %s %s — url мутирован ядром (ожидался slug "%s"), строка не в карте',
            $type,
            $externalId,
            $slug
        ));
        $stats->errors++;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array{name:string, role:string}>
     */
    private function orderedFiles(array $manifest): array
    {
        $priority = [
            Contract::ENTITY_CATEGORY => 0,
            Contract::ENTITY_BRAND    => 1,
            Contract::ENTITY_FEATURE  => 2,
            Contract::ENTITY_PRODUCT  => 3,
            Contract::ENTITY_REDIRECT => 4,
        ];

        $files = [];
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $name = (string) ($file['name'] ?? '');
            $role = $this->roleOf($name);
            if ($role === null) {
                continue;
            }
            $files[] = ['name' => $name, 'role' => $role];
        }

        usort($files, static function (array $a, array $b) use ($priority): int {
            if ($priority[$a['role']] !== $priority[$b['role']]) {
                return $priority[$a['role']] <=> $priority[$b['role']];
            }

            return strcmp($a['name'], $b['name']);
        });

        return $files;
    }

    private function roleOf(string $name): ?string
    {
        if (strpos($name, 'categories') === 0) {
            return Contract::ENTITY_CATEGORY;
        }
        if (strpos($name, 'brands') === 0) {
            return Contract::ENTITY_BRAND;
        }
        if (strpos($name, 'features') === 0) {
            return Contract::ENTITY_FEATURE;
        }
        if (strpos($name, 'products') === 0) {
            return Contract::ENTITY_PRODUCT;
        }
        if (strpos($name, 'redirects') === 0) {
            return Contract::ENTITY_REDIRECT;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $raw = $this->settings->get(Contract::SETTINGS_KEY);

        return is_array($raw) ? $raw : [];
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }

    private function warning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    private function error(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
