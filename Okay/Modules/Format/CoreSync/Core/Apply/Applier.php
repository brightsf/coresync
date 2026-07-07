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
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CurrencyNotMappedException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;
use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Psr\Log\LoggerInterface;

/**
 * Применение сверенного снапшота в БД витрины Okay (full-режим). Фазы строго по порядку:
 * categories → brands → features → products(+варианты) → redirects → absent. Каждая строка
 * идемпотентна через карту (MapGateway): applied_hash совпал → skip. url диктует ядро (пишется
 * ЯВНО + пост-проверка мутации). Валюта без маппинга → fail-closed ДО фазы products. Картинки НЕ
 * качаются (M3) — товар с картинками флажится image_state=pending. absent > порога → held.
 *
 * Класс НЕ final и резолвит сущности через EntityFactory — тесты подставляют стаб-сущности
 * (паттерн APIImport), реальная БД не нужна.
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

    // Резолвятся в apply() (боот на прогон).
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
    /** @var mixed RedirectsEntity|null (модуль Format/Redirects может быть не установлен) */
    private $redirectsEntity;

    /** @var array<int, int> currency_map: manifestCode игнорируется, ключ — код → currency_id */
    private $currencyMap = [];
    /** @var int currency_id валюты манифеста (после currency-гейта) */
    private $manifestCurrencyId = 0;

    const REDIRECTS_ENTITY_CLASS = 'Okay\\Modules\\Format\\Redirects\\Entities\\RedirectsEntity';

    public function __construct(
        EntityFactory $entityFactory,
        Settings $settings,
        NdjsonGzReader $reader,
        ?Languages $languages = null,
        ?LoggerInterface $logger = null
    ) {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->reader = $reader;
        $this->languages = $languages;
        $this->logger = $logger;
    }

    /**
     * Применить снапшот. Возвращает терминальный статус job'а.
     *
     * @param array<string, mixed> $manifest
     * @param callable():bool      $isCancelled кооперативная отмена между файлами
     * @return string Contract::STATUS_APPLIED | STATUS_HELD | STATUS_CANCELLED | STATUS_FAILED
     */
    public function apply(
        array $manifest,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        ApplyStats $stats
    ): string {
        $this->boot();

        // --- Currency-гейт: fail-closed ВСЕГО прогона ДО фазы products (enforcement из M1) ---
        try {
            $this->resolveCurrency($manifest);
        } catch (CurrencyNotMappedException $e) {
            $this->error('CoreSync apply: ' . $e->getMessage());

            return Contract::STATUS_FAILED;
        }

        $seenProducts = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($isCancelled()) {
                $this->info('CoreSync apply: отмена между файлами');

                return Contract::STATUS_CANCELLED;
            }

            $name = $file['name'];
            $role = $file['role'];

            // Resume: файл уже применён — не переприменяем (строки всё равно были бы skip).
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
                // Битый файл (доля broken выше порога) → job failed после фазы.
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

        return $held ? Contract::STATUS_HELD : Contract::STATUS_APPLIED;
    }

    private function boot(): void
    {
        $this->map = new MapGateway($this->entityFactory->get(CoreSyncMapEntity::class));
        $this->categoriesEntity = $this->entityFactory->get(CategoriesEntity::class);
        $this->brandsEntity = $this->entityFactory->get(BrandsEntity::class);
        $this->featuresEntity = $this->entityFactory->get(FeaturesEntity::class);
        $this->featuresValuesEntity = $this->entityFactory->get(FeaturesValuesEntity::class);
        $this->productsEntity = $this->entityFactory->get(ProductsEntity::class);
        $this->variantsEntity = $this->entityFactory->get(VariantsEntity::class);

        $this->redirectsEntity = null;
        if (class_exists(self::REDIRECTS_ENTITY_CLASS)) {
            $this->redirectsEntity = $this->entityFactory->get(self::REDIRECTS_ENTITY_CLASS);
        }

        // Пишем языковые поля в язык-приёмник канала (настройка lang_id).
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
        // Родители раньше детей (дамп отсортирован деревом); forward-ref → отложенная очередь.
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
        // Неразрешённые forward-ref (родителя нет в карте после всех проходов) → ошибки строк.
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

        // update
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

        // Свойство не имеет slug в контракте → url генерит ядро (мутация не проверяется).
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

    // ---------------------------------------------------------------- products (+ variants)

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

        // Бренд через карту (null-бренд = 0; отсутствие в карте = ошибка строки).
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

        // Главная категория (primary) через карту.
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
        $imageState = !empty($data['images']) ? Contract::IMAGE_STATE_PENDING : null;

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

        if ($imageState !== null) {
            $stats->imagesPending++;
        }

        $this->reconcileProductCategories($localId, $categories, $mainCategoryId);
        $this->reconcileProductFeatureValues($localId, (array) ($data['feature_values'] ?? []));
        $this->reconcileVariants($localId, (array) ($data['variants'] ?? []), $stats);
    }

    /**
     * Категории товара: желаемый набор (primary + additional) через карту; лишние связи (нет в
     * снапшоте) удаляются — только для товара карты.
     *
     * @param array<string, mixed> $categories
     */
    private function reconcileProductCategories(int $productId, array $categories, ?int $mainCategoryId): void
    {
        $desired = []; // categoryId => position
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
     * Значения свойств товара: полный сброс + переустановка из снапшота (отвязка отсутствующих).
     *
     * @param array<int, array<string, mixed>> $featureValues
     */
    private function reconcileProductFeatureValues(int $productId, array $featureValues): void
    {
        // Отвязать все текущие значения товара, затем проставить набор снапшота.
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
                continue; // свойство не применено — пропускаем связку (не роняем товар)
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
     * Варианты товара: upsert по external_id (в рамках товара). stock пишется ЯВНЫМ числом (0 = 0,
     * НЕ NULL=∞). Вариант, исчезнувший у существующего товара, → stock=0 (деактивация без удаления —
     * заказы Okay ссылаются на строки). Новые варианты создаются (full-режим).
     *
     * @param array<int, array<string, mixed>> $variants
     */
    private function reconcileVariants(int $productId, array $variants, ApplyStats $stats): void
    {
        $existing = []; // external_id => variantRow
        foreach ($this->variantsEntity->find(['product_id' => $productId]) as $variantRow) {
            $existing[(string) $variantRow->external_id] = $variantRow;
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
                'stock'       => (int) ($variant['stock'] ?? 0), // ЯВНЫЙ 0, не NULL
                'currency_id' => (int) $currencyId,
                'external_id' => $variantExternal,
            ];

            if (isset($existing[$variantExternal])) {
                $this->variantsEntity->update((int) $existing[$variantExternal]->id, $fields);
            } else {
                $this->variantsEntity->add($fields);
            }
        }

        // Исчезнувшие варианты существующего товара → stock=0 (деактивация).
        foreach ($existing as $variantExternal => $variantRow) {
            if (!isset($snapshotIds[$variantExternal])) {
                $this->variantsEntity->update((int) $variantRow->id, ['stock' => 0]);
                $stats->deactivated++;
            }
        }
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

        // Upsert по UNIQUE request_url (301, активен).
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

    /**
     * Полный page-url по фронт-роутингу Okay (сравнение хука FrontEndExtender — по getPageUrl,
     * т.е. по пути с ведущим слэшем). Префиксы — настройки роутов (product_routes_template__default
     * / category_routes_template__default), дефолты products / catalog.
     */
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
        $absent = []; // external_id => localId
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

        // Порог-предохранитель: absent > 20% товаров карты → фазу ПРОПУСТИТЬ (held).
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
            } else { // out_of_stock (дефолт): все варианты товара stock=0
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
     * Slug диктует ядро: пишем url ЯВНО, затем проверяем фактический url записи. Авто-мутация ядром
     * Okay (коллизия → url2 / url_1) = ошибка применения строки.
     *
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
     * Файлы манифеста в порядке фаз: category → brand → feature → product (по имени) → redirect.
     *
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

            return strcmp($a['name'], $b['name']); // чанки products по имени
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
