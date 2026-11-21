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
use Okay\Modules\Format\CoreSync\Core\CategoryV2Validator;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CurrencyNotMappedException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\FeatureLifecycleConflict;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;
use Okay\Modules\Format\CoreSync\Core\StagedSnapshotFile;
use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Core\ProductContentLanguageCatalog;
use Okay\Modules\Format\CoreSync\Core\ProductV3Validator;
use Okay\Modules\Format\CoreSync\Core\ProductV4Validator;
use Okay\Modules\Format\CoreSync\Core\ProductV5Validator;
use Okay\Modules\Format\CoreSync\Core\VariantInventory;
use Okay\Modules\Format\CoreSync\Core\VariantInventoryStore;
use Okay\Modules\Format\CoreSync\Core\FeatureV5Validator;
use Okay\Modules\Format\CoreSync\Core\FeatureLineageValidator;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Okay\Modules\Format\CoreSync\Core\Preview\FullMediaPreview;
use Okay\Modules\Format\CoreSync\Core\Preview\PreviewEntityFactory;
use Okay\Modules\Format\CoreSync\Core\Preview\PreviewFeatureLifecycle;
use Psr\Log\LoggerInterface;

/**
 * Применение сверенного снапшота в БД витрины Okay. Три режима, выбор в apply():
 *  - bind: bind ещё НЕ отрабатывал И карта пуста (0 строк product/variant) И каталог непуст →
 *    товары связываются по SKU, category/brand — по marker/exact slug. Контент не пишется; для
 *    словарей разрешена только durable identity (coresync_external_id + map).
 *    Bind — ОДНОРАЗОВАЯ фаза (отметка в карте): «связано 0» тоже её завершает, иначе витрина с чужим
 *    каталогом вечно перебиндивается и не получает каталог ядра (D-SAT-BIND-LOOP-NEVER-APPLIES);
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
    /** @var CategoryV2Validator */
    private $categoryV2Validator;
    /** @var ProductSourceIdentityValidator */
    private $productSourceIdentityValidator;
    /** @var ProductContentLanguageCatalog|null */
    private $productContentLanguageCatalog;
    /** @var ProductV3Validator */
    private $productV3Validator;
    /** @var ProductV4Validator */
    private $productV4Validator;
    /** @var ProductV5Validator */
    private $productV5Validator;
    /** @var FeatureV5Validator */
    private $featureV5Validator;
    /** @var array<string, array<string, mixed>> validated v5 feature rows */
    private $v5Features = [];
    /** @var array<string, int> preflighted existing feature id, or zero for create */
    private $v5FeaturePlan = [];
    /** @var array<string, int> preflighted existing option id, or zero for create */
    private $v5OptionPlan = [];
    /** @var array<string, string> source option key => first equivalent key in the same feature */
    private $v5OptionCanonical = [];
    /** @var array<string, string> new-parent source => deterministic physical owner source */
    private $v5ParentCanonical = [];
    /** @var array<string, bool> option keys selected by validated product rows */
    private $v5RequiredOptionKeys = [];
    /** @var array<string, bool> selected or already mapped option keys in this dictionary */
    private $v5MaterializedOptionKeys = [];
    /** @var ProductDocumentStore|null своё хранилище документов (product_documents_store) */
    private $productDocumentStore;
    /** @var string|null режим документов манифеста: store | disabled | null (старая форма, не применяется) */
    private $documentsMode;
    /** @var bool предупреждение о документах старой формы уже записано в этом обмене */
    private $legacyDocumentsWarned = false;
    /** @var FullMediaPreview|null */
    private $mediaPreview;
    /** @var array<string, int> */
    private $manifestDocuments = [];
    /** @var array<string, int> validated href_lang => local language id for one v3 apply */
    private $productContentLanguageIds = [];
    /** @var array<string, int> v5 snapshot-declared href_lang => local language id */
    private $v5LinkLanguageIds = [];
    /** @var V5AdoptionPlan|null Immutable optional receiver-only V5 decisions. */
    private $v5AdoptionPlan;
    /** @var array<string, array<int, array<string, mixed>>> validated selected pairs by source product ID */
    private $v5SelectedProductPairs = [];
    /** @var array<string, array{product_id:int,variants:array<string,int>}> */
    private $v5ProtectedProductIdentities = [];
    /** @var array<string, bool> holds found in the validated product stream */
    private $v5SeenHolds = [];
    /** @var array<int, int> exact existing held physical links to retain for current product */
    private $v5ProtectedValueIds = [];
    /** @var array<string, mixed> affected identities for fail-fast feature checks */
    private $v5ConflictContext = [];
    /** @var string source product currently reconciling its feature links */
    private $v5CurrentProductExternal = '';
    /** @var string validated channel/default language for one v3 apply */
    private $manifestLanguage = '';
    /** @var CategoryImageDownloader|null */
    private $categoryImageDownloader;
    /**
     * @var GalleryContentAdopter|null усыновление уже лежащей галереи по содержимому (null → фаза
     *      усыновления не выполняется, поведение остаётся до-адопционным: всё качается заново)
     */
    private $galleryContentAdopter;
    /**
     * @var int|null Кап попыток на пути ТИКА (добор хвоста): выставляется в applyPendingImages(),
     *      сбрасывается в apply(). null — полный проход, где кап НЕ действует: исчерпанные строки
     *      переигрываются, семантика attempts не меняется ({@see Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS}).
     */
    private $imageTickRetryCap;
    /** @var int Manifest-selected schema major, pinned once per apply call. */
    private $schemaMajor = 1;

    /**
     * Локальные id характеристик из карты модуля, снятые один раз за прогон (кэш живёт от boot()
     * до конца apply). null — ещё не читали.
     *
     * @var array<int, int>|null
     */
    private $managedFeatureLocalIds;
    /** @var string Manifest-run source namespace, pinned once per apply call. */
    private $sourceInstance = '';

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
    /** @var CoreSyncCategoryImagesEntity|null */
    private $coresyncCategoryImagesEntity;
    /** @var mixed RedirectsEntity|null (модуль Format/Redirects может быть не установлен) */
    private $redirectsEntity;

    /** @var array<string, int> currency_map: код валюты → currency_id */
    private $currencyMap = [];
    /** @var int currency_id валюты манифеста (после currency-гейта) */
    private $manifestCurrencyId = 0;

    const REDIRECTS_ENTITY_CLASS = 'Okay\\Modules\\Format\\Redirects\\Entities\\RedirectsEntity';

    /** @var FeatureLinkAdoptionTransaction|null */
    private $featureLinkAdoptionTransaction;
    /** @var FeatureLifecycleLock|null */
    private $featureLifecycleLock;
    private $variantInventoryStore;
    private $inventoryVariantProducts = [];
    private $snapshotVariantIds = [];

    public function __construct(
        EntityFactory $entityFactory,
        Settings $settings,
        NdjsonGzReader $reader,
        ?Languages $languages = null,
        ?LoggerInterface $logger = null,
        ?ImageDownloader $imageDownloader = null,
        ?CategoryV2Validator $categoryV2Validator = null,
        ?CategoryImageDownloader $categoryImageDownloader = null,
        ?GalleryContentAdopter $galleryContentAdopter = null,
        ?ProductContentLanguageCatalog $productContentLanguageCatalog = null,
        ?ProductV3Validator $productV3Validator = null,
        ?ProductV4Validator $productV4Validator = null,
        ?FeatureV5Validator $featureV5Validator = null,
        ?ProductV5Validator $productV5Validator = null,
        ?FeatureLinkAdoptionTransaction $featureLinkAdoptionTransaction = null,
        ?FeatureLifecycleLock $featureLifecycleLock = null,
        ?FullMediaPreview $mediaPreview = null,
        ?PreviewFeatureLifecycle $previewLifecycle = null,
        ?VariantInventoryStore $variantInventoryStore = null,
        ?ProductDocumentStore $productDocumentStore = null
    ) {
        if ($mediaPreview !== null
            && (!$entityFactory instanceof PreviewEntityFactory || $featureLinkAdoptionTransaction !== null
                || $featureLifecycleLock !== null || $logger !== null)) {
            throw new \InvalidArgumentException('Offline media planning requires an isolated apply');
        }
        if ($previewLifecycle !== null && $mediaPreview === null) {
            throw new \InvalidArgumentException('Offline lifecycle requires an isolated apply');
        }
        $this->mediaPreview = $mediaPreview;
        $this->variantInventoryStore = $variantInventoryStore;
        $this->featureLinkAdoptionTransaction = $featureLinkAdoptionTransaction ?? $previewLifecycle;
        $this->featureLifecycleLock = $featureLifecycleLock ?? $previewLifecycle;
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->reader = $reader;
        $this->languages = $languages;
        $this->logger = $logger;
        $this->imageDownloader = $imageDownloader;
        $this->categoryV2Validator = $categoryV2Validator ?? new CategoryV2Validator();
        $this->productSourceIdentityValidator = new ProductSourceIdentityValidator();
        $this->productContentLanguageCatalog = $productContentLanguageCatalog
            ?? ($languages !== null ? new ProductContentLanguageCatalog($languages) : null);
        $this->productV3Validator = $productV3Validator ?? new ProductV3Validator();
        $this->productV4Validator = $productV4Validator ?? new ProductV4Validator($this->productV3Validator);
        $this->featureV5Validator = $featureV5Validator ?? new FeatureV5Validator();
        $this->productV5Validator = $productV5Validator ?? new ProductV5Validator($this->productV4Validator);
        $this->productDocumentStore = $productDocumentStore;
        $this->categoryImageDownloader = $categoryImageDownloader;
        $this->galleryContentAdopter = $galleryContentAdopter;
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
        ApplyStats $stats,
        ?int $schemaMajor = null,
        ?string $sourceInstance = null
    ): string {
        // Полный проход капа тика не знает: новая версия/force-reapply переигрывают и исчерпанный хвост.
        $this->imageTickRetryCap = null;
        $this->v5Features = [];
        $this->v5FeaturePlan = [];
        $this->v5OptionPlan = [];
        $this->v5OptionCanonical = [];
        $this->v5ParentCanonical = [];
        $this->v5RequiredOptionKeys = [];
        $this->v5MaterializedOptionKeys = [];
        $this->v5SelectedProductPairs = [];
        $this->v5ProtectedProductIdentities = [];
        $this->v5SeenHolds = [];
        $this->v5ProtectedValueIds = [];
        $this->v5CurrentProductExternal = '';
        $this->v5AdoptionPlan = null;
        $this->schemaMajor = $schemaMajor ?? $this->schemaMajorFromManifest($manifest);
        $this->manifestDocuments = [];
        $this->legacyDocumentsWarned = false;
        if ($this->schemaMajor >= 4) {
            foreach ((array) ($manifest['files'] ?? []) as $file) {
                if (($file['role'] ?? null) === 'product_document') {
                    $this->manifestDocuments[(string) $file['sha256']] = (int) $file['bytes'];
                }
            }
        }
        if (!in_array($this->schemaMajor, Contract::SNAPSHOT_SCHEMA_MAJORS, true)) {
            throw new ManifestException('Неподдерживаемый schema major перед apply');
        }
        $this->documentsMode = $this->schemaMajor >= 4 ? Contract::productDocumentsMode($manifest) : null;
        if ($this->documentsMode !== null && $this->productDocumentStore === null && $this->mediaPreview === null) {
            throw new ManifestException('Для product_documents недоступно хранилище документов');
        }
        $this->inventoryVariantProducts = [];
        $this->snapshotVariantIds = [];
        $config = $this->config();
        $this->sourceInstance = $sourceInstance ?? (string) ($config[Contract::SETTINGS_SOURCE_INSTANCE_FIELD] ?? '');
        $mode = (string) ($manifest['sync_mode'] ?? Contract::SYNC_MODE_FULL);
        $fullV5 = $this->schemaMajor === 5 && $mode === Contract::SYNC_MODE_FULL;
        $channelCode = (string) ($config['channel_code'] ?? '');
        $scopePlan = null;
        $scopePlanError = null;
        try {
            $scopePlan = $this->loadV5AdoptionPlan($config, $channelCode);
        } catch (ManifestException $e) {
            if ($fullV5) {
                throw $e;
            }
            $scopePlanError = $e;
        }
        if ($fullV5) {
            $this->v5AdoptionPlan = $scopePlan;
        }
        $lineageEvents = (new FeatureLineageValidator())->events($manifest);
        $lineageJournal = [];
        $lifecycleIntent = null;
        $lifecycleLockHeld = false;
        $this->assertFeatureLifecycleScope($manifest, $scopePlan);
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)
            && !Contract::isValidSourceInstance($this->sourceInstance)) {
            throw new ManifestException('Для snapshot v2/v3 обязателен безопасный source_instance');
        }
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor) && $this->languages === null) {
            throw new ManifestException('Для snapshot v2/v3 недоступен сервис Languages');
        }
        if ($this->schemaMajor >= 3) {
            $this->preflightV3Products($manifest, $stagingDir);
        }
        $restoreLanguageId = $this->schemaMajor >= 3 ? (int) $this->languages->getLangId() : null;
        try {
            $this->boot();
            // All native applies share this catalog lock, even if an operator changes the configured scope.
            if ($this->featureLifecycleLock !== null) {
                $this->featureLifecycleLock->acquire();
                $lifecycleLockHeld = true;
            }
            $this->assertInventoryOwnership();
            $scopeKey = hash('sha256', $this->sourceInstance . '|' . $channelCode);
            $fences = $this->map->featureLifecycleFenceRows();
            foreach ($fences as $fence) {
                if ((string) $fence->external_id !== $scopeKey) {
                    throw new ManifestException('v5 feature lifecycle catalog is fenced for another configured scope');
                }
            }
            if ($fences !== [] || ($scopePlan !== null && $scopePlan->featureLifecycle())) {
                if ($this->mediaPreview !== null) {
                    $this->mediaPreview->setPhase('feature_lifecycle');
                }
                if ($scopePlanError !== null) {
                    throw $scopePlanError;
                }
                if ($scopePlan === null || !$scopePlan->featureLifecycle() || !$lifecycleLockHeld) {
                    throw new ManifestException('v5 feature lifecycle catalog requires its reviewed scope and lock service');
                }
                $lifecycleIntent = $this->preflightFeatureLifecycleIntent($manifest, $channelCode, $scopePlan);
            }
            if ($fullV5) {
                if ($this->mediaPreview !== null) {
                    $this->mediaPreview->setPhase('feature_lineage');
                }
                $lineageJournal = $this->preflightFeatureLineageJournal($lineageEvents, $channelCode);
                if ($lineageEvents !== []) {
                    $resolver = new FeatureLineageResolver($this->map, $this->featuresEntity,
                        $this->featuresValuesEntity, $this->languages, $this->productContentLanguageIds);
                    $this->v5AdoptionPlan = $resolver->resolve($lineageEvents, $this->v5Features, $this->v5AdoptionPlan);
                }
                if ($this->mediaPreview !== null) {
                    $this->mediaPreview->setPhase('feature_ownership');
                }
                $this->preflightV5Ownership();
            }

            // --- Bind-фаза: карта пуста (product+variant) И каталог непуст → связывание, каталог не пишется ---
            if ($this->shouldBind()) {
                if ($this->mediaPreview !== null) {
                    $this->mediaPreview->setPhase('bind');
                }
                return $this->runBind($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
            }

            // --- Currency-гейт: fail-closed ВСЕГО прогона ДО применения (full и price_stock пишут цену) ---
            try {
                $this->resolveCurrency($manifest);
            } catch (CurrencyNotMappedException $e) {
                $this->error('CoreSync apply: ' . $e->getMessage());

                return Contract::STATUS_FAILED;
            }

            $this->recordFeatureLifecycleIntent($lifecycleIntent);
            $this->prepareProductDocumentStore($manifest, $stagingDir, $stats, $checkpoints);
            if ($mode === Contract::SYNC_MODE_PRICE_STOCK) {
                $status = $this->applyPriceStock($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
                $this->finishProductDocumentStore($status, $stats);

                return $status;
            }


            $this->reserveFeatureLineageEvents($lineageJournal);
            $status = $this->applyFull($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
            if ($status === Contract::STATUS_APPLIED) {
                $this->completeFeatureLineageEvents($lineageJournal);
            }
            $this->finishProductDocumentStore($status, $stats);
            return $status;
        } finally {
            try {
                if ($lifecycleLockHeld) {
                    $this->featureLifecycleLock->release();
                }
            } finally {
                if ($restoreLanguageId !== null) {
                    $this->languages->setLangId($restoreLanguageId);
                }
            }
        }
    }

    /**
     * Своё хранилище документов, шаг 1 (store: нужные файлы пакета в хранилище раньше любой
     * привязки) или полная уборка (disabled). Зовётся после проверки всего пакета и до записи
     * первого товара. Нужны только ready-документы товаров, которые есть на витрине: в full это все
     * строки снимка, в price_stock только связанные товары.
     *
     * @param array<string, mixed> $manifest
     */
    private function prepareProductDocumentStore(
        array $manifest,
        string $stagingDir,
        ApplyStats $stats,
        FileCheckpointStore $checkpoints
    ): void {
        if ($this->documentsMode === null || $this->mediaPreview !== null) {
            return;
        }
        if ($this->documentsMode !== Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            $this->productDocumentStore->prepare($this->documentsMode, $manifest, $stagingDir, $stats);

            return;
        }
        $allLinked = ($manifest['sync_mode'] ?? Contract::SYNC_MODE_FULL) !== Contract::SYNC_MODE_PRICE_STOCK;
        $needed = $this->storeDocumentRows($manifest, $stagingDir, $allLinked)['needed'];
        $available = $this->productDocumentStore->prepare($this->documentsMode, $manifest, $stagingDir, $stats, $needed);
        $this->markHandledDocumentCheckpoints($manifest, $needed, $available, $checkpoints);
    }

    /**
     * Файлы документов пакета store, нужные витрине (ready-документы товаров, которые на ней есть).
     * До применения: full считает нужными все строки снимка, price_stock только связанные товары.
     * Строки берутся из staging этой версии (ndjson уже скачан и сверен).
     *
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>> спеки manifest.files
     */
    public function neededDocumentFiles(array $manifest, string $stagingDir): array
    {
        if ($this->productDocumentStore === null
            || Contract::productDocumentsMode($manifest) !== Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            return [];
        }
        $allLinked = ($manifest['sync_mode'] ?? Contract::SYNC_MODE_FULL) !== Contract::SYNC_MODE_PRICE_STOCK;

        return $this->documentFileSpecs($manifest, $this->storeDocumentRows($manifest, $stagingDir, $allLinked)['needed']);
    }

    /**
     * Отложенные файлы применённой версии store: нужны товарам, которые на витрине есть (по карте),
     * и ещё не лежат в хранилище. Файлы товаров, которых на витрине нет, сюда не попадают никогда:
     * иначе каждый тик качал бы их заново, а уборка удаляла.
     *
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>> спеки manifest.files
     */
    public function deferredDocumentFiles(array $manifest, string $stagingDir): array
    {
        if ($this->productDocumentStore === null
            || Contract::productDocumentsMode($manifest) !== Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            return [];
        }
        $unstored = [];
        foreach ($this->documentFileSpecs($manifest, null) as $file) {
            if (!$this->productDocumentStore->hasStoredFile((string) $file['sha256'], (int) $file['bytes'])) {
                $unstored[(string) $file['sha256']] = true;
            }
        }
        if ($unstored === []) {
            return [];
        }
        $needed = array_intersect_key($this->storeDocumentRows($manifest, $stagingDir, false)['needed'], $unstored);

        return $this->documentFileSpecs($manifest, $needed);
    }

    /**
     * Догон документов уже применённой версии store: доехавшие нужные файлы переносятся в
     * хранилище, документы связанных товаров довязываются по строкам staging этой версии, затем
     * уборка. Товары, цены, остатки, картинки и карта не трогаются. Строки сверяются с манифестом
     * заново (sha256 файла staging и форма списка документов) до первой записи.
     *
     * @param array<string, mixed> $manifest уже применённый манифест этой версии
     */
    public function applyDeferredDocuments(
        array $manifest,
        string $stagingDir,
        ApplyStats $stats,
        int $schemaMajor,
        ?FileCheckpointStore $checkpoints = null
    ): void {
        if (!in_array($schemaMajor, [4, 5], true)
            || Contract::productDocumentsMode($manifest) !== Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            throw new ManifestException('Догон документов возможен только для store-манифеста V4/V5');
        }
        if ($this->productDocumentStore === null) {
            throw new ManifestException('Для product_documents недоступно хранилище документов');
        }
        $this->schemaMajor = $schemaMajor;
        $this->documentsMode = Contract::PRODUCT_DOCUMENTS_MODE_STORE;
        $read = $this->storeDocumentRows($manifest, $stagingDir, false);
        // Всё прочитано и проверено до первой записи: файлы, затем привязки, затем уборка.
        $available = $this->productDocumentStore->prepare(
            Contract::PRODUCT_DOCUMENTS_MODE_STORE, $manifest, $stagingDir, $stats, $read['needed']
        );
        foreach ($read['rows'] as [$externalId, $localId, $list]) {
            $this->productDocumentStore->reconcileProduct($externalId, $localId, $list, $stats);
        }
        $this->productDocumentStore->finish($stats);
        if ($checkpoints !== null) {
            $this->markHandledDocumentCheckpoints($manifest, $read['needed'], $available, $checkpoints);
        }
    }

    /**
     * Строки товаров store-пакета из staging: файл товаров сверяется с манифестом, список документов
     * проверяется формой store. Связанный товар: есть в карте с локальным id; при $allLinked (full до
     * применения) связанными считаются все строки снимка.
     *
     * @param array<string, mixed> $manifest
     * @return array{rows:list<array{0:string,1:int,2:array}>,needed:array<string,bool>}
     */
    private function storeDocumentRows(array $manifest, string $stagingDir, bool $allLinked): array
    {
        $documents = [];
        $specs = [];
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $specs[(string) ($file['name'] ?? '')] = $file;
            if (($file['role'] ?? null) === 'product_document') {
                $documents[(string) $file['sha256']] = (int) $file['bytes'];
            }
        }
        $map = new MapGateway($this->entityFactory->get(CoreSyncMapEntity::class));
        $rows = [];
        $needed = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($file['role'] !== Contract::ENTITY_PRODUCT) {
                continue;
            }
            $spec = $specs[$file['name']] ?? [];
            $path = rtrim($stagingDir, '/') . '/' . $file['name'];
            if (!StagedSnapshotFile::matches($path, strtolower((string) ($spec['sha256'] ?? '')), $spec)) {
                throw new ManifestException('Документы store: файл staging не совпадает с манифестом ' . $file['name']);
            }
            $read = $this->reader->each($path, function (array $line) use ($documents, $map, $allLinked, &$rows, &$needed): void {
                $list = $line['data']['documents'] ?? null;
                $this->productV4Validator->validateStoreDocumentList($list, $documents);
                $row = $map->find(Contract::ENTITY_PRODUCT, (string) ($line['external_id'] ?? ''));
                $localId = $row !== null ? (int) ($row->local_id ?? 0) : 0;
                if ($localId < 1 && !$allLinked) {
                    return;
                }
                if ($localId > 0) {
                    $rows[] = [(string) $line['external_id'], $localId, $list];
                }
                foreach ($list as $document) {
                    if ($document['state'] === Contract::PRODUCT_DOCUMENT_STATE_READY) {
                        $needed[(string) $document['sha256']] = true;
                    }
                }
            });
            if ($read['broken'] > 0) {
                throw new ManifestException('Документы store: битые строки в ' . $file['name']);
            }
        }

        return ['rows' => $rows, 'needed' => $needed];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, bool>|null $shas null - все файлы документов пакета
     * @return list<array<string, mixed>>
     */
    private function documentFileSpecs(array $manifest, ?array $shas): array
    {
        $files = [];
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (is_array($file) && ($file['role'] ?? null) === 'product_document'
                && ($shas === null || isset($shas[(string) ($file['sha256'] ?? '')]))) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Чекпоинт файла документа становится applied, когда staging ему больше не нужен: файл лежит
     * в хранилище (перенесён или был там) либо не нужен витрине (товара на ней нет). Отложенный
     * нужный файл остаётся pending: версия держит staging для догона. Документы старой формы
     * загрузчик не качает и сразу закрывает applied; verified PDF в staging бывает только у пакетов
     * старого пути до 1.9.1, их ротация держит.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, bool> $needed
     * @param array<string, bool> $available
     */
    private function markHandledDocumentCheckpoints(
        array $manifest,
        array $needed,
        array $available,
        FileCheckpointStore $checkpoints
    ): void {
        foreach ($this->documentFileSpecs($manifest, null) as $file) {
            $sha = (string) $file['sha256'];
            if (!isset($needed[$sha]) || isset($available[$sha])) {
                $checkpoints->setStatus((string) $file['name'], Contract::FILE_APPLIED);
            }
        }
    }

    /** Шаг 3: файлы без ссылок убираются только после записанных привязок прогона. */
    private function finishProductDocumentStore(string $status, ApplyStats $stats): void
    {
        if ($this->documentsMode !== Contract::PRODUCT_DOCUMENTS_MODE_STORE || $this->mediaPreview !== null
            || !in_array($status, [Contract::STATUS_APPLIED, Contract::STATUS_HELD], true)) {
            return;
        }
        $this->productDocumentStore->finish($stats);
    }

    private function loadV5AdoptionPlan(array $config, string $channelCode): ?V5AdoptionPlan
    {
        $path = (string) ($config['v5_adoption_plan_path'] ?? '');
        $digest = (string) ($config['v5_adoption_plan_sha256'] ?? '');
        if (($path === '') !== ($digest === '')) {
            throw new ManifestException('v5 adoption plan path and digest must be paired');
        }
        if ($path === '') {
            return null;
        }
        $plan = V5AdoptionPlan::load($path, $digest);
        $plan->assertContext($this->sourceInstance, $channelCode);
        return $plan;
    }

    private function assertFeatureLifecycleScope(array $manifest, ?V5AdoptionPlan $scopePlan): void
    {
        $scope = $scopePlan !== null && $scopePlan->featureLifecycle();
        if ($scope && $this->schemaMajor !== 5) {
            throw new ManifestException('feature lifecycle channel cannot downgrade from snapshot schema v5');
        }
        if (($scope && ($manifest['channel_code'] ?? null) !== $scopePlan->manifestChannelCode())
            || (array_key_exists('feature_lineage', $manifest)
                && (!$scope || $manifest['feature_lineage']['source_instance'] !== $this->sourceInstance))) {
            throw new ManifestException('v5 feature lineage source/channel or lifecycle scope mismatch');
        }
    }

    /**
     * Догнать только durable pending/failed-картинки без повторного full/bind/apply-прохода.
     *
     * @param callable():bool $isCancelled кооперативная отмена между товарами/категориями
     * @return string Contract::STATUS_CANCELLED | STATUS_FAILED | STATUS_APPLIED
     */
    public function applyPendingImages(callable $isCancelled, ApplyStats $stats, int $schemaMajor = 1): string
    {
        // Путь тика: строки failed с исчерпанными попытками пропускаются обеими фазами.
        $this->imageTickRetryCap = Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS;
        $this->schemaMajor = $schemaMajor;
        if (!in_array($this->schemaMajor, Contract::SNAPSHOT_SCHEMA_MAJORS, true)) {
            throw new ManifestException('Неподдерживаемый schema major перед добором картинок');
        }
        $this->boot(false);

        $productImagesStatus = $this->runImagesPhase($isCancelled, $stats);
        if ($productImagesStatus === Contract::STATUS_CANCELLED) {
            return $productImagesStatus;
        }
        $categoryImagesStatus = $this->runCategoryImagesPhase($isCancelled, $stats);

        return $categoryImagesStatus ?? $productImagesStatus;
    }

    // ================================================================ full

    /**
     * Validate every v3 product row before boot() resolves writable entities or a bind marker can
     * be created. Product files are intentionally read twice: this first pass is the all-or-nothing
     * boundary, the later pass remains streaming apply.
     *
     * @param array<string, mixed> $manifest
     */
    private function preflightV3Products(array $manifest, string $stagingDir): void
    {
        if ($this->productContentLanguageCatalog === null) {
            throw new ManifestException('Для snapshot v3 недоступен каталог языков контента');
        }
        $this->productContentLanguageIds = $this->productContentLanguageCatalog->localIdsByHrefLang();

        $manifestLanguages = $manifest['product_content_languages'] ?? null;
        if (!is_array($manifestLanguages)
            || !Contract::isList($manifestLanguages)
            || $manifestLanguages === []) {
            throw new ManifestException('product_content_languages v3 должен быть непустым списком');
        }
        $canonicalLanguages = [];
        foreach ($manifestLanguages as $language) {
            if (!is_string($language)
                || !isset($this->productContentLanguageIds[$language])
                || in_array($language, $canonicalLanguages, true)) {
                throw new ManifestException('product_content_languages v3 не совпадает с local href_lang catalog');
            }
            $canonicalLanguages[] = $language;
        }
        $sortedLanguages = $canonicalLanguages;
        sort($sortedLanguages, SORT_STRING);
        if ($sortedLanguages !== $canonicalLanguages) {
            throw new ManifestException('product_content_languages v3 должен быть отсортирован');
        }

        $this->manifestLanguage = is_string($manifest['language'] ?? null)
            ? (string) $manifest['language']
            : '';
        if ($this->manifestLanguage === '' || !in_array($this->manifestLanguage, $canonicalLanguages, true)) {
            throw new ManifestException('Язык канала отсутствует в product_content_languages v3');
        }

        if ($this->schemaMajor === 5) {
            if ($this->v5AdoptionPlan !== null) {
                $this->v5AdoptionPlan->assertLanguageScope($canonicalLanguages, array_keys($this->productContentLanguageIds));
            }
            $this->v5LinkLanguageIds = array_intersect_key(
                $this->productContentLanguageIds,
                array_fill_keys($canonicalLanguages, true)
            );
            $this->preflightV5Features($manifest, $stagingDir, $canonicalLanguages);
        }

        // Hold принимается в обоих режимах: в full по full_variant_price_hold, в price_stock по
        // price_stock_variant_price_hold (ядро согласует свою capability на режим).
        $allowVariantPriceHold = in_array(
            $manifest['sync_mode'] ?? Contract::SYNC_MODE_FULL,
            [Contract::SYNC_MODE_FULL, Contract::SYNC_MODE_PRICE_STOCK],
            true
        );
        $seenExternalIds = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($file['role'] !== Contract::ENTITY_PRODUCT) {
                continue;
            }
            $path = rtrim($stagingDir, '/') . '/' . $file['name'];
            if (!is_file($path)) {
                if ($this->schemaMajor === 5) {
                    throw new ManifestException('product v5 staging file missing');
                }
                continue;
            }
            $read = $this->reader->each(
                $path,
                function (array $line, int $lineNo, string $rawJson) use (
                    $canonicalLanguages,
                    $allowVariantPriceHold,
                    &$seenExternalIds
                ): void {
                    if ($this->schemaMajor === 5 && $this->mediaPreview === null
                        && (!$this->settings->get(VariantInventoryStore::READY) || $this->variantInventoryStore === null)) {
                        foreach ((array) ($line['data']['variants'] ?? []) as $variant) {
                            if (is_array($variant) && array_key_exists('inventory', $variant)) {
                                throw new ManifestException('Variant inventory storage has not been upgraded');
                            }
                        }
                    }
                    $validated = $this->schemaMajor === 5
                        ? $this->productV5Validator->validate(
                            $line,
                            $canonicalLanguages,
                            $this->productContentLanguageIds,
                            $this->manifestDocuments,
                            $rawJson,
                            $allowVariantPriceHold,
                            $this->documentsMode
                        )
                        : ($this->schemaMajor === 4
                        ? $this->productV4Validator->validate(
                            $line,
                            $canonicalLanguages,
                            $this->productContentLanguageIds,
                            $this->manifestDocuments,
                            $rawJson,
                            $allowVariantPriceHold,
                            $this->documentsMode
                        )
                        : $this->productV3Validator->validate(
                            $line,
                            $canonicalLanguages,
                            $this->productContentLanguageIds,
                            $rawJson
                        ));
                    if ($this->schemaMajor === 5) {
                        $this->assertV5ProductPairs(
                            (array) $validated['data']['feature_values'],
                            (string) $validated['external_id']
                        );
                    }
                    $externalId = (string) $validated['external_id'];
                    if (isset($seenExternalIds[$externalId])) {
                        throw new ManifestException('product v3 external_id повторяется в snapshot');
                    }
                    $seenExternalIds[$externalId] = true;

                    $data = (array) $validated['data'];
                    foreach ($data['variants'] as $variant) {
                        $variantExternal = (string) $variant['external_id'];
                        $this->snapshotVariantIds[$variantExternal] = true;
                        if (!array_key_exists('inventory', $variant)) {
                            continue;
                        }
                        if (isset($this->inventoryVariantProducts[$variantExternal])) {
                            throw new ManifestException('Duplicate variant inventory external ID: ' . $variantExternal);
                        }
                        $this->inventoryVariantProducts[$variantExternal] = $externalId;
                    }

                    if ($this->productSourceIdentityValidator->hasIdentifiedProduct($data)) {
                        try {
                            $identity = $this->productSourceIdentityValidator->validate($data, $this->sourceInstance);
                        } catch (\InvalidArgumentException $e) {
                            throw new ManifestException('product v3 source_identity: ' . $e->getMessage());
                        }
                        if ($this->v5AdoptionPlan !== null && $this->v5AdoptionPlan->languages() === ['ru']
                            && isset($this->v5AdoptionPlan->holds()[$externalId])) {
                            $this->v5ProtectedProductIdentities[$externalId] = $identity;
                        }
                    }
                }
            );
            if ($read['broken'] > 0) {
                throw new ManifestException('product v3 файл содержит битые строки');
            }
        }
        if ($this->schemaMajor === 5 && $this->v5AdoptionPlan !== null
            && !$this->v5AdoptionPlan->featureLifecycle()) {
            foreach ($this->v5AdoptionPlan->holds() as $productExternal => $holds) {
                foreach ($holds as $key => $hold) {
                    if (!isset($this->v5SeenHolds[$productExternal . '|' . $key])) {
                        throw new ManifestException('v5 adoption plan hold not found in validated products');
                    }
                }
            }
        }
    }

    /** Validate all v5 feature identities before any dictionary, bind or product write. */
    private function preflightV5Features(array $manifest, string $stagingDir, array $languages): void
    {
        $seenOptions = [];
        foreach ($this->orderedFiles($manifest) as $file) {
            if ($file['role'] !== Contract::ENTITY_FEATURE) { continue; }
            $path = rtrim($stagingDir, '/') . '/' . $file['name'];
            if (!is_file($path)) { throw new ManifestException('feature v5 staging file missing'); }
            $read = $this->reader->each($path, function (array $line, int $lineNo, string $rawJson) use ($languages, &$seenOptions): void {
                $feature = $this->featureV5Validator->validate($line, $languages, $this->manifestLanguage, $rawJson);
                $external = (string) $feature['external_id'];
                if (isset($this->v5Features[$external])) { throw new ManifestException('feature v5 duplicate external_id'); }
                $this->v5Features[$external] = $feature;
                foreach ($feature['data']['option_records'] as $option) {
                    $optionId = (string) $option['external_id'];
                    if (isset($seenOptions[$optionId])) { throw new ManifestException('feature v5 option ID reused across features'); }
                    $seenOptions[$optionId] = true;
                }
            });
            if ($read['broken'] !== 0) { throw new ManifestException('feature v5 file has broken rows'); }
        }
        if ($this->v5AdoptionPlan !== null) {
            $this->assertV5PolicySourceRows();
        }
    }

    /** Every curated product value must name one exact option in its feature's v5 dictionary. */
    private function assertV5ProductPairs(array $pairs, string $productExternal): void
    {
        $this->v5SelectedProductPairs[$productExternal] = $pairs;
        foreach ($pairs as $pair) {
            $featureExternal = (string) $pair['feature_external_id'];
            $feature = $this->v5Features[$featureExternal] ?? null;
            if ($feature === null) { throw new ManifestException('product v5 references missing feature'); }
            if (strpos($featureExternal, 'attr-') === 0) { continue; }
            $matched = false;
            foreach ($feature['data']['option_records'] as $option) {
                if ($option['external_id'] === $pair['option_external_id'] && $option['value'] === $pair['value']) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) { throw new ManifestException('product v5 references missing or mismatched option'); }
            $key = $featureExternal . ':' . (string) $pair['option_external_id'];
            $hold = $this->v5AdoptionPlan !== null
                ? $this->v5AdoptionPlan->hold($productExternal, $key) : null;
            if ($hold !== null) {
                if ($hold['expected_value'] !== $pair['value']) {
                    throw new ManifestException('v5 adoption plan held source value changed');
                }
                $this->v5SeenHolds[$productExternal . '|' . $key] = true;
                continue;
            }
            $this->v5RequiredOptionKeys[$key] = true;
        }
    }

    /** Plan entries must bind real, unchanged source IDs; unselected options stay validated. */
    private function preflightFeatureLifecycleIntent(
        array $manifest,
        string $channelCode,
        ?V5AdoptionPlan $scopePlan = null
    ): ?array {
        $scopePlan = $scopePlan ?? $this->v5AdoptionPlan;
        $this->assertFeatureLifecycleScope($manifest, $scopePlan);
        $key = hash('sha256', $this->sourceInstance . '|' . $channelCode);
        $saved = $this->map->find(Contract::ENTITY_FEATURE_LIFECYCLE_FENCE, $key);
        if ($saved === null && ($scopePlan === null || !$scopePlan->featureLifecycle())) {
            return null;
        }
        if ($this->schemaMajor !== 5) {
            throw new ManifestException('feature lifecycle channel cannot downgrade from snapshot schema v5');
        }
        $version = $manifest['snapshot_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new ManifestException('v5 feature lifecycle requires a snapshot version');
        }
        $digest = rtrim(strtr(base64_encode(hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), true)), '+/', '-_'), '=');
        $intent = $version . ':' . $digest;
        if ($saved !== null) {
            if ((int) $saved->local_id !== 1 || !preg_match('/^([1-9][0-9]*):[A-Za-z0-9_-]{43}$/D',
                (string) $saved->applied_hash, $matches)) {
                throw new ManifestException('v5 feature lifecycle version intent is corrupt');
            }
            $previous = (int) $matches[1];
            if ($version < $previous) {
                throw new ManifestException('v5 feature lifecycle refuses snapshot older than its durable version intent');
            }
            if ($version === $previous && !hash_equals((string) $saved->applied_hash, $intent)) {
                throw new ManifestException('v5 feature lifecycle same-version manifest changed');
            }
            if ($version === $previous) {
                return null;
            }
        }
        return ['key'=>$key, 'hash'=>$intent, 'row'=>$saved];
    }

    private function recordFeatureLifecycleIntent(?array $intent): void
    {
        if ($intent === null) {
            return;
        }
        if ($intent['row'] === null) {
            $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_LIFECYCLE_FENCE, $intent['key'], 1, $intent['hash']);
        } else {
            $this->map->recordUpdateChecked($intent['row'], 1, $intent['hash']);
        }
    }

    private function preflightFeatureLineageJournal(array $events, string $channelCode): array
    {
        $pending = [];
        foreach ($events as $event) {
            $key = hash('sha256', $event['id']);
            $wireChannel = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->manifestChannelCode() : null;
            $event['hash'] = hash('sha256', $this->sourceInstance . '|' . $channelCode . '|' . $wireChannel . '|'
                . FeatureLineageValidator::eventHash($event));
            $pending[$key] = $event;
        }
        foreach ($this->map->featureLineageEventRows() as $row) {
            $key = (string) $row->external_id;
            if (!isset($pending[$key]) || (int) $row->local_id !== 1
                || !in_array($row->image_state ?? null, ['pending', 'applied'], true)
                || !hash_equals((string) $row->applied_hash, $pending[$key]['hash'])) {
                throw new ManifestException('v5 feature lineage omits or changes a durable event');
            }
            if ($row->image_state === 'applied') {
                unset($pending[$key]);
            } else {
                $pending[$key]['reserved'] = true;
            }
        }
        return $pending;
    }

    private function reserveFeatureLineageEvents(array $events): void
    {
        foreach ($events as $key => $event) {
            if (empty($event['reserved'])) {
                $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_LINEAGE_EVENT, $key, 1, $event['hash'], 'pending');
                $saved = $this->map->find(Contract::ENTITY_FEATURE_LINEAGE_EVENT, $key);
                if ($saved === null || ($saved->image_state ?? null) !== 'pending') {
                    throw new ManifestException('v5 feature lineage reservation readback failed');
                }
            }
        }
    }

    private function completeFeatureLineageEvents(array $events): void
    {
        foreach ($events as $key => $event) {
            $saved = $this->map->find(Contract::ENTITY_FEATURE_LINEAGE_EVENT, $key);
            if ($saved === null || ($saved->image_state ?? null) !== 'pending'
                || !hash_equals((string) $saved->applied_hash, $event['hash'])) {
                throw new ManifestException('v5 feature lineage pending event drift');
            }
            $this->map->recordUpdateChecked($saved, 1, $event['hash'], 'applied');
            $applied = $this->map->find(Contract::ENTITY_FEATURE_LINEAGE_EVENT, $key);
            if ($applied === null || ($applied->image_state ?? null) !== 'applied') {
                throw new ManifestException('v5 feature lineage completion readback failed');
            }
            $this->info('CoreSync feature lineage ' . $event['action'] . ' event ' . $event['id']
                . ' effective at snapshot ' . $event['effective_version']);
        }
    }

    private function featureConflict(ManifestException $error, array $context): ManifestException
    {
        if ($error instanceof FeatureLifecycleConflict
            || ($this->mediaPreview === null && ($this->v5AdoptionPlan === null || !$this->v5AdoptionPlan->featureLifecycle()))) {
            return $error;
        }
        return new FeatureLifecycleConflict($error->getMessage(), $context, $error);
    }

    private function assertV5PolicySourceRows(): void
    {
        $lifecycle = $this->v5AdoptionPlan->featureLifecycle();
        foreach ($this->v5AdoptionPlan->parents() as $external => $policy) {
            $line = $this->v5Features[$external] ?? null;
            if ($line === null) {
                if ($lifecycle) { continue; }
                throw $this->featureConflict(new ManifestException('v5 adoption plan unknown source parent'),
                    ['source_feature_id' => (string) $external, 'local_feature_id' => $policy['local_id']]);
            }
            $data = $line['data'];
            $labels = [];
            foreach ($data['translations'] as $translation) {
                $labels[$translation['language']] = $translation['name'];
            }
            $expected = $policy['expected_source'];
            $labelsMatch = true;
            foreach ($this->v5AdoptionPlan->languages() as $language) {
                if (($labels[$language] ?? null) !== $expected[$language]) {
                    $labelsMatch = false;
                }
            }
            if ((!$lifecycle && !$labelsMatch)
                || $data['type'] !== $expected['type'] || $data['unit'] !== $expected['unit']) {
                throw $this->featureConflict(new ManifestException('v5 adoption plan source parent drift'),
                    ['source_feature_id'=>(string) $external, 'local_feature_id'=>$policy['local_id']]);
            }
        }
        foreach ($this->v5AdoptionPlan->options() as $key => $policy) {
            list($featureExternal, $optionExternal) = explode(':', $key, 2);
            $line = $this->v5Features[$featureExternal] ?? null;
            if ($line === null) {
                if ($lifecycle) { continue; }
                throw new ManifestException('v5 adoption plan unknown source option parent');
            }
            $found = false;
            foreach ($line['data']['option_records'] as $option) {
                if ((string) $option['external_id'] !== $optionExternal) { continue; }
                $labels = [];
                foreach ($option['translations'] as $translation) {
                    $labels[$translation['language']] = $translation['value'];
                }
                if (($labels['ru'] ?? null) !== $policy['expected_source']['ru']
                    || ($labels['uk'] ?? null) !== $policy['expected_source']['uk']) {
                    throw $this->featureConflict(new ManifestException('v5 adoption plan source option drift'),
                        ['source_feature_id'=>$featureExternal, 'source_option_key'=>$key,
                            'local_value_id'=>$policy['local_id'] ?? null]);
                }
                $found = true;
                break;
            }
            if (!$found && !$lifecycle) { throw new ManifestException('v5 adoption plan unknown source option'); }
        }
    }

    /** Only declared, equivalent source parents may own one first-created physical row. */
    private function preflightV5NewParentGroups(): array
    {
        $resolved = [];
        $reservedUrls = [];
        foreach ($this->v5AdoptionPlan->parentCreateGroups() as $group => $members) {
            $this->v5ConflictContext = ['create_group'=>$group, 'source_feature_ids'=>$members];
            $active = array_filter($members, function ($source) {
                return isset($this->v5Features[$source]);
            });
            $lifecycle = $this->v5AdoptionPlan->featureLifecycle();
            if ($lifecycle && $active === []) { continue; }
            $canonical = $members[0];
            $policy = $this->v5AdoptionPlan->parent($canonical);
            $slug = Translit::translitAlpha($policy['display']['ru']);
            if ($slug === '') { throw new ManifestException('v5 adoption new-parent URL empty'); }
            if (isset($reservedUrls[$slug])) {
                throw new ManifestException('v5 adoption new-parent groups share physical URL');
            }
            $reservedUrls[$slug] = true;
            $filterable = null;
            $localId = 0;
            foreach ($members as $source) {
                $line = $this->v5Features[$source] ?? null;
                if ($line === null && $lifecycle) { continue; }
                if ($line === null || (!$lifecycle && $line['data']['name'] !== $policy['expected_source']['ru'])) {
                    throw new ManifestException('v5 adoption new-parent source member absent/drift');
                }
                $actualFilterable = !empty($line['data']['filterable']);
                $memberPolicy = $this->v5AdoptionPlan->parent($source);
                $resolution = $memberPolicy['filterable_resolution'] ?? null;
                if ($resolution !== null) {
                    if ($actualFilterable !== $resolution['expected_source']) {
                        throw new ManifestException('v5 adoption new-parent source filter drift');
                    }
                    $filterable = $resolution['target'];
                } else {
                    if ($filterable !== null && $filterable !== $actualFilterable) {
                        throw new ManifestException('v5 adoption new-parent filter semantics conflict');
                    }
                    $filterable = $actualFilterable;
                }
                $this->v5ParentCanonical[$source] = $canonical;
                $map = $this->map->find(Contract::ENTITY_FEATURE, $source);
                if ($map === null) { continue; }
                $mappedId = (int) ($map->local_id ?? 0);
                if ($mappedId < 1 || ($localId > 0 && $mappedId !== $localId)) {
                    throw new ManifestException('v5 adoption new-parent maps disagree');
                }
                $localId = $mappedId;
                $this->v5ConflictContext['local_feature_id'] = $localId;
            }
            if ($localId === 0) {
                if (count($active) !== count($members)) {
                    throw new ManifestException('v5 adoption cannot create a partially present parent group');
                }
                if ($this->exactV5Rows($this->featuresEntity,
                    ['name'=>$policy['display']['ru']], 'name', $policy['display']['ru']) !== []
                    || $this->map->findEntityRows($this->featuresEntity, ['url'=>$slug]) !== []) {
                    throw new ManifestException('v5 adoption new-parent would claim manual/foreign target');
                }
            } else {
                $physical = $this->map->findEntityOne($this->featuresEntity, ['id'=>$localId]);
                if ($physical === false || (string) ($physical->external_id ?? '') !== $canonical
                    || (string) ($physical->url ?? '') !== $slug
                    || (int) ($physical->in_filter ?? -1) !== (int) $filterable
                    || !$this->policyTargetDisplayCurrent(
                        $this->featuresEntity, $localId, 'name', $policy['display'])) {
                    throw new ManifestException('v5 adoption new-parent physical identity drift');
                }
                $owners = $this->map->findByLocalId(Contract::ENTITY_FEATURE, $localId);
                if ($owners === []) { throw new ManifestException('v5 adoption new-parent unowned target'); }
                foreach ($owners as $owner) {
                    if (!in_array((string) $owner->external_id, $members, true)) {
                        throw new ManifestException('v5 adoption new-parent unlisted owner');
                    }
                }
            }
            $resolved[$group] = $localId;
        }
        return $resolved;
    }

    /** Read-only default-language resolution; a single conflict aborts before apply writes anything. */
    private function preflightV5Ownership(): void
    {
        $this->v5FeaturePlan = [];
        $this->v5OptionPlan = [];
        $this->v5OptionCanonical = [];
        $this->v5ParentCanonical = [];
        $this->v5MaterializedOptionKeys = [];
        $this->v5ConflictContext = ['stage'=>'feature_ownership'];
        $previous = (int) $this->languages->getLangId();
        $this->languages->setLangId($this->productContentLanguageIds[$this->manifestLanguage]);
        try {
            if ($this->v5AdoptionPlan !== null) { $this->assertV5PolicySemanticGroups(); }
            $newParentIds = $this->v5AdoptionPlan !== null ? $this->preflightV5NewParentGroups() : [];
            $sharedOptionGroups = $this->v5AdoptionPlan !== null
                ? $this->v5AdoptionPlan->sharedOptionCreateGroups() : [];
        $claimedValues = [];
        $fingerprints = [];
        $canonical = [];
        $groupKeys = [];
        $groupLocalIds = [];
        $reservedParentCreates = [];
        $reservedOptionCreates = [];
        foreach ($this->v5Features as $featureExternal => $line) {
            if (strpos((string) $featureExternal, 'attr-') === 0) { continue; }
            foreach ($line['data']['option_records'] as $option) {
                $key = $featureExternal . ':' . $option['external_id'];
                if (!isset($this->v5RequiredOptionKeys[$key])
                    && $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key) === null) {
                    continue;
                }
                $this->v5MaterializedOptionKeys[$key] = true;
                $policy = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($key) : null;
                if ($policy !== null && ($policy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                    continue;
                }
                $fingerprint = $this->v5OptionFingerprint($option);
                if ($policy !== null) {
                    $fingerprint = hash('sha256', json_encode(
                        [$policy['display']['ru'], $policy['display']], JSON_UNESCAPED_UNICODE
                    ));
                }
                $fingerprints[$key] = $fingerprint;
                $scope = $policy !== null && isset($policy['share_create_group'])
                    ? 'shared:' . $policy['share_create_group'] : (string) $featureExternal;
                if (!isset($canonical[$scope][$fingerprint])) {
                    $canonical[$scope][$fingerprint] = $policy !== null && isset($policy['share_create_group'])
                        ? $sharedOptionGroups[$policy['share_create_group']][0] : $key;
                }
                $this->v5OptionCanonical[$key] = $canonical[$scope][$fingerprint];
                $groupKeys[$scope][$fingerprint][] = $key;
            }
        }
        if ($this->v5AdoptionPlan !== null) { $this->assertV5NewOptionGroupSourceCollisions(); }
            foreach ($this->v5Features as $featureExternal => $line) {
                if (strpos($featureExternal, 'attr-') === 0) { continue; }
                $featureMap = $this->map->find(Contract::ENTITY_FEATURE, (string) $featureExternal);
                $this->v5ConflictContext = ['source_feature_id'=>(string) $featureExternal,
                    'local_feature_id'=>$featureMap === null ? null : (int) $featureMap->local_id];
                if ($this->v5AdoptionPlan !== null && $this->v5AdoptionPlan->featureLifecycle()) {
                    $shape = $this->map->find(Contract::ENTITY_FEATURE_SOURCE_SHAPE, (string) $featureExternal);
                    if ($shape !== null && ($featureMap === null || (int) $shape->local_id !== (int) $featureMap->local_id
                        || !hash_equals((string) $shape->applied_hash, FeatureLineageResolver::shape($line['data'])))) {
                        throw new ManifestException('feature v5 durable source type/unit drift: ' . $featureExternal);
                    }
                }
                $parentPolicy = $this->v5AdoptionPlan !== null
                    ? $this->v5AdoptionPlan->parent((string) $featureExternal) : null;
                if ($parentPolicy !== null && isset($parentPolicy['create_group'])) {
                    $featureId = $newParentIds[$parentPolicy['create_group']];
                    $rows = $featureId > 0
                        ? $this->map->findEntityRows($this->featuresEntity, ['id'=>$featureId]) : [];
                } elseif ($parentPolicy !== null && $parentPolicy['local_id'] !== null) {
                    $this->v5ConflictContext['local_feature_id'] = (int) $parentPolicy['local_id'];
                    $featureId = $this->preflightPolicyFeature($parentPolicy, $featureMap);
                    $rows = $this->map->findEntityRows($this->featuresEntity, ['id'=>$featureId]);
                } elseif ($featureMap !== null) {
                    $featureId = (int) ($featureMap->local_id ?? 0);
                    $rows = $featureId > 0 ? $this->map->findEntityRows($this->featuresEntity, ['id'=>$featureId]) : [];
                    if (count($rows) !== 1) { throw new ManifestException('feature v5 mapped target missing'); }
                    foreach ($this->map->findByLocalId(Contract::ENTITY_FEATURE, $featureId) as $owner) {
                        if ((string) $owner->external_id !== (string) $featureExternal) {
                            $alias = $this->v5AdoptionPlan !== null
                                ? $this->v5AdoptionPlan->parent((string) $owner->external_id) : null;
                            if ($alias === null || $alias['local_id'] !== $featureId) {
                                throw new ManifestException('feature v5 mapped target also owned by another source ID');
                            }
                        }
                    }
                } else {
                    $name = $line['data']['name'];
                    $rows = $this->exactV5Rows($this->featuresEntity, ['name'=>$name], 'name', $name);
                    if ($this->v5AdoptionPlan !== null && $this->v5AdoptionPlan->featureLifecycle()
                        && ($rows !== [] || $this->map->findEntityRows(
                            $this->featuresEntity, ['url'=>Translit::translitAlpha($name)]
                        ) !== [])) {
                        throw new ManifestException('feature v5 new source has an unreviewed local field collision: '
                            . $featureExternal);
                    }
                    if (count($rows) > 1) { throw new ManifestException('feature v5 ambiguous name adoption'); }
                    $featureId = count($rows) === 1 ? (int) $rows[0]->id : 0;
                    if ($featureId > 0 && $this->map->findByLocalId(Contract::ENTITY_FEATURE, $featureId) !== []) {
                        throw new ManifestException('feature v5 local target already owned');
                    }
                }
                if ($parentPolicy === null && $featureId > 0 && !empty($rows[0]->external_id)
                    && (string) $rows[0]->external_id !== (string) $featureExternal) {
                    throw new ManifestException('feature v5 target external_id mismatch');
                }
                if ($featureId === 0) {
                    $name = $parentPolicy !== null ? $parentPolicy['display']['ru'] : $line['data']['name'];
                    $slug = Translit::translitAlpha($name);
                    $owner = $parentPolicy['create_group'] ?? (string) $featureExternal;
                    if ($slug === '' || (isset($reservedParentCreates[$slug])
                        && $reservedParentCreates[$slug] !== $owner)) {
                        throw new ManifestException('feature v5 unapproved new-parent collision');
                    }
                    $reservedParentCreates[$slug] = $owner;
                }
                $this->v5FeaturePlan[$featureExternal] = $featureId;
                $this->v5ConflictContext['local_feature_id'] = $featureId > 0 ? $featureId : null;
                foreach ($line['data']['option_records'] as $option) {
                    $key = $featureExternal . ':' . $option['external_id'];
                    if (!isset($this->v5MaterializedOptionKeys[$key])) { continue; }
                    $optionPolicy = $this->v5AdoptionPlan !== null
                        ? $this->v5AdoptionPlan->option($key) : null;
                    $optionMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key);
                    $this->v5ConflictContext['source_option_key'] = $key;
                    $this->v5ConflictContext['local_value_id'] = $optionMap !== null ? (int) $optionMap->local_id
                        : ($optionPolicy['local_id'] ?? null);
                    if ($optionPolicy !== null
                        && ($optionPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                        if ($optionMap !== null) {
                            throw new ManifestException('v5 adoption split option already has one physical map');
                        }
                        $this->preflightPolicySplit($optionPolicy, $featureId);
                        $this->v5OptionPlan[$key] = -1;
                        continue;
                    }
                    if ($optionPolicy !== null && $optionPolicy['local_id'] !== null) {
                        $valueId = $this->preflightPolicyOption($optionPolicy, $featureId, $optionMap);
                    } elseif ($optionMap !== null) {
                        $valueId = (int) ($optionMap->local_id ?? 0);
                        $valueRows = $valueId > 0 ? $this->map->findEntityRows($this->featuresValuesEntity, ['id'=>$valueId]) : [];
                        if (count($valueRows) !== 1 || $featureId < 1 || (int) $valueRows[0]->feature_id !== $featureId) {
                            throw new ManifestException('feature v5 mapped option target mismatch');
                        }
                        foreach ($this->map->findByLocalId(Contract::ENTITY_FEATURE_VALUE, $valueId) as $owner) {
                            $ownerKey = (string) $owner->external_id;
                            if (!isset($fingerprints[$ownerKey])
                                && $this->v5AdoptionPlan !== null
                                && $this->v5AdoptionPlan->featureLifecycle()
                                && $this->isNewSharedOptionAlias($ownerKey, $key)) {
                                // The group target check below validates every durable owner and the local display.
                                continue;
                            }
                            if (!isset($fingerprints[$ownerKey]) || $fingerprints[$ownerKey] !== $fingerprints[$key]
                                || (explode(':', $ownerKey, 2)[0] !== (string) $featureExternal
                                    && !$this->isExplicitPolicyOptionAlias($ownerKey, $valueId, $featureId)
                                    && !$this->isNewSharedOptionAlias($ownerKey, $key))) {
                                throw new ManifestException('feature v5 mapped option has incompatible alias ownership: ' . $key . ' and ' . $ownerKey);
                            }
                        }
                    } elseif ($featureId > 0) {
                        $valueRows = $this->exactV5Rows(
                            $this->featuresValuesEntity,
                            ['feature_id'=>$featureId, 'value'=>$option['value']],
                            'value', $option['value']
                        );
                        if (count($valueRows) > 1) { throw new ManifestException('feature v5 ambiguous option adoption: ' . $key); }
                        $valueId = count($valueRows) === 1 ? (int) $valueRows[0]->id : 0;
                        $this->v5ConflictContext['local_value_id'] = $valueId > 0 ? $valueId : null;
                        if ($valueId > 0) {
                            if ($optionPolicy === null && $this->v5AdoptionPlan !== null
                                && $this->v5AdoptionPlan->featureLifecycle()
                                && !$this->policyTargetDisplayCurrent($this->featuresValuesEntity, $valueId, 'value',
                                    $this->v5SourceOptionLabels($line, (string) $option['external_id']))) {
                                throw new ManifestException('feature v5 new option collides with a different bilingual value: ' . $key);
                            }
                            foreach ($this->map->findByLocalId(Contract::ENTITY_FEATURE_VALUE, $valueId) as $owner) {
                                $ownerKey = (string) $owner->external_id;
                                if (!isset($fingerprints[$ownerKey]) || $fingerprints[$ownerKey] !== $fingerprints[$key]
                                    || (explode(':', $ownerKey, 2)[0] !== (string) $featureExternal
                                        && !$this->isExplicitPolicyOptionAlias($ownerKey, $valueId, $featureId)
                                        && !$this->isNewSharedOptionAlias($ownerKey, $key))) {
                                    throw new ManifestException('feature v5 option local target has incompatible owner');
                                }
                            }
                        }
                        if ($valueId === 0 && $this->map->findEntityRows($this->featuresValuesEntity, [
                            'feature_id'=>$featureId, 'translit'=>Translit::translitAlpha($option['value'])
                        ]) !== []) {
                            throw new ManifestException('feature v5 option translit collision');
                        }
                    } else {
                        $valueId = 0;
                    }
                    if ($valueId > 0 && isset($claimedValues[$valueId])
                        && $claimedValues[$valueId] !== $fingerprints[$key]) {
                        throw new ManifestException('feature v5 competing option identities');
                    }
                    if ($valueId > 0) { $claimedValues[$valueId] = $fingerprints[$key]; }
                    if ($valueId === 0) {
                        $parentIdentity = $featureId > 0 ? 'id:' . $featureId
                            : 'new:' . ($parentPolicy['create_group'] ?? (string) $featureExternal);
                        $slug = Translit::translitAlpha((string) $option['value']);
                        $physicalKey = $parentIdentity . ':' . $slug;
                        $groupOwner = $optionPolicy !== null
                            ? $this->v5NewOptionGroupIdentity($optionPolicy) : null;
                        $owner = $groupOwner ?? ('default:' . (string) $featureExternal . ':' . $fingerprints[$key]);
                        if ($slug === '' || (isset($reservedOptionCreates[$physicalKey])
                            && $reservedOptionCreates[$physicalKey]['owner'] !== $owner
                            && !(strpos($reservedOptionCreates[$physicalKey]['owner'], 'default:') === 0
                                && $groupOwner === null
                                && $reservedOptionCreates[$physicalKey]['source_parent'] === (string) $featureExternal))) {
                            throw new ManifestException('feature v5 unapproved new-option collision');
                        }
                        if (!isset($reservedOptionCreates[$physicalKey])) {
                            $reservedOptionCreates[$physicalKey] = [
                                'owner'=>$owner, 'source_parent'=>(string) $featureExternal,
                            ];
                        }
                    }
                    if ($valueId > 0) {
                        $scope = $optionPolicy !== null && isset($optionPolicy['share_create_group'])
                            ? 'shared:' . $optionPolicy['share_create_group'] : (string) $featureExternal;
                        if (isset($groupLocalIds[$scope][$fingerprints[$key]])
                            && $groupLocalIds[$scope][$fingerprints[$key]] !== $valueId) {
                            throw new ManifestException('feature v5 equivalent aliases point at different local values');
                        }
                        $groupLocalIds[$scope][$fingerprints[$key]] = $valueId;
                    }
                    if ($optionPolicy !== null
                        && (isset($optionPolicy['create_group']) || isset($optionPolicy['share_create_group']))) {
                        $this->preflightV5NewOptionGroupTarget($optionPolicy, $valueId, $featureId);
                    }
                    $this->v5OptionPlan[$key] = $valueId;
                }
            }
            foreach ($groupKeys as $scope => $groups) {
                foreach ($groups as $fingerprint => $keys) {
                    $localId = $groupLocalIds[$scope][$fingerprint] ?? 0;
                    if ($localId > 0) {
                        foreach ($keys as $key) { $this->v5OptionPlan[$key] = $localId; }
                    }
                }
            }
            if ($this->v5AdoptionPlan !== null) {
                $this->v5ConflictContext = ['stage'=>'physical_feature_identity'];
                $this->assertV5PolicyEffectiveDisplays();
                $this->preflightPolicyProductLinks();
                if ($this->v5AdoptionPlan->preserveMappedStock()) {
                    foreach ($this->map->allLocalIds(Contract::ENTITY_PRODUCT) as $external => $localId) {
                        if (!isset($this->v5SelectedProductPairs[(string) $external])) {
                            $this->v5ConflictContext = ['source_product_id'=>(string) $external,
                                'local_product_id'=>$localId];
                            throw new ManifestException('v5 adoption stock-preserved mapped product absent from snapshot');
                        }
                    }
                }
            }
        } catch (ManifestException $e) {
            throw $this->featureConflict($e, $this->v5ConflictContext);
        } finally {
            $this->v5ConflictContext = [];
            $this->languages->setLangId($previous);
        }
    }

    /** A shared physical row has one deterministic displayed meaning, regardless of source order. */
    private function assertV5PolicySemanticGroups(): void
    {
        $parents = [];
        foreach ($this->v5AdoptionPlan->parents() as $row) {
            if ($row['local_id'] === null
                || (!empty($row['follow_source_labels']) && !isset($this->v5Features[$row['source_id']]))) {
                continue;
            }
            $key = (int) $row['local_id'];
            $meaning = [$row['display'], $row['expected_source']['type'], $row['expected_source']['unit']];
            if (isset($parents[$key]) && $parents[$key] !== $meaning) {
                throw new ManifestException('v5 adoption shared parent display/type/unit conflict');
            }
            $parents[$key] = $meaning;
        }
        $options = [];
        foreach ($this->v5AdoptionPlan->options() as $row) {
            if (isset($row['mode'])) { continue; }
            if ($row['local_id'] === null) { continue; }
            $key = (int) $row['local_id'];
            if (isset($options[$key]) && $options[$key] !== $row['display']) {
                throw new ManifestException('v5 adoption shared option display conflict');
            }
            $options[$key] = $row['display'];
        }
    }

    private function isExplicitPolicyOptionAlias(string $ownerKey, int $valueId, int $featureId): bool
    {
        $policy = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($ownerKey) : null;
        return $policy !== null && !isset($policy['mode'])
            && $policy['local_id'] === $valueId
            && $policy['expected_target']['feature_id'] === $featureId;
    }

    private function isNewSharedOptionAlias(string $ownerKey, string $key): bool
    {
        $owner = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($ownerKey) : null;
        $current = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($key) : null;
        return $owner !== null && $current !== null
            && isset($current['share_create_group'])
            && ($owner['share_create_group'] ?? null) === $current['share_create_group'];
    }

    private function v5NewOptionGroupIdentity(array $policy): ?string
    {
        if (isset($policy['share_create_group'])) { return 'shared:' . $policy['share_create_group']; }
        if (isset($policy['create_group'])) { return 'legacy:' . $policy['create_group']; }
        return null;
    }

    /** New-only display aliases may not silently absorb another selected source option. */
    private function assertV5NewOptionGroupSourceCollisions(): void
    {
        $groupBySlug = [];
        foreach ($this->v5AdoptionPlan->options() as $key => $policy) {
            $group = $this->v5NewOptionGroupIdentity($policy);
            if ($group === null) { continue; }
            $this->v5ConflictContext = ['source_option_key'=>(string) $key, 'create_group'=>$group];
            list($parent, $optionId) = explode(':', $key, 2);
            $line = $this->v5Features[$parent] ?? null;
            if ($line === null) {
                if ($this->v5AdoptionPlan->featureLifecycle()) { continue; }
                throw new ManifestException('v5 adoption new-option parent absent');
            }
            $found = false;
            foreach ($line['data']['option_records'] as $option) {
                if ((string) $option['external_id'] !== $optionId) { continue; }
                if ((string) $option['value'] !== $policy['expected_source']['ru']) {
                    throw new ManifestException('v5 adoption new-option source base/RU differs');
                }
                $slug = Translit::translitAlpha($option['value']);
                if ($slug === '') { throw new ManifestException('v5 adoption new-option translit empty'); }
                if (isset($groupBySlug[$parent][$slug])
                    && $groupBySlug[$parent][$slug] !== $group) {
                    throw new ManifestException('v5 adoption new-option groups share translit');
                }
                $groupBySlug[$parent][$slug] = $group;
                $found = true;
                break;
            }
            if (!$found && !$this->v5AdoptionPlan->featureLifecycle()) {
                throw new ManifestException('v5 adoption new-option source row absent');
            }
        }
        foreach ($this->v5MaterializedOptionKeys as $key => $selected) {
            list($parent, $optionId) = explode(':', $key, 2);
            if (!isset($groupBySlug[$parent])) { continue; }
            foreach ($this->v5Features[$parent]['data']['option_records'] as $option) {
                if ((string) $option['external_id'] !== $optionId) { continue; }
                $slug = Translit::translitAlpha($option['value']);
                if (isset($groupBySlug[$parent][$slug])) {
                    $policy = $this->v5AdoptionPlan->option($key);
                    if ($policy === null
                        || $this->v5NewOptionGroupIdentity($policy) !== $groupBySlug[$parent][$slug]) {
                        throw new ManifestException('v5 adoption unlisted source option shares new-option translit');
                    }
                }
                break;
            }
        }
    }

    /** An existing value is reusable only when this exact group created and owns it. */
    private function preflightV5NewOptionGroupTarget(array $policy, int $valueId, int $featureId): void
    {
        if ($valueId === 0) { return; }
        $row = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$valueId]);
        if ($row === false || (int) ($row->feature_id ?? 0) !== $featureId
            || (string) ($row->translit ?? '') !== Translit::translitAlpha($policy['display']['ru'])) {
            throw new ManifestException('v5 adoption new-option physical target drift');
        }
        if (!$this->policyTargetDisplayCurrent(
            $this->featuresValuesEntity, $valueId, 'value', $policy['display']
        )) {
            throw new ManifestException('v5 adoption new-option target label drift');
        }
        $owners = $this->map->findByLocalId(Contract::ENTITY_FEATURE_VALUE, $valueId);
        if ($owners === []) { throw new ManifestException('v5 adoption new-option would claim manual target'); }
        foreach ($owners as $owner) {
            $ownerKey = (string) $owner->external_id;
            $alias = $this->v5AdoptionPlan->option($ownerKey);
            $group = $this->v5NewOptionGroupIdentity($policy);
            if ($alias === null || $this->v5NewOptionGroupIdentity($alias) !== $group) {
                throw new ManifestException('v5 adoption new-option has unlisted owner');
            }
        }
    }

    /** Every source writer to one physical row must agree on its final bilingual display. */
    private function assertV5PolicyEffectiveDisplays(): void
    {
        $featureDisplays = [];
        foreach ($this->v5FeaturePlan as $sourceId => $physicalId) {
            if ($physicalId < 1) { continue; }
            $this->v5ConflictContext = ['source_feature_id'=>(string) $sourceId, 'local_feature_id'=>$physicalId];
            $policy = $this->v5AdoptionPlan->parent((string) $sourceId);
            $display = $this->v5EffectiveParentDisplay($this->v5Features[$sourceId], $policy);
            $line = $this->v5Features[$sourceId];
            $meaning = [$display, $line['data']['type'], $line['data']['unit']];
            if (isset($featureDisplays[$physicalId]) && $featureDisplays[$physicalId] !== $meaning) {
                throw new ManifestException('v5 adoption physical parent has order-dependent semantics');
            }
            $featureDisplays[$physicalId] = $meaning;
        }
        $valueDisplays = [];
        foreach ($this->v5OptionPlan as $key => $physicalId) {
            $this->v5ConflictContext = ['source_option_key'=>(string) $key,
                'local_value_id'=>$physicalId > 0 ? $physicalId : null];
            $policy = $this->v5AdoptionPlan->option((string) $key);
            if ($policy !== null && isset($policy['mode'])) {
                foreach ($policy['expected_targets'] as $target) {
                    $display = $policy['display'] ?? ['ru'=>$target['ru'], 'uk'=>$target['uk']];
                    $this->assertPolicySharedDisplay($valueDisplays, $target['id'], $display);
                }
                continue;
            }
            if ($physicalId < 1) { continue; }
            list($parentId, $optionId) = explode(':', (string) $key, 2);
            $display = $policy !== null ? $policy['display']
                : $this->v5SourceOptionLabels($this->v5Features[$parentId], $optionId);
            $this->assertPolicySharedDisplay($valueDisplays, $physicalId, $display);
        }
    }

    private function assertPolicySharedDisplay(array &$seen, int $physicalId, array $display): void
    {
        if (isset($seen[$physicalId]) && $seen[$physicalId] !== $display) {
            throw new ManifestException('v5 adoption physical option has order-dependent display');
        }
        $seen[$physicalId] = $display;
    }

    private function v5EffectiveParentDisplay(array $line, ?array $policy): array
    {
        return $policy !== null && empty($policy['follow_source_labels'])
            ? $policy['display'] : $this->v5SourceFeatureLabels($line);
    }

    private function v5SourceFeatureLabels(array $line): array
    {
        $labels = [];
        foreach ($line['data']['translations'] as $translation) {
            $labels[$translation['language']] = $translation['name'];
        }
        return ['ru'=>$labels['ru'] ?? '', 'uk'=>$labels['uk'] ?? ''];
    }

    private function v5SourceOptionLabels(array $line, string $optionId): array
    {
        foreach ($line['data']['option_records'] as $option) {
            if ((string) $option['external_id'] !== $optionId) { continue; }
            $labels = [];
            foreach ($option['translations'] as $translation) {
                $labels[$translation['language']] = $translation['value'];
            }
            return ['ru'=>$labels['ru'] ?? '', 'uk'=>$labels['uk'] ?? ''];
        }
        throw new ManifestException('v5 adoption option writer missing source row');
    }

    /** @param object|null $sourceMap */
    private function preflightPolicyFeature(array $policy, $sourceMap): int
    {
        $localId = (int) $policy['local_id'];
        if ($sourceMap !== null && (int) ($sourceMap->local_id ?? 0) !== $localId) {
            throw new ManifestException('v5 adoption parent source map drift');
        }
        $row = $this->map->findEntityOne($this->featuresEntity, ['id'=>$localId]);
        if ($row === false || (int) $row->id !== $localId) {
            throw new ManifestException('v5 adoption parent physical target absent');
        }
        $target = $policy['expected_target'];
        $filter = $policy['filterable_resolution'] ?? null;
        if ($filter !== null) {
            $source = $this->v5Features[$policy['source_id']] ?? null;
            if ($source === null || !empty($source['data']['filterable']) !== $filter['expected_source']
                || (int) ($row->in_filter ?? -1) !== (int) $filter['target']) {
                throw new ManifestException('v5 adoption existing-parent filter pin drift');
            }
        }
        if ((string) ($row->external_id ?? '') !== $target['external_id']
            || (string) ($row->url ?? '') !== $target['url']) {
            throw new ManifestException('v5 adoption parent physical identity/URL drift');
        }
        if (!empty($policy['follow_source_labels'])) {
            if ($sourceMap === null) {
                throw new ManifestException('v5 adoption managed labels require a durable source map');
            }
            foreach ($this->v5AdoptionPlan->parents() as $alias) {
                if ($alias['local_id'] === $localId && $alias['source_id'] !== $policy['source_id']) {
                    throw new ManifestException('v5 adoption managed labels conflict with a planned alias');
                }
            }
            $this->assertPolicyOwners(Contract::ENTITY_FEATURE, $localId,
                $target['owner_source_ids'], $target['owner_source_ids']);
            return $localId;
        }
        $this->assertPolicyLabels($this->featuresEntity, $localId, 'name', $target, $policy['display']);
        $allowed = $target['owner_source_ids'];
        foreach ($this->v5AdoptionPlan->parents() as $alias) {
            if ($alias['local_id'] === $localId) { $allowed[] = $alias['source_id']; }
        }
        $this->assertPolicyOwners(Contract::ENTITY_FEATURE, $localId, $target['owner_source_ids'], $allowed);
        return $localId;
    }

    /** @param object|null $sourceMap */
    private function preflightPolicyOption(array $policy, int $featureId, $sourceMap): int
    {
        $localId = (int) $policy['local_id'];
        $target = $policy['expected_target'];
        if ($featureId < 1 || $target['feature_id'] !== $featureId
            || ($sourceMap !== null && (int) ($sourceMap->local_id ?? 0) !== $localId)) {
            throw new ManifestException('v5 adoption option parent/source map drift');
        }
        $this->assertPolicyValueTarget($target, $policy['display']);
        $allowed = $target['owner_keys'];
        foreach ($this->v5AdoptionPlan->options() as $alias) {
            if (!isset($alias['mode']) && $alias['local_id'] === $localId) {
                $allowed[] = $alias['key'];
            }
        }
        $this->assertPolicyOwners(Contract::ENTITY_FEATURE_VALUE, $localId, $target['owner_keys'], $allowed);
        return $localId;
    }

    private function preflightPolicySplit(array $policy, int $featureId): void
    {
        foreach ($policy['expected_targets'] as $target) {
            if ($featureId < 1 || $target['feature_id'] !== $featureId) {
                throw new ManifestException('v5 adoption split target parent drift');
            }
            $display = $policy['display'] ?? ['ru'=>$target['ru'], 'uk'=>$target['uk']];
            $this->assertPolicyValueTarget($target, $display);
            $allowed = $target['owner_keys'];
            foreach ($this->v5AdoptionPlan->options() as $alias) {
                if (!isset($alias['mode']) && $alias['local_id'] === $target['id']
                    && $alias['expected_target']['feature_id'] === $featureId) {
                    $allowed[] = $alias['key'];
                }
            }
            $this->assertPolicyOwners(
                Contract::ENTITY_FEATURE_VALUE, $target['id'], $target['owner_keys'], $allowed
            );
        }
    }

    private function assertPolicyValueTarget(array $target, array $display): void
    {
        $localId = $target['id'];
        $row = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$localId]);
        if ($row === false || (int) $row->id !== $localId
            || (int) $row->feature_id !== $target['feature_id']
            || (string) ($row->translit ?? '') !== $target['translit']) {
            throw new ManifestException('v5 adoption option physical identity/URL drift');
        }
        $this->assertPolicyLabels($this->featuresValuesEntity, $localId, 'value', $target, $display);
    }

    private function assertPolicyLabels($entity, int $localId, string $field, array $before, array $display): void
    {
        $previous = (int) $this->languages->getLangId();
        try {
            foreach ($this->v5AdoptionPlan->languages() as $language) {
                if (!isset($this->productContentLanguageIds[$language])) {
                    throw new ManifestException('v5 adoption target language missing');
                }
                $this->languages->setLangId($this->productContentLanguageIds[$language]);
                $row = $this->map->findEntityOne($entity, ['id'=>$localId]);
                $actual = $row === false ? null : (string) ($row->$field ?? '');
                if ($actual !== $before[$language] && $actual !== $display[$language]) {
                    throw new ManifestException('v5 adoption target bilingual label drift');
                }
            }
        } finally {
            $this->languages->setLangId($previous);
        }
    }

    private function assertPolicyOwners(string $type, int $localId, array $required, array $allowed): void
    {
        $actual = [];
        foreach ($this->map->findByLocalId($type, $localId) as $owner) {
            $actual[] = (string) $owner->external_id;
        }
        foreach ($required as $owner) {
            if (!in_array($owner, $actual, true)) {
                throw new ManifestException('v5 adoption required existing owner missing');
            }
        }
        foreach ($actual as $owner) {
            if (!in_array($owner, $allowed, true)) {
                throw new ManifestException('v5 adoption unexpected physical owner');
            }
        }
    }

    /** Resolve split physical identity for the whole product set before the first catalog write. */
    private function preflightPolicyProductLinks(): void
    {
        foreach ($this->v5AdoptionPlan->productTargets() as $external => $targets) {
            foreach ($targets as $key => $target) {
                $found = false;
                foreach ($this->v5SelectedProductPairs[$external] ?? [] as $pair) {
                    if ((string) $pair['feature_external_id'] . ':' . (string) $pair['option_external_id'] === $key) {
                        $found = true;
                        break;
                    }
                }
                if (!$found && !$this->v5AdoptionPlan->featureLifecycle()) {
                    throw new ManifestException('v5 adoption product target lacks source pair');
                }
            }
        }
        $adopter = new FeatureLinkAdopter($this->map, $this->featuresValuesEntity);
        $initialBind = null;
        $claimedProtectedTargets = [];
        foreach ($this->v5SelectedProductPairs as $external => $pairs) {
            $this->v5ConflictContext = ['source_product_id'=>(string) $external];
            foreach ($this->v5AdoptionPlan->featureLinkAdoptions($external) as $adoption) {
                $this->v5ConflictContext = ['source_product_id'=>(string) $external,
                    'source_option_key'=>$adoption['option_key'], 'local_product_id'=>$adoption['local_product_id'],
                    'local_value_id'=>$adoption['local_value_id']];
                $this->assertReviewedFeatureLinkAdoption($adopter, $adoption);
            }
            $selected = [];
            foreach ($pairs as $pair) {
                $key = (string) $pair['feature_external_id'] . ':' . (string) ($pair['option_external_id'] ?? '');
                $policy = $this->v5AdoptionPlan->option($key);
                if ($this->v5AdoptionPlan->hold($external, $key) !== null
                    || $policy === null || ($policy['mode'] ?? null) !== 'preserve_existing_equivalent_set') {
                    continue;
                }
                $selected[$key] = $policy;
            }
            $holds = $this->v5AdoptionPlan->holds()[$external] ?? [];
            if ($selected === [] && $holds === []) { continue; }
            $productMap = $this->map->find(Contract::ENTITY_PRODUCT, $external);
            $localId = (int) ($productMap->local_id ?? 0);
            $identity = $this->v5ProtectedProductIdentities[$external] ?? null;
            if ($localId < 1 && $identity !== null) {
                $initialBind = $initialBind ?? $this->shouldBind();
                if (!$initialBind) {
                    throw new ManifestException('v5 adoption protected product has no physical map');
                }
                // The native bind will create its map later. Read the exact target now,
                // so the hold can be checked without provisional ownership writes.
                $conflict = $this->sourceIdentityBindConflict((string) $external, $identity);
                if ($conflict !== null) {
                    $error = new ManifestException('v5 adoption protected product source identity: ' . $conflict['reason']);
                    if ($this->mediaPreview !== null) {
                        throw new FeatureLifecycleConflict($error->getMessage(), ['product_external_id' => (string) $external] + $conflict, $error);
                    }
                    throw $error;
                }
                $localId = $identity['product_id'];
            }
            if ($localId < 1) {
                throw new ManifestException('v5 adoption protected product has no physical map');
            }
            if ($identity !== null && ($identity['product_id'] !== $localId
                || isset($claimedProtectedTargets[$localId]))) {
                throw new ManifestException('v5 adoption protected product identity does not match unique local target');
            }
            if ($identity !== null) {
                $claimedProtectedTargets[$localId] = true;
            }
            $this->v5ConflictContext['local_product_id'] = $localId;
            $linked = $this->map->productValueIdsChecked($this->featuresValuesEntity, $localId);
            foreach ($selected as $key => $policy) {
                $this->v5ConflictContext['source_option_key'] = $key;
                $this->v5ConflictContext['local_value_ids'] = $policy['local_ids'];
                $existing = array_intersect($policy['local_ids'], $linked);
                $target = $this->v5AdoptionPlan->productTarget($external, $key);
                if ($existing === [] && $target === null) {
                    throw new ManifestException('v5 adoption selected split option has no existing physical link or target');
                }
                if ($target !== null) {
                    $this->preflightPolicyProductTarget($target, $policy, $linked);
                }
            }
            foreach ($holds as $hold) {
                foreach ($hold['existing_local_value_ids'] ?? [] as $id) {
                    $this->v5ConflictContext['source_option_key'] = $hold['key'];
                    $this->v5ConflictContext['local_value_id'] = $id;
                    if (!in_array($id, $linked, true)) {
                        throw new ManifestException('v5 adoption held physical link drift');
                    }
                    if ($this->v5AdoptionPlan->languages() === ['ru']) {
                        $sourceFeature = explode(':', $hold['key'], 2)[0];
                        $localFeature = $this->v5FeaturePlan[$sourceFeature] ?? 0;
                        $value = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$id]);
                        if ($localFeature < 1 || $value === false || (int) $value->feature_id !== $localFeature
                            || (string) $value->value !== $hold['expected_value']) {
                            throw new ManifestException('v5 adoption RU-only held target feature or label drift');
                        }
                    }
                }
            }
        }
    }

    private function preflightPolicyProductTarget(array $target, array $policy, array $linked): void
    {
        $valueId = $target['local_value_id'];
        if (!in_array($valueId, $policy['local_ids'], true)) {
            throw new ManifestException('v5 adoption product target outside value set');
        }
        if (isset($target['reference_product_external_id'])) {
            $referenceExternal = $target['reference_product_external_id'];
            $referenceMatches = false;
            foreach ($this->v5SelectedProductPairs[$referenceExternal] ?? [] as $pair) {
                $referenceKey = (string) $pair['feature_external_id'] . ':'
                    . (string) ($pair['option_external_id'] ?? '');
                if ($referenceKey === $target['key']
                    && $this->v5AdoptionPlan->hold($referenceExternal, $referenceKey) === null) {
                    $referenceMatches = true;
                    break;
                }
            }
            if (!$referenceMatches) {
                throw new ManifestException('v5 adoption reference source lacks same selected option');
            }
            $reference = $this->map->find(
                Contract::ENTITY_PRODUCT, $referenceExternal
            );
            $referenceId = $reference !== null ? (int) ($reference->local_id ?? 0) : 0;
        } else {
            $referenceId = $target['reference_target_product_id'];
        }
        if ($referenceId < 1 || !in_array($valueId,
            $this->map->productValueIdsChecked($this->featuresValuesEntity, $referenceId), true)) {
            throw new ManifestException('v5 adoption product target has no linked physical reference');
        }
        $wanted = null;
        foreach ($policy['expected_targets'] as $guard) {
            if ($guard['id'] === $valueId) { $wanted = $guard; break; }
        }
        if ($wanted === null) { throw new ManifestException('v5 adoption product target guard missing'); }
        $wantedNumber = $this->policyComparableNumber(
            $wanted[$this->manifestLanguage] ?? $wanted['ru']
        );
        foreach ($linked as $existingValueId) {
            if ($existingValueId === $valueId) { continue; }
            $row = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$existingValueId]);
            if ($row === false || (int) ($row->feature_id ?? 0) !== $wanted['feature_id']) { continue; }
            $actualNumber = $this->policyComparableNumber((string) ($row->value ?? ''));
            if ($wantedNumber !== null && $actualNumber !== null
                && $wantedNumber[1] === $actualNumber[1]
                && $wantedNumber[0] !== $actualNumber[0]) {
                throw new ManifestException('v5 adoption product target conflicts with existing numeric fact');
            }
        }
    }

    /** A numeric option is comparable only within the same physical parent and unit token. */
    private function policyComparableNumber(string $label): ?array
    {
        if (preg_match('/^\s*([0-9]+(?:[,.][0-9]+)?)\s*(.*?)\s*$/uD', $label, $match) !== 1) {
            return null;
        }
        return [str_replace(',', '.', $match[1]), mb_strtolower(trim($match[2]), 'UTF-8')];
    }

    /** Stable label fingerprint excludes the source option ID and translation array order. */
    private function v5OptionFingerprint(array $option): string
    {
        $labels = [];
        foreach ($option['translations'] as $translation) {
            $labels[$translation['language']] = $translation['value'];
        }
        ksort($labels, SORT_STRING);
        return hash('sha256', json_encode([$option['value'], $labels], JSON_UNESCAPED_UNICODE));
    }

    /** @param mixed $entity @param array<string, mixed> $filter @return array<int, object> */
    private function exactV5Rows($entity, array $filter, string $field, string $expected): array
    {
        return array_values(array_filter($this->map->findEntityRows($entity, $filter), static function ($row) use ($field, $expected): bool {
            return isset($row->$field) && $row->$field === $expected;
        }));
    }

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
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->setPhase($role);
            }

            if ($checkpoints->getStatus($name) === Contract::FILE_APPLIED) {
                if ($this->schemaMajor === 5
                    && ($role === Contract::ENTITY_FEATURE || $role === Contract::ENTITY_PRODUCT)) {
                    $this->assertV5AppliedFilePolicyCurrent(
                        $role, rtrim($stagingDir, '/') . '/' . $name
                    );
                }
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
            if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor) && $phaseErrors > 0) {
                return Contract::STATUS_FAILED; // strict v2/v3: do not checkpoint a partially rejected file
            }

            $checkpoints->setStatus($name, Contract::FILE_APPLIED);
        }

        // --- absent-фаза (только записи карты, отсутствующие в снапшоте) ---
        if ($isCancelled()) {
            return Contract::STATUS_CANCELLED;
        }
        if ($this->mediaPreview !== null) {
            $this->mediaPreview->setPhase('absent');
        }
        $held = $this->applyAbsent($manifest, $stats, $seenProducts);

        if ($this->mediaPreview !== null) {
            $this->mediaPreview->setPhase('media');
        }

        // --- усыновление уже лежащей галереи по содержимому — ДО скачивания ---
        if ($this->runGalleryAdoptionPhase($isCancelled, $stats) === Contract::STATUS_CANCELLED) {
            return Contract::STATUS_CANCELLED;
        }

        // --- картинки — догоняющая фаза после текста (витрина уже актуальна по ценам/остаткам) ---
        if ($this->runImagesPhase($isCancelled, $stats) === Contract::STATUS_CANCELLED) {
            return Contract::STATUS_CANCELLED;
        }
        $categoryImagesStatus = $this->runCategoryImagesPhase($isCancelled, $stats);
        if ($categoryImagesStatus !== null) {
            return $categoryImagesStatus;
        }

        return $held ? Contract::STATUS_HELD : Contract::STATUS_APPLIED;
    }

    /** A resumed file cannot be skipped under a different receiver policy digest. */
    private function assertV5AppliedFilePolicyCurrent(string $role, string $path): void
    {
        if (!is_file($path)) {
            throw new ManifestException('v5 adoption applied file missing on resume');
        }
        $read = $this->reader->each($path, function (array $line) use ($role): void {
            $external = (string) $line['external_id'];
            if ($role === Contract::ENTITY_PRODUCT) {
                $map = $this->map->find(Contract::ENTITY_PRODUCT, $external);
                if ($map === null || (string) $map->applied_hash
                    !== $this->v5PolicyHash((string) $line['hash'])) {
                    throw new ManifestException('v5 adoption resumed product policy digest mismatch');
                }
                return;
            }
            if (strpos($external, 'attr-') === 0) { return; }
            $map = $this->map->find(Contract::ENTITY_FEATURE, $external);
            if ($map === null || (string) $map->applied_hash
                !== $this->v5PolicyHash((string) $line['hash'])) {
                throw new ManifestException('v5 adoption resumed feature policy digest mismatch');
            }
            $parentPolicy = $this->v5AdoptionPlan !== null
                ? $this->v5AdoptionPlan->parent($external) : null;
            if ($parentPolicy !== null && !$this->policyTargetDisplayCurrent(
                $this->featuresEntity, $parentPolicy['local_id'], 'name', $parentPolicy['display']
            )) {
                throw new ManifestException('v5 adoption resumed parent display mismatch');
            }
            foreach ($line['data']['option_records'] as $option) {
                $key = $external . ':' . (string) $option['external_id'];
                if (!isset($this->v5MaterializedOptionKeys[$key])) { continue; }
                $policy = $this->v5AdoptionPlan !== null
                    ? $this->v5AdoptionPlan->option($key) : null;
                if ($policy !== null && isset($policy['mode'])) {
                    if (!$this->policySplitDisplayCurrent($policy)) {
                        throw new ManifestException('v5 adoption resumed split display mismatch');
                    }
                    continue;
                }
                $optionMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key);
                $expected = $this->v5PolicyHash(hash('sha256', (string) json_encode($option)));
                if ($optionMap === null || (string) $optionMap->applied_hash !== $expected) {
                    throw new ManifestException('v5 adoption resumed option policy digest mismatch');
                }
                if ($policy !== null && !$this->policyTargetDisplayCurrent(
                    $this->featuresValuesEntity, (int) $optionMap->local_id, 'value', $policy['display']
                )) {
                    throw new ManifestException('v5 adoption resumed option display mismatch');
                }
            }
        });
        if ($read['broken'] !== 0) {
            throw new ManifestException('v5 adoption applied file broken on resume');
        }
    }

    // ================================================================ bind

    private function shouldBind(): bool
    {
        // Прерванный bind — ДОБИТЬ, не флипать в full/price_stock (RISK(v) M3, §0.1). Sticky-метка
        // держит bind-режим сквозь interrupt/resume, пока bind не завершён полностью. Проверять ДО
        // «карта пуста»: после первого bind-файла карта уже непуста, но bind ещё не закончен.
        if ($this->map->isBindInProgress()) {
            return true;
        }

        // Bind уже отработал по этой витрине — ВТОРОЙ РАЗ НЕ ЗАПУСКАЕМ, даже если карта пуста
        // (D-SAT-BIND-LOOP-NEVER-APPLIES). Пустая карта после bind = легальный исход «ни один SKU
        // витрины не наш»; без этой памяти условие ниже вечно истинно → bind→bound→bind навсегда,
        // и полностью чужой каталог (типовое подключение клиента) НИКОГДА не получает каталог ядра.
        // Отметка ниже in_progress: прерванный bind добивается bind'ом, а не улетает в full.
        if ($this->map->isBindCompleted()) {
            return false;
        }

        $mapEmpty = ($this->map->count(Contract::ENTITY_PRODUCT) + $this->map->count(Contract::ENTITY_VARIANT)) === 0;
        if (!$mapEmpty) {
            return false;
        }

        return (int) $this->productsEntity->count() > 0;
    }

    /**
     * Связывание строк снапшота с записями Okay: category/brand по marker/exact slug, товары по SKU
     * варианта. Заполняет map с applied_hash=NULL; контент не пишет, кроме module-owned marker у
     * успешно обнаруженных category/brand.
     *
     * Interrupt-safe (RISK(v) M3, §0.1): sticky-метка bind взводится ДО первой записи; per-file
     * чекпоинт (как в applyFull) пропускает уже связанные файлы при resume; отмена/ошибка оставляют
     * метку взведённой (resume добьёт bind, а не уйдёт в full → дубли). Метка снимается ТОЛЬКО при
     * полном прохождении всех dictionary/products-файлов — и тогда же взводится отметка «bind отработал»
     * (markBindCompleted), закрывающая фазу навсегда, в том числе при 0 совпадений.
     *
     * @param array<string, mixed> $manifest
     */
    private function runBind(
        array $manifest,
        string $stagingDir,
        FileCheckpointStore $checkpoints,
        callable $isCancelled,
        ApplyStats $stats
    ): string {
        $this->map->markBindInProgress();

        foreach ($this->orderedFiles($manifest) as $file) {
            if (!in_array($file['role'], [
                Contract::ENTITY_CATEGORY,
                Contract::ENTITY_BRAND,
                Contract::ENTITY_PRODUCT,
            ], true)) {
                continue; // features/redirects не участвуют в discovery-bind
            }
            if ($isCancelled()) {
                return Contract::STATUS_CANCELLED; // метка остаётся — resume добьёт bind
            }
            $name = $file['name'];
            if ($checkpoints->getStatus($name) === Contract::FILE_APPLIED) {
                continue; // файл уже полностью связан на прошлом проходе
            }
            $path = rtrim($stagingDir, '/') . '/' . $name;
            if (!is_file($path)) {
                $this->warning('CoreSync bind: файл отсутствует в staging: ' . $name);
                continue;
            }
            $errorsBefore = $stats->errors;
            try {
                if ($file['role'] === Contract::ENTITY_CATEGORY) {
                    $this->bindCategoriesFile($path, $stats);
                } elseif ($file['role'] === Contract::ENTITY_BRAND) {
                    $this->bindBrandsFile($path, $stats);
                } else {
                    $this->reader->each($path, function (array $line) use ($stats): void {
                        $this->bindProductLine($line, $stats);
                    });
                }
            } catch (NdjsonReadException $e) {
                $this->error('CoreSync bind: ' . $e->getMessage());

                return Contract::STATUS_FAILED; // метка остаётся — resume повторит bind
            }
            if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)
                && $stats->errors > $errorsBefore) {
                return Contract::STATUS_FAILED; // do not checkpoint an invalid v2/v3 dictionary file
            }
            $checkpoints->setStatus($name, Contract::FILE_APPLIED);
        }

        // Порядок важен: сперва «bind отработал» (markBindCompleted), затем снятие in_progress.
        // Crash МЕЖДУ ними оставляет in_progress активным → shouldBind() истинен по метке → resume
        // добивает bind идемпотентно (верно при любом числе связанных). Обратный порядок при том же
        // crash (in_progress снят, completed не взведён) ломается по-РАЗНОМУ в зависимости от исхода:
        //   • связано 0 (карта product/variant пуста) → shouldBind() снова истинен (карта пуста +
        //     каталог непуст) → bind→bound→bind вечно (петля D-SAT-BIND-LOOP-NEVER-APPLIES);
        //   • связано ≥1 (карта непуста) → shouldBind() ложен → прогон уходит в full и пишет каталог
        //     мимо связанного как новый → ДУБЛИ.
        // Обе ветки — регресс, поэтому completed взводится строго ДО снятия in_progress (замок порядка —
        // testBindCompletedMarkedBeforeInProgressCleared в BindTest).
        $this->map->markBindCompleted();
        $this->map->clearBindInProgress();

        // Исход «связано 0» логирует SyncRunner (у него версия снапшота и контекст отчёта) —
        // здесь не дублируем, чтобы у оператора на одно событие была одна строка.
        return Contract::STATUS_BOUND;
    }

    /**
     * Category discovery идёт итеративно: дочерний slug проверяется только после durable-разрешения
     * родителя, поэтому точный immediate parent индуктивно подтверждает весь path.
     */
    private function bindCategoriesFile(string $path, ApplyStats $stats): void
    {
        $read = $this->reader->readAll($path);
        $stats->errors += $read['stats']['broken'];
        $queue = [];
        foreach ($read['lines'] as $index => $line) {
            $queue[] = ['line' => $line, 'raw_json' => $read['raw_lines'][$index] ?? null];
        }
        $progress = true;

        while (!empty($queue) && $progress) {
            $progress = false;
            $deferred = [];
            foreach ($queue as $item) {
                if ($this->bindCategoryLine($item['line'], $stats, $item['raw_json']) === 'defer') {
                    $deferred[] = $item;
                } else {
                    $progress = true;
                }
            }
            $queue = $deferred;
        }

        foreach ($queue as $item) {
            $line = $item['line'];
            $externalId = (string) ($line['external_id'] ?? '');
            $parentExternal = (string) (((array) ($line['data'] ?? []))['parent_external_id'] ?? '');
            $this->dictionaryConflict($stats, 'category', $externalId, 'parent ' . $parentExternal . ' unresolved');
        }
    }

    /** @param array<string, mixed> $line @return string 'done'|'defer' */
    private function bindCategoryLine(array $line, ApplyStats $stats, ?string $rawJson = null): string
    {
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            try {
                $data = $this->categoryV2Validator->validate($line, $this->sourceInstance, $rawJson);
            } catch (ManifestException $e) {
                $stats->errors++;
                $this->warning('CoreSync bind: category v2 row rejected');

                return 'done';
            }
            $externalId = (string) $data['external_id'];
        } else {
            $externalId = (string) ($line['external_id'] ?? '');
            $data = (array) ($line['data'] ?? []);
        }
        $parentExternal = $data['parent_external_id'] ?? null;
        $parentId = 0;
        if ($parentExternal !== null) {
            $resolved = $this->map->localId(Contract::ENTITY_CATEGORY, (string) $parentExternal);
            if ($resolved === null) {
                return 'defer';
            }
            $parentId = $resolved;
        }

        $this->bindDictionaryLine(
            Contract::ENTITY_CATEGORY,
            $externalId,
            (string) ($data['slug'] ?? ''),
            $this->categoriesEntity,
            $stats,
            $parentId,
            Contract::isSnapshotStructuralV2Plus($this->schemaMajor) ? (string) $data['source_id'] : null
        );

        return 'done';
    }

    private function bindBrandsFile(string $path, ApplyStats $stats): void
    {
        $read = $this->reader->each($path, function (array $line) use ($stats): void {
            $data = (array) ($line['data'] ?? []);
            $this->bindDictionaryLine(
                Contract::ENTITY_BRAND,
                (string) ($line['external_id'] ?? ''),
                (string) ($data['slug'] ?? ''),
                $this->brandsEntity,
                $stats,
                null
            );
        });
        $stats->errors += $read['broken'];
    }

    /**
     * Приоритет discovery: map → marker → exact case-sensitive slug. Успех штампует marker и
     * map; конфликт не делает ни одной новой записи для спорной сущности.
     *
     * @param mixed $entity CategoriesEntity|BrandsEntity
     */
    private function bindDictionaryLine(
        string $entityType,
        string $externalId,
        string $slug,
        $entity,
        ApplyStats $stats,
        ?int $expectedParentId,
        ?string $verifiedSourceId = null
    ): void {
        $mapRow = $this->map->find($entityType, $externalId);
        $markerRows = $this->dictionaryMarkerRows($entity, $externalId);

        if ($mapRow !== null) {
            $localId = (int) $mapRow->local_id;
            $local = $localId > 0 ? $entity->findOne(['id' => $localId]) : false;
            if (empty($local)) {
                $this->dictionaryConflict($stats, $entityType, $externalId, 'map local_id missing');

                return;
            }
            if (count($markerRows) > 1) {
                $this->dictionaryConflict($stats, $entityType, $externalId, 'duplicate marker');

                return;
            }
            if (count($markerRows) === 1 && (int) $markerRows[0]->id !== $localId) {
                $this->dictionaryConflict($stats, $entityType, $externalId, 'marker-map mismatch');

                return;
            }
            if (!$this->dictionaryCandidateIsValid($entityType, $externalId, $local, $expectedParentId, $stats)) {
                return;
            }
            if ((string) ($local->coresync_external_id ?? '') === '') {
                if ($entity->update($localId, ['coresync_external_id' => $externalId]) === false) {
                    $this->dictionaryConflict($stats, $entityType, $externalId, 'marker write failed');

                    return;
                }
            }
            $stats->bound++;

            return;
        }

        if (count($markerRows) > 1) {
            $this->dictionaryConflict($stats, $entityType, $externalId, 'duplicate marker');

            return;
        }
        if (count($markerRows) === 1) {
            $candidate = $markerRows[0];
        } else {
            $candidateRows = [];
            if ($entityType === Contract::ENTITY_CATEGORY && $verifiedSourceId !== null) {
                $candidateRows = $this->dictionaryRowsByExactField($entity, 'external_id', $verifiedSourceId);
                if (count($candidateRows) > 1) {
                    $this->dictionaryConflict($stats, $entityType, $externalId, 'duplicate verified source id');

                    return;
                }
            }
            if (count($candidateRows) === 0) {
                $candidateRows = $this->dictionarySlugRows($entity, $slug);
            }
            if (count($candidateRows) === 0) {
                $stats->unmatched++;
                $this->bindSample($stats, $entityType . ' ' . $externalId . ' (identity/slug not found)');

                return;
            }
            if (count($candidateRows) > 1) {
                $this->dictionaryConflict($stats, $entityType, $externalId, 'duplicate slug');

                return;
            }
            $candidate = $candidateRows[0];
        }

        if (!$this->dictionaryCandidateIsValid($entityType, $externalId, $candidate, $expectedParentId, $stats)) {
            return;
        }

        $localId = (int) $candidate->id;
        if ((string) ($candidate->coresync_external_id ?? '') === '') {
            // Marker-first: crash до recordBind сходится следующим прогоном через marker lookup.
            if ($entity->update($localId, ['coresync_external_id' => $externalId]) === false) {
                $this->dictionaryConflict($stats, $entityType, $externalId, 'marker write failed');

                return;
            }
        }
        $this->map->recordBind($entityType, $externalId, $localId);
        $stats->bound++;
    }

    /** @param mixed $candidate */
    private function dictionaryCandidateIsValid(
        string $entityType,
        string $externalId,
        $candidate,
        ?int $expectedParentId,
        ApplyStats $stats
    ): bool {
        $reason = $this->dictionaryCandidateConflictReason(
            $entityType,
            $externalId,
            $candidate,
            $expectedParentId
        );
        if ($reason !== null) {
            $this->dictionaryConflict($stats, $entityType, $externalId, $reason);

            return false;
        }

        return true;
    }

    /** @param mixed $candidate */
    private function dictionaryCandidateConflictReason(
        string $entityType,
        string $externalId,
        $candidate,
        ?int $expectedParentId
    ): ?string {
        $localId = (int) ($candidate->id ?? 0);
        $marker = (string) ($candidate->coresync_external_id ?? '');
        if ($localId <= 0) {
            return 'invalid local id';
        }
        if ($marker !== '' && $marker !== $externalId) {
            return 'marker mismatch';
        }
        foreach ($this->map->findByLocalId($entityType, $localId) as $owned) {
            if ((string) $owned->external_id !== $externalId) {
                return 'local id already mapped';
            }
        }
        if ($entityType === Contract::ENTITY_CATEGORY
            && $expectedParentId !== null
            && (int) ($candidate->parent_id ?? 0) !== $expectedParentId) {
            return 'parent mismatch';
        }

        return null;
    }

    /** @param mixed $entity @return array<int, object> */
    private function dictionaryMarkerRows($entity, string $externalId): array
    {
        return $this->dictionaryRowsByExactField($entity, 'coresync_external_id', $externalId);
    }

    /** @param mixed $entity @return array<int, object> */
    private function dictionarySlugRows($entity, string $slug): array
    {
        return $this->dictionaryCandidateRows($entity, 'url', $slug);
    }

    /**
     * Кандидаты discovery по одному точному полю: `url` у category/brand, `name` у характеристик
     * (идентичности `okay:feature:N` в ядре нет — см. бриф coresync-feature-bind-existing).
     * Пустое значение кандидатов не даёт: иначе первая же безымянная строка витрины была бы
     * «совпадением» для каждой безымянной строки ядра.
     *
     * @param mixed $entity
     * @return array<int, object>
     */
    private function dictionaryCandidateRows($entity, string $field, string $value): array
    {
        if ($value === '') {
            return [];
        }

        return $this->dictionaryRowsByExactField($entity, $field, $value);
    }

    /**
     * CategoriesEntity не поддерживает произвольные find-фильтры (url/module marker дают пусто),
     * поэтому общий category/brand lookup читает живой iterable без фильтра и сравнивает поле здесь.
     * Строгое === после string-cast сохраняет exact case-sensitive semantics discovery.
     *
     * @param mixed $entity CategoriesEntity|BrandsEntity
     * @return array<int, object>
     */
    private function dictionaryRowsByExactField($entity, string $field, string $value): array
    {
        return array_values(array_filter(
            $entity->find(),
            static function ($row) use ($field, $value): bool {
                return (string) ($row->{$field} ?? '') === $value;
            }
        ));
    }

    private function dictionaryConflict(ApplyStats $stats, string $entityType, string $externalId, string $reason): void
    {
        $stats->conflicts++;
        $sample = $entityType . ' ' . $externalId . ' (' . $reason . ')';
        $this->bindSample($stats, $sample);
        $this->warning('CoreSync bind: conflict ' . $sample);
    }

    /**
     * Full apply: существующая map приоритетна; если map потеряна, ровно один marker восстанавливает
     * её до MAP_CREATE. Любая неоднозначность fail-closed до content write.
     *
     * @param mixed $entity CategoriesEntity|BrandsEntity
     * @return array{row:object|null,marker_missing:bool,conflict:bool}
     */
    private function prepareDictionaryMapForApply(
        string $entityType,
        string $externalId,
        $entity,
        ApplyStats $stats
    ): array {
        $mapRow = $this->map->find($entityType, $externalId);
        $markerRows = $this->dictionaryMarkerRows($entity, $externalId);
        if (count($markerRows) > 1) {
            $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'duplicate marker');

            return ['row' => null, 'marker_missing' => false, 'conflict' => true];
        }

        if ($mapRow !== null) {
            $localId = (int) $mapRow->local_id;
            $local = $localId > 0 ? $entity->findOne(['id' => $localId]) : false;
            if (empty($local)) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'map local_id missing');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
            if (count($markerRows) === 1 && (int) $markerRows[0]->id !== $localId) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'marker-map mismatch');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
            $marker = (string) ($local->coresync_external_id ?? '');
            if ($marker !== '' && $marker !== $externalId) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'marker mismatch');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
            if ($this->localDictionaryMapConflicts($entityType, $externalId, $localId)) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'local id already mapped');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }

            return ['row' => $mapRow, 'marker_missing' => $marker === '', 'conflict' => false];
        }

        if (count($markerRows) === 1) {
            $localId = (int) $markerRows[0]->id;
            if ($localId <= 0 || $this->localDictionaryMapConflicts($entityType, $externalId, $localId)) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'marker local id already mapped');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
            $this->map->recordBind($entityType, $externalId, $localId);

            return [
                'row'            => $this->map->find($entityType, $externalId),
                'marker_missing' => false,
                'conflict'       => false,
            ];
        }

        return ['row' => null, 'marker_missing' => false, 'conflict' => false];
    }

    /**
     * Последний fail-closed барьер перед MAP_CREATE. Bind мог завершиться conflict/unmatched без
     * marker/map, поэтому full заново проверяет exact slug в актуальном локальном состоянии:
     * 0 кандидатов разрешает create; один свободный валидный — marker-first bind и update той же
     * строки; неоднозначность/чужое владение/wrong parent блокируют content write.
     *
     * @param mixed $entity CategoriesEntity|BrandsEntity
     * @return array{row:object|null,marker_missing:bool,conflict:bool}
     */
    private function discoverDictionaryMapBeforeCreate(
        string $entityType,
        string $externalId,
        string $candidateField,
        string $candidateValue,
        $entity,
        ApplyStats $stats,
        ?int $expectedParentId,
        ?string $verifiedSourceId = null
    ): array {
        $candidates = [];
        if ($entityType === Contract::ENTITY_CATEGORY && $verifiedSourceId !== null) {
            $candidates = $this->dictionaryRowsByExactField($entity, 'external_id', $verifiedSourceId);
            if (count($candidates) > 1) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'duplicate verified source id');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
        }
        if (count($candidates) === 0) {
            $candidates = $this->dictionaryCandidateRows($entity, $candidateField, $candidateValue);
        }
        if (count($candidates) === 0) {
            return ['row' => null, 'marker_missing' => false, 'conflict' => false];
        }
        if (count($candidates) > 1) {
            // Формулировка причины у category/brand историческая ('duplicate slug', на неё смотрят
            // замки DictionaryBindTest); у характеристик оператору нужно ИМЯ — по нему он и ищет
            // спорную пару на витрине.
            $this->dictionaryApplyConflict($stats, $entityType, $externalId, $candidateField === 'url'
                ? 'duplicate slug'
                : 'duplicate ' . $candidateField . ' "' . $candidateValue . '"');

            return ['row' => null, 'marker_missing' => false, 'conflict' => true];
        }

        $candidate = $candidates[0];
        $reason = $this->dictionaryCandidateConflictReason(
            $entityType,
            $externalId,
            $candidate,
            $expectedParentId
        );
        if ($reason !== null) {
            $this->dictionaryApplyConflict($stats, $entityType, $externalId, $reason);

            return ['row' => null, 'marker_missing' => false, 'conflict' => true];
        }

        $localId = (int) $candidate->id;
        if ((string) ($candidate->coresync_external_id ?? '') === '') {
            // Marker-first: crash до recordBind сходится следующим full через marker lookup.
            if ($entity->update($localId, ['coresync_external_id' => $externalId]) === false) {
                $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'marker write failed');

                return ['row' => null, 'marker_missing' => false, 'conflict' => true];
            }
        }
        $this->map->recordBind($entityType, $externalId, $localId);

        return [
            'row'            => $this->map->find($entityType, $externalId),
            'marker_missing' => false,
            'conflict'       => false,
        ];
    }

    private function localDictionaryMapConflicts(string $entityType, string $externalId, int $localId): bool
    {
        foreach ($this->map->findByLocalId($entityType, $localId) as $owned) {
            if ((string) $owned->external_id !== $externalId) {
                return true;
            }
        }

        return false;
    }

    private function dictionaryApplyConflict(ApplyStats $stats, string $entityType, string $externalId, string $reason): void
    {
        if ($entityType === Contract::ENTITY_FEATURE) {
            $this->featureApplyConflict($stats, $externalId, $reason);

            return;
        }

        $stats->conflicts++;
        $stats->errors++;
        $sample = $entityType . ' ' . $externalId . ' (' . $reason . ')';
        $this->bindSample($stats, $sample);
        $this->warning('CoreSync apply: conflict ' . $sample);
    }

    /**
     * Конфликт идентичности ХАРАКТЕРИСТИКИ — нефатальный по построению: строка остаётся несвязанной,
     * ни одной записи не делается, но `errors` НЕ растёт. Причина причинная, не вкусовая:
     * {@see self::applyFull} на snapshot v2+ возвращает STATUS_FAILED при phaseErrors > 0, а замер
     * витрины artaz даёт 33 неоднозначных имени из 1 043 — через `dictionaryApplyConflict` они
     * сделали бы КАЖДЫЙ apply вечно провальным, то есть каталог не доезжал бы вовсе.
     * Это осознанное отличие контракта feature-фазы от category/brand.
     */
    private function featureApplyConflict(ApplyStats $stats, string $externalId, string $reason): void
    {
        $stats->conflicts++;
        $sample = Contract::ENTITY_FEATURE . ' ' . $externalId . ' (' . $reason . ')';
        $this->bindSample($stats, $sample);
        $this->warning('CoreSync apply: conflict ' . $sample);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function bindProductLine(array $line, ApplyStats $stats): void
    {
        $productExternal = (string) $line['external_id'];
        $data = (array) $line['data'];
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)
            && $this->productSourceIdentityValidator->hasIdentifiedProduct($data)) {
            $this->bindProductLineBySourceIdentity($productExternal, $data, $stats);

            return;
        }
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

    /**
     * Resolve and validate the complete identified row before the first recordBind call.
     *
     * @param array<string, mixed> $data
     */
    private function bindProductLineBySourceIdentity(
        string $productExternal,
        array $data,
        ApplyStats $stats
    ): bool {
        try {
            $identity = $this->productSourceIdentityValidator->validate($data, $this->sourceInstance);
        } catch (\InvalidArgumentException $e) {
            $this->productIdentityConflict($stats, $productExternal, ['reason' => $e->getMessage()]);

            return false;
        }

        $conflict = $this->sourceIdentityBindConflict($productExternal, $identity);
        if ($conflict !== null) {
            $this->productIdentityConflict($stats, $productExternal, $conflict);

            return false;
        }

        $localProductId = $identity['product_id'];
        $this->map->recordBind(Contract::ENTITY_PRODUCT, $productExternal, $localProductId);
        $stats->bound++;
        foreach ($identity['variants'] as $variantExternal => $localVariantId) {
            $this->map->recordBind(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId);
            $stats->bound++;
        }

        return true;
    }

    /** @param array{product_id:int, variants:array<string,int>} $identity */
    private function sourceIdentityBindConflict(string $productExternal, array $identity): ?array
    {
        $localProductId = $identity['product_id'];
        $products = $this->map->findEntityRows($this->productsEntity, ['id' => $localProductId]);
        if (count($products) !== 1) {
            return ['reason' => 'local product id not found', 'local_product_id' => $localProductId];
        }
        if (!$this->mapTargetAvailable(Contract::ENTITY_PRODUCT, $productExternal, $localProductId)) {
            return ['reason' => 'local product map ownership conflict', 'local_product_id' => $localProductId];
        }

        foreach ($identity['variants'] as $variantExternal => $localVariantId) {
            $rows = $this->map->findEntityRows($this->variantsEntity, ['id' => $localVariantId]);
            $target = ['local_product_id' => $localProductId, 'variant_external_id' => (string) $variantExternal,
                'expected_local_variant_id' => $localVariantId];
            if (count($rows) !== 1 || (int) reset($rows)->product_id !== $localProductId) {
                return ['reason' => 'local variant missing or belongs to another product'] + $target;
            }
            if (!$this->mapTargetAvailable(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId)) {
                if ($this->mediaPreview !== null) {
                    $target = ['mapped_local_variant_id' => $this->map->localId(Contract::ENTITY_VARIANT, (string) $variantExternal)] + $target;
                }
                return ['reason' => 'local variant map ownership conflict'] + $target;
            }
        }

        return null;
    }

    private function mapTargetAvailable(string $entityType, string $externalId, int $localId): bool
    {
        $existing = $this->map->find($entityType, $externalId);
        if ($existing !== null && (int) $existing->local_id !== $localId) {
            return false;
        }
        foreach ($this->map->findByLocalId($entityType, $localId) as $owned) {
            if ((string) $owned->external_id !== $externalId) {
                return false;
            }
        }

        return true;
    }

    private function productIdentityConflict(ApplyStats $stats, string $productExternal, array $conflict): void
    {
        $stats->conflicts++;
        $sample = $productExternal . ' (source identity: ' . $conflict['reason'] . ')';
        if ($this->mediaPreview !== null) {
            $this->mediaPreview->recordIdentityConflict(['product_external_id' => $productExternal] + $conflict);
        }
        $this->bindSample($stats, $sample);
        $this->warning('CoreSync bind: conflict ' . $sample);
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
        // Список документов несётся и в price_stock (product_documents_store, тот же список, что в full).
        if ($this->documentsMode === Contract::PRODUCT_DOCUMENTS_MODE_STORE && $this->mediaPreview === null
            && (int) ($productRow->local_id ?? 0) > 0) {
            $this->productDocumentStore->reconcileProduct(
                $productExternal,
                (int) $productRow->local_id,
                (array) ($data['documents'] ?? []),
                $stats
            );
        }

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
            if (array_key_exists('inventory', $variant)) {
                $target = $this->map->findEntityOne($this->variantsEntity, ['id' => (int) $variantRow->local_id]);
                if ($target === false || (int) $target->product_id !== (int) $productRow->local_id) {
                    throw new ManifestException('Variant inventory mapping belongs to another product');
                }
                $this->applyVariantInventory($variant, (int) $variantRow->local_id);
            }

            // Удержание цены (V4/V5, preflight-валидировано): вариант уже в seenVariants, поэтому не
            // обнуляется; ниже пишется только остаток, цена/валюта/старая цена витрины не трогаются.
            $priceHold = in_array($this->schemaMajor, [4, 5], true)
                && ($variant['price_action'] ?? null) === Contract::FULL_VARIANT_PRICE_HOLD;
            if ($priceHold) {
                $targetId = (int) $variantRow->local_id;
                $target = $targetId > 0
                    ? $this->map->findEntityOne($this->variantsEntity, ['id' => $targetId])
                    : false;
                if (($variant['source_identity']['id'] ?? null) !== (string) $targetId
                    || $target === false || (int) $target->product_id !== (int) $productRow->local_id) {
                    $stats->errors++;
                    $this->warning('CoreSync price_stock: price hold requires its existing mapped variant');

                    continue;
                }
                $stats->variantsPriceHeld++;
            }

            $syncPrices = Contract::syncPrices($this->config());
            // Stock-only hashes exclude ignored money/SKU fields and distinguish the policy.
            // Re-enabling prices returns to the native full/price_stock hash for the same row.
            $hash = $syncPrices ? $this->variantHash($variant) : hash('sha256', (string) json_encode([
                'sync_prices' => false,
                'stock' => $this->normalizedStock($variant),
            ]));
            if ((string) $variantRow->applied_hash === $hash) {
                $stats->skipped++; // цена/сток не изменились — идемпотентный skip

                continue;
            }

            // Only the selected price_stock fields; full-mode create/update stays unchanged.
            $fields = ['stock' => $this->normalizedStock($variant)];
            if ($syncPrices && !$priceHold) {
                $price = (array) ($variant['price'] ?? []);
                $variantCurrency = (string) ($price['currency'] ?? '');
                $currencyId = $this->currencyMap[$variantCurrency] ?? $this->manifestCurrencyId;
                $fields = [
                    'price' => (string) ($price['amount'] ?? '0'),
                    'stock' => $fields['stock'],
                    'currency_id' => (int) $currencyId,
                ];
            }
            $this->variantsEntity->update((int) $variantRow->local_id, $fields);
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
                if ($this->inventoryVariantProducts !== [] && !isset($this->snapshotVariantIds[$externalId])) {
                    $this->clearVariantInventory((string) $externalId, (int) $localVariantId);
                }
                $stats->stockZeroed++;
                $stats->deactivated++;
            }
        }

        return false;
    }

    // ================================================================ boot / currency

    private function boot(bool $selectV3ManifestLanguage = true): void
    {
        $this->map = new MapGateway($this->entityFactory->get(CoreSyncMapEntity::class));
        $this->managedFeatureLocalIds = null; // список управляемых характеристик — свой на каждый прогон
        $this->categoriesEntity = $this->entityFactory->get(CategoriesEntity::class);
        $this->brandsEntity = $this->entityFactory->get(BrandsEntity::class);
        $this->featuresEntity = $this->entityFactory->get(FeaturesEntity::class);
        $this->featuresValuesEntity = $this->entityFactory->get(FeaturesValuesEntity::class);
        $this->productsEntity = $this->entityFactory->get(ProductsEntity::class);
        $this->variantsEntity = $this->entityFactory->get(VariantsEntity::class);
        $this->imagesEntity = $this->entityFactory->get(ImagesEntity::class);
        $this->coresyncImagesEntity = $this->entityFactory->get(CoreSyncImagesEntity::class);
        $this->coresyncCategoryImagesEntity = null;
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            $this->coresyncCategoryImagesEntity = $this->entityFactory->get(CoreSyncCategoryImagesEntity::class);
        }

        $this->redirectsEntity = null;
        if (class_exists(self::REDIRECTS_ENTITY_CLASS)) {
            $this->redirectsEntity = $this->entityFactory->get(self::REDIRECTS_ENTITY_CLASS);
        }

        $cfg = $this->config();
        if ($this->languages !== null) {
            if ($this->schemaMajor >= 3 && $selectV3ManifestLanguage) {
                $this->languages->setLangId($this->productContentLanguageIds[$this->manifestLanguage]);
            } elseif (!empty($cfg['lang_id'])) {
                $this->languages->setLangId((int) $cfg['lang_id']);
            }
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

        $queue = [];
        foreach ($read['lines'] as $index => $line) {
            $queue[] = ['line' => $line, 'raw_json' => $read['raw_lines'][$index] ?? null];
        }
        $progress = true;
        while (!empty($queue) && $progress) {
            $progress = false;
            $deferred = [];
            foreach ($queue as $item) {
                if ($this->applyCategoryLine($item['line'], $stats, $item['raw_json']) === 'defer') {
                    $deferred[] = $item;
                } else {
                    $progress = true;
                }
            }
            $queue = $deferred;
        }
        foreach ($queue as $item) {
            $line = $item['line'];
            $this->warning('CoreSync apply: категория ' . ($line['external_id'] ?? '?') . ' — родитель не применён');
            $stats->errors++;
        }

        return $read['stats']['valid'];
    }

    /**
     * @param array<string, mixed> $line
     * @return string 'done' | 'defer'
     */
    private function applyCategoryLine(array $line, ApplyStats $stats, ?string $rawJson = null): string
    {
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            try {
                $data = $this->categoryV2Validator->validate($line, $this->sourceInstance, $rawJson);
            } catch (ManifestException $e) {
                $stats->errors++;
                $this->warning('CoreSync apply: category v2 row rejected');

                return 'done';
            }
            $externalId = (string) $data['external_id'];
            $hash = (string) $data['hash'];
        } else {
            $externalId = (string) $line['external_id'];
            $hash = (string) $line['hash'];
            $data = (array) $line['data'];
        }

        $identity = $this->prepareDictionaryMapForApply(
            Contract::ENTITY_CATEGORY,
            $externalId,
            $this->categoriesEntity,
            $stats
        );
        if ($identity['conflict']) {
            return 'done';
        }
        $row = $identity['row'];
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            if ($identity['marker_missing']) {
                if ($this->categoriesEntity->update((int) $row->local_id, ['coresync_external_id' => $externalId]) === false) {
                    $this->dictionaryApplyConflict($stats, Contract::ENTITY_CATEGORY, $externalId, 'marker write failed');

                    return 'done';
                }
            }
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('category', $externalId, (int) $row->local_id);
            }
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
        $imageState = $this->schemaMajor === 1 && !empty($data['image_url'])
            ? Contract::IMAGE_STATE_PENDING
            : null;
        $fields = [
            'parent_id'            => $parentId,
            'position'             => (int) ($data['position'] ?? 0),
            'visible'              => !empty($data['is_active']) ? 1 : 0,
            'coresync_external_id' => $externalId,
        ];
        if ($this->schemaMajor === 1) {
            $fields += [
                'name'             => (string) ($data['name'] ?? ''),
                'annotation'       => (string) ($data['annotation_html'] ?? ''),
                'description'      => (string) ($data['description_html'] ?? ''),
                'meta_title'       => (string) ($data['seo_title'] ?? ''),
                'meta_keywords'    => (string) ($data['seo_keywords'] ?? ''),
                'meta_description' => (string) ($data['seo_description'] ?? ''),
            ];
        } else {
            // Durable OkaySat identity is separate from the core wrapper external id.
            $fields['external_id'] = (string) $data['source_id'];
        }
        // v1 empty slug delegates generation; v2/v3 presence is sparse and explicit empty means clear.
        if (($this->schemaMajor === 1 && $slug !== '')
            || (Contract::isSnapshotStructuralV2Plus($this->schemaMajor) && !empty($data['slug_present']))) {
            $fields['url'] = $slug;
        }

        if ($decision === Contract::MAP_CREATE) {
            $identity = $this->discoverDictionaryMapBeforeCreate(
                Contract::ENTITY_CATEGORY,
                $externalId,
                'url',
                $slug,
                $this->categoriesEntity,
                $stats,
                $parentId,
                Contract::isSnapshotStructuralV2Plus($this->schemaMajor) ? (string) $data['source_id'] : null
            );
            if ($identity['conflict']) {
                return 'done';
            }
            if ($identity['row'] !== null) {
                $row = $identity['row'];
                $decision = $this->map->decide($row, $hash);
            }
        }

        if ($decision === Contract::MAP_CREATE) {
            if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
                $localId = $this->createV2Category($fields, (array) $data['translations']);
            } else {
                $localId = (int) $this->categoriesEntity->add($fields);
            }
            if (!$this->urlMatches(
                $this->categoriesEntity,
                $localId,
                $slug,
                Contract::isSnapshotStructuralV2Plus($this->schemaMajor) && !empty($data['slug_present'])
            )) {
                $this->slugMutationError('category', $externalId, $slug, $stats);

                return 'done';
            }
            if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
                $this->reconcileCategoryImage($externalId, $localId, $data);
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
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            $this->applyV2Translations($localId, (array) $data['translations']);
        }
        if (!$this->urlMatches(
            $this->categoriesEntity,
            $localId,
            $slug,
            Contract::isSnapshotStructuralV2Plus($this->schemaMajor) && !empty($data['slug_present'])
        )) {
            $this->slugMutationError('category', $externalId, $slug, $stats);

            return 'done';
        }
        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            // Durable desired image must exist before the row hash can become a skip checkpoint.
            $this->reconcileCategoryImage($externalId, $localId, $data);
        }
        $this->map->recordUpdate($row, $localId, $hash, $imageState);
        $stats->updated++;
        if ($imageState !== null) {
            $stats->imagesPending++;
        }

        return 'done';
    }

    // ---------------------------------------------------------------- brands

    /**
     * Create exactly one structural category while writing the first known translation in its
     * locale; remaining translations are updates of the same local id.  Language state is restored
     * even if an Okay entity operation throws.
     *
     * @param array<string, mixed> $structural
     * @param array<int, array<string, string>> $translations
     */
    private function createV2Category(array $structural, array $translations): int
    {
        $writes = $this->v2TranslationWrites($translations);
        if (empty($writes)) {
            return (int) $this->categoriesEntity->add($structural);
        }

        $originalLanguage = (int) $this->languages->getLangId();
        $firstLanguage = (int) array_key_first($writes);
        try {
            $this->languages->setLangId($firstLanguage);
            $localId = (int) $this->categoriesEntity->add(array_merge($structural, $writes[$firstLanguage]));
            unset($writes[$firstLanguage]);
            foreach ($writes as $languageId => $fields) {
                $this->languages->setLangId((int) $languageId);
                $this->categoriesEntity->update($localId, $fields);
            }

            return $localId;
        } finally {
            $this->languages->setLangId($originalLanguage);
        }
    }

    /** @param array<int, array<string, string>> $translations */
    private function applyV2Translations(int $localId, array $translations): void
    {
        $writes = $this->v2TranslationWrites($translations);
        if (empty($writes)) {
            return;
        }

        $originalLanguage = (int) $this->languages->getLangId();
        try {
            foreach ($writes as $languageId => $fields) {
                $this->languages->setLangId((int) $languageId);
                $this->categoriesEntity->update($localId, $fields);
            }
        } finally {
            $this->languages->setLangId($originalLanguage);
        }
    }

    /**
     * Sparse mapper: omitted fields never enter the write payload; explicit empty strings do.
     * Unknown satellite languages are ignored rather than guessed or redirected to a default.
     *
     * @param array<int, array<string, string>> $translations
     * @return array<int, array<string, string>> local language id => entity fields
     */
    private function v2TranslationWrites(array $translations): array
    {
        $localLanguages = [];
        foreach ($this->languages->getAllLanguages() as $language) {
            $hrefLang = (string) ($language->href_lang ?? '');
            $id = (int) ($language->id ?? 0);
            if ($hrefLang !== '' && $id > 0) {
                $localLanguages[$hrefLang] = $id;
            }
        }

        $mapping = [
            'name' => 'name',
            'seo_title' => 'meta_title',
            'seo_description' => 'meta_description',
            'seo_keywords' => 'meta_keywords',
            'annotation_html' => 'annotation',
            'description_html' => 'description',
        ];
        $writes = [];
        foreach ($translations as $translation) {
            $hrefLang = (string) ($translation['language'] ?? '');
            if (!isset($localLanguages[$hrefLang])) {
                continue;
            }
            $fields = [];
            foreach ($mapping as $source => $target) {
                if (array_key_exists($source, $translation)) {
                    $fields[$target] = (string) $translation[$source];
                }
            }
            if (!empty($fields)) {
                $writes[$localLanguages[$hrefLang]] = $fields;
            }
        }

        return $writes;
    }

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

        $identity = $this->prepareDictionaryMapForApply(
            Contract::ENTITY_BRAND,
            $externalId,
            $this->brandsEntity,
            $stats
        );
        if ($identity['conflict']) {
            return;
        }
        $row = $identity['row'];
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            if ($identity['marker_missing']) {
                if ($this->brandsEntity->update((int) $row->local_id, ['coresync_external_id' => $externalId]) === false) {
                    $this->dictionaryApplyConflict($stats, Contract::ENTITY_BRAND, $externalId, 'marker write failed');

                    return;
                }
            }
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('brand', $externalId, (int) $row->local_id);
            }
            $stats->skipped++;

            return;
        }

        $slug = (string) ($data['slug'] ?? '');
        $fields = [
            'visible'              => !empty($data['is_active']) ? 1 : 0,
            'coresync_external_id' => $externalId,
            'name'                 => (string) ($data['name'] ?? ''),
        ];
        // Пустой slug → url делегирован приёмнику (см. applyProductLine): не пишем, чтобы не затирать.
        if ($slug !== '') {
            $fields['url'] = $slug;
        }

        if ($decision === Contract::MAP_CREATE) {
            $identity = $this->discoverDictionaryMapBeforeCreate(
                Contract::ENTITY_BRAND,
                $externalId,
                'url',
                $slug,
                $this->brandsEntity,
                $stats,
                null
            );
            if ($identity['conflict']) {
                return;
            }
            if ($identity['row'] !== null) {
                $row = $identity['row'];
                $decision = $this->map->decide($row, $hash);
            }
        }

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
            if ($this->schemaMajor === 5 && strpos((string) $line['external_id'], 'attr-') !== 0) {
                $this->applyFeatureV5Line($line, $stats);
            } else {
                $this->applyFeatureLine($line, $stats);
            }
        });
        $stats->errors += $read['broken'];

        return $read['valid'];
    }

    /**
     * Характеристики — единственная словарная фаза, у которой в снапшоте НЕТ идентичности
     * (`feature.schema.json`: name/type/unit/filterable/options). Поэтому discovery существующей
     * строки витрины идёт по ТОЧНОМУ имени (case-sensitive, без нормализации): замер artaz
     * 2026-09-08 дал точное совпадение имени у 1 043 из 1 043 характеристик ядра.
     *
     * Инварианты витрины: `position`, `in_filter`, `url`, `external_id` СВЯЗАННОЙ строки не
     * пишутся никогда — посадочные страницы SeoFilter завязаны на `in_filter`, и «доставка»
     * filterable из ядра стоила бы клиенту фильтров. `in_filter`/`visible`/`external_id` живут
     * только в INSERT (витрина, где характеристики создавал сам модуль, ведёт себя как раньше).
     *
     * @param array<string, mixed> $line
     */
    private function applyFeatureLine(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];
        $name = (string) ($data['name'] ?? '');

        $identity = $this->prepareDictionaryMapForApply(
            Contract::ENTITY_FEATURE,
            $externalId,
            $this->featuresEntity,
            $stats
        );
        if ($identity['conflict']) {
            return;
        }
        $row = $identity['row'];
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('feature', $externalId, (int) $row->local_id);
            }
            $stats->skipped++;

            return;
        }

        if ($decision === Contract::MAP_CREATE) {
            $identity = $this->discoverDictionaryMapBeforeCreate(
                Contract::ENTITY_FEATURE,
                $externalId,
                'name',
                $name,
                $this->featuresEntity,
                $stats,
                null
            );
            if ($identity['conflict']) {
                return;
            }
            if ($identity['row'] !== null) {
                $row = $identity['row'];
                $decision = $this->map->decide($row, $hash);
            }
        }

        if ($decision === Contract::MAP_CREATE) {
            $localId = (int) $this->featuresEntity->add([
                'name'        => $name,
                'in_filter'   => !empty($data['filterable']) ? 1 : 0,
                'visible'     => 1,
                'external_id' => $externalId,
            ]);
            $this->map->recordCreate(Contract::ENTITY_FEATURE, $externalId, $localId, $hash);
            $stats->upserted++;

            return;
        }

        // Связанная строка витрины: из содержимого ядра доезжает ТОЛЬКО имя.
        $localId = (int) $row->local_id;
        $this->featuresEntity->update($localId, ['name' => $name]);
        $this->map->recordUpdate($row, $localId, $hash);
        $stats->updated++;
    }

    /** One mapped feature and one mapped option per stable source identity, regardless of locale. */
    private function applyFeatureV5Line(array $line, ApplyStats $stats): void
    {
        $external = (string) $line['external_id'];
        $hash = $this->v5PolicyHash((string) $line['hash']);
        $parentPolicy = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->parent($external) : null;
        $parentDisplay = $this->v5EffectiveParentDisplay($line, $parentPolicy);
        $featureMap = $this->map->find(Contract::ENTITY_FEATURE, $external);
        $featureCurrent = $featureMap !== null && (string) $featureMap->applied_hash === $hash;
        if ($featureCurrent && $parentPolicy !== null) {
            $featureCurrent = $this->policyTargetDisplayCurrent(
                $this->featuresEntity, (int) $featureMap->local_id, 'name', $parentDisplay
            );
        }
        $optionsCurrent = true;
        foreach ($line['data']['option_records'] as $option) {
            $key = $external . ':' . $option['external_id'];
            if (!isset($this->v5MaterializedOptionKeys[$key])) { continue; }
            $optionPolicy = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($key) : null;
            if ($optionPolicy !== null && ($optionPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                if (!$this->policySplitDisplayCurrent($optionPolicy)) { $optionsCurrent = false; break; }
                continue;
            }
            $optionMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key);
            if ($optionMap === null
                || (string) $optionMap->applied_hash !== $this->v5PolicyHash(
                    hash('sha256', (string) json_encode($option))
                ) || ($optionPolicy !== null && !$this->policyTargetDisplayCurrent(
                    $this->featuresValuesEntity, (int) $optionMap->local_id, 'value', $optionPolicy['display']
                ))) {
                $optionsCurrent = false;
                break;
            }
        }
        if ($featureCurrent && $optionsCurrent) {
            $this->recordV5FeatureShape($external, (int) $featureMap->local_id, $line['data']);
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('feature', $external, (int) $row->local_id);
            }
            $stats->skipped++;
            return;
        }
        if (!array_key_exists($external, $this->v5FeaturePlan)) {
            throw new ManifestException('feature v5 was not ownership-preflighted');
        }
        $previous = (int) $this->languages->getLangId();
        try {
            $this->languages->setLangId($this->productContentLanguageIds[$this->manifestLanguage]);
            $featureId = $this->v5FeaturePlan[$external];
            if ($featureId === 0) {
                $canonicalParent = $this->v5ParentCanonical[$external] ?? $external;
                $fields = [
                    'name'=>$parentPolicy !== null ? $parentPolicy['display']['ru'] : $line['data']['name'],
                    'in_filter'=>($parentPolicy['filterable_resolution']['target']
                        ?? !empty($line['data']['filterable'])) ? 1 : 0,
                    'visible'=>1,
                    'external_id'=>$canonicalParent,
                ];
                if ($canonicalParent !== $external || ($parentPolicy !== null && isset($parentPolicy['create_group']))) {
                    $fields['url'] = Translit::translitAlpha($parentPolicy['display']['ru']);
                }
                $featureId = $this->map->addEntityChecked($this->featuresEntity, $fields, 'feature v5');
                $this->v5FeaturePlan[$external] = $featureId;
                if ($parentPolicy !== null && isset($parentPolicy['create_group'])) {
                    foreach ($this->v5ParentCanonical as $source => $canonical) {
                        if ($canonical === $canonicalParent) { $this->v5FeaturePlan[$source] = $featureId; }
                    }
                }
            }
            if (!$featureCurrent) {
                foreach ($line['data']['translations'] as $translation) {
                    $this->languages->setLangId($this->productContentLanguageIds[$translation['language']]);
                    $language = $translation['language'];
                    $desired = $parentDisplay[$language] ?? $translation['name'];
                    $current = $this->map->findEntityOne($this->featuresEntity, ['id'=>$featureId]);
                    if ($current === false || (string) $current->name !== $desired) {
                        $this->map->updateEntityChecked($this->featuresEntity, $featureId, ['name'=>$desired], 'feature v5 translation');
                    }
                }
            }
            foreach ($line['data']['option_records'] as $option) {
                $key = $external . ':' . $option['external_id'];
                if (!isset($this->v5MaterializedOptionKeys[$key])) { continue; }
                $optionPolicy = $this->v5AdoptionPlan !== null ? $this->v5AdoptionPlan->option($key) : null;
                if ($optionPolicy !== null && ($optionPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                    $this->applyPolicySplitDisplay($optionPolicy);
                    continue;
                }
                if (!array_key_exists($key, $this->v5OptionPlan)) {
                    throw new ManifestException('feature v5 option was not ownership-preflighted');
                }
                $optionHash = $this->v5PolicyHash(hash('sha256', (string) json_encode($option)));
                $optionMap = $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key);
                if ($optionMap !== null && (string) $optionMap->applied_hash === $optionHash
                    && ($optionPolicy === null || $this->policyTargetDisplayCurrent(
                        $this->featuresValuesEntity, (int) $optionMap->local_id, 'value', $optionPolicy['display']
                    ))) { continue; }
                $this->languages->setLangId($this->productContentLanguageIds[$this->manifestLanguage]);
                $valueId = $this->v5OptionPlan[$key];
                $canonicalKey = $this->v5OptionCanonical[$key] ?? $key;
                if ($valueId === 0 && $canonicalKey !== $key) {
                    $valueId = $this->v5OptionPlan[$canonicalKey] ?? 0;
                }
                if ($valueId === 0) {
                    $valueId = $this->map->addEntityChecked($this->featuresValuesEntity, [
                        'feature_id'=>$featureId,
                        'value'=>$option['value'],
                        'translit'=>Translit::translitAlpha($option['value']),
                    ], 'feature value v5');
                    $this->v5OptionPlan[$key] = $valueId;
                    foreach ($this->v5OptionCanonical as $member => $memberCanonical) {
                        if ($memberCanonical === $canonicalKey) {
                            $this->v5OptionPlan[$member] = $valueId;
                        }
                    }
                }
                foreach ($option['translations'] as $translation) {
                    $this->languages->setLangId($this->productContentLanguageIds[$translation['language']]);
                    $language = $translation['language'];
                    $desired = $optionPolicy !== null && isset($optionPolicy['display'][$language])
                        ? $optionPolicy['display'][$language] : $translation['value'];
                    $current = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$valueId]);
                    if ($current === false || (string) $current->value !== $desired) {
                        $this->map->updateEntityChecked($this->featuresValuesEntity, $valueId, ['value'=>$desired], 'feature value v5 translation');
                    }
                }
                if ($optionMap === null) {
                    $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_VALUE, $key, $valueId, $optionHash);
                } else {
                    $this->map->recordUpdateChecked($optionMap, $valueId, $optionHash);
                }
            }
            if ($featureMap === null) {
                $this->map->recordCreateChecked(Contract::ENTITY_FEATURE, $external, $featureId, $hash);
                $stats->upserted++;
            } elseif (!$featureCurrent) {
                $this->map->recordUpdateChecked($featureMap, $featureId, $hash);
                $stats->updated++;
            } else {
                $stats->updated++;
            }
            $this->recordV5FeatureShape($external, $featureId, $line['data']);
        } finally {
            $this->languages->setLangId($previous);
        }
    }

    private function recordV5FeatureShape(string $external, int $localId, array $data): void
    {
        if ($this->v5AdoptionPlan === null || !$this->v5AdoptionPlan->featureLifecycle()
            || $this->map->find(Contract::ENTITY_FEATURE_SOURCE_SHAPE, $external) !== null) {
            return;
        }
        $this->map->recordCreateChecked(Contract::ENTITY_FEATURE_SOURCE_SHAPE, $external, $localId,
            FeatureLineageResolver::shape($data));
    }

    private function v5PolicyHash(string $sourceHash): string
    {
        if ($this->schemaMajor !== 5 || $this->v5AdoptionPlan === null) { return $sourceHash; }
        return hash('sha256', "v5-adoption-policy\0" . $this->v5AdoptionPlan->digest() . "\0" . $sourceHash);
    }

    private function policySplitDisplayCurrent(array $policy): bool
    {
        foreach ($policy['expected_targets'] as $target) {
            $display = $policy['display'] ?? ['ru'=>$target['ru'], 'uk'=>$target['uk']];
            if (!$this->policyTargetDisplayCurrent(
                $this->featuresValuesEntity, $target['id'], 'value', $display
            )) { return false; }
        }
        return true;
    }

    private function policyTargetDisplayCurrent($entity, int $localId, string $field, array $display): bool
    {
        $previous = (int) $this->languages->getLangId();
        try {
            foreach ($this->v5AdoptionPlan === null ? ['ru', 'uk'] : $this->v5AdoptionPlan->languages() as $language) {
                $this->languages->setLangId($this->productContentLanguageIds[$language]);
                $row = $this->map->findEntityOne($entity, ['id'=>$localId]);
                if ($row === false || (string) $row->$field !== $display[$language]) { return false; }
            }
            return true;
        } finally {
            $this->languages->setLangId($previous);
        }
    }

    private function applyPolicySplitDisplay(array $policy): void
    {
        $previous = (int) $this->languages->getLangId();
        try {
            foreach ($policy['expected_targets'] as $target) {
                $display = $policy['display'] ?? ['ru'=>$target['ru'], 'uk'=>$target['uk']];
                foreach (['ru', 'uk'] as $language) {
                    $this->languages->setLangId($this->productContentLanguageIds[$language]);
                    $row = $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$target['id']]);
                    if ($row === false) { throw new ManifestException('v5 adoption split target disappeared'); }
                    if ((string) $row->value !== $display[$language]) {
                        $this->map->updateEntityChecked(
                            $this->featuresValuesEntity, $target['id'], ['value'=>$display[$language]],
                            'feature value v5 approved display'
                        );
                    }
                }
            }
        } finally {
            $this->languages->setLangId($previous);
        }
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
        if ($this->schemaMajor >= 3) {
            $this->applyProductV3Line($line, $stats);

            return;
        }

        $externalId = (string) $line['external_id'];
        $hash = (string) $line['hash'];
        $data = (array) $line['data'];

        $row = $this->map->find(Contract::ENTITY_PRODUCT, $externalId);
        $decision = $this->map->decide($row, $hash);
        if ($decision === Contract::MAP_SKIP) {
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('product', $externalId, (int) $row->local_id);
            }
            $stats->skipped++;

            return;
        }
        if ($decision === Contract::MAP_CREATE
            && $this->map->isBindCompleted()
            && $this->hasExistingProductBindCandidate($externalId, $data)) {
            $this->missingCompletedBindMapError(Contract::ENTITY_PRODUCT, $externalId, $stats);

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
            'brand_id'         => $brandId,
            'visible'          => !empty($data['visible']) ? 1 : 0,
            'external_id'      => $externalId,
            'name'             => (string) ($data['name'] ?? ''),
            'description'      => (string) ($data['description_html'] ?? ''),
            'meta_title'       => (string) ($seo['title'] ?? ''),
            'meta_keywords'    => (string) ($seo['keywords'] ?? ''),
            'meta_description' => (string) ($seo['description'] ?? ''),
        ];
        // Пустой slug ядра = генерация url делегирована приёмнику: НЕ пишем url (иначе на update
        // затрём уже сгенерированный Okay url пустым → фронт-404). Okay сам построит при create /
        // сохранит при update. Стык SAT-RT. Явный непустой slug пишется + сверяется пост-проверкой.
        if ($slug !== '') {
            $fields['url'] = $slug;
        }
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
        $this->reconcileProductFeatureValues($localId, (array) ($data['feature_values'] ?? []), $stats);
        $this->reconcileVariants($localId, (array) ($data['variants'] ?? []), $stats);
        $this->reconcileImages($localId, $externalId, $images, $stats);
    }

    private function hasFullVariantPriceHold(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (($variant['price_action'] ?? null) === Contract::FULL_VARIANT_PRICE_HOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * V3 keeps structural data shared but writes all six texts only in explicit translation
     * contexts. The product checkpoint is reserved before add and finalized after every child.
     *
     * @param array<string, mixed> $line preflight-validated v3 row
     */
    private function applyProductV3Line(array $line, ApplyStats $stats): void
    {
        $externalId = (string) $line['external_id'];
        $hash = $this->v5PolicyHash((string) $line['hash']);
        $data = (array) $line['data'];
        $documents = $this->schemaMajor >= 4 ? (array) $data['documents'] : [];
        $row = $this->map->find(Contract::ENTITY_PRODUCT, $externalId);
        // A price hold is an instruction for an existing local product/variant only.
        // Resolve the exact source identities even after the first bind completed.
        if (in_array($this->schemaMajor, [4, 5], true) && $this->hasFullVariantPriceHold($data['variants'])) {
            if (!$this->bindProductLineBySourceIdentity($externalId, $data, $stats)) {
                $stats->errors++;

                return;
            }
            $row = $this->map->find(Contract::ENTITY_PRODUCT, $externalId);
            if ($row === null || (int) $row->local_id < 1) {
                throw new ManifestException('full variant price hold product bind is missing');
            }
        }
        $preservedStructure = $this->preservedV5MappedProductStructure($row);
        if ($row !== null && (string) ($row->applied_hash ?? '') === $hash) {
            if ($this->schemaMajor >= 4) {
                $this->reconcileProductDocuments($externalId, (int) $row->local_id, $documents, $stats);
            }
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('product', $externalId, (int) $row->local_id);
            }
            $stats->skipped++;

            return;
        }
        if ($row === null
            && $this->map->isBindCompleted()
            && $this->hasExistingProductBindCandidate($externalId, $data)) {
            $this->missingCompletedBindMapError(Contract::ENTITY_PRODUCT, $externalId, $stats);

            return;
        }

        $brandId = 0;
        $brandExternal = $data['brand_external_id'];
        if ($brandExternal !== null) {
            $brandId = (int) ($this->map->localId(Contract::ENTITY_BRAND, (string) $brandExternal) ?? 0);
            if ($brandId < 1) {
                $stats->errors++;
                $this->warning('CoreSync apply: product v3 brand is not mapped');

                return;
            }
        }

        $categories = (array) $data['categories'];
        $mainCategoryId = null;
        if ($categories['primary'] !== null) {
            $mainCategoryId = $this->map->localId(
                Contract::ENTITY_CATEGORY,
                (string) $categories['primary']
            );
            if ($mainCategoryId === null) {
                $stats->errors++;
                $this->warning('CoreSync apply: product v3 primary category is not mapped');

                return;
            }
        }

        $slug = (string) ($data['slug'] ?? '');
        $images = (array) $data['images'];
        $imageState = $images === [] ? null : Contract::IMAGE_STATE_PENDING;
        $structural = [
            'brand_id' => $brandId,
            'visible' => $data['visible'] ? 1 : 0,
            'external_id' => $externalId,
        ];
        if ($slug !== '' && $preservedStructure === null) {
            $structural['url'] = $slug;
        }
        if ($mainCategoryId !== null && $preservedStructure === null) {
            $structural['main_category_id'] = $mainCategoryId;
        }
        if ($preservedStructure !== null) {
            unset($structural['external_id']);
        }

        $createName = $this->v3CreateName((array) $data['translations']);
        $createFlow = $row === null || ($row->applied_hash ?? null) === null;
        if ($row === null && $createName === null) {
            $stats->errors++;
            $this->warning('CoreSync apply: product v3 create requires non-empty manifest-language name');

            return;
        }

        $checkpoint = $row;
        $localId = $row === null || ($row->local_id ?? null) === null ? 0 : (int) $row->local_id;
        if ($row === null) {
            $checkpoint = $this->map->reserveProduct($externalId);
            $createFields = $structural;
            if ($slug === '') {
                $createFields['url'] = $this->nextProductUrl((string) $createName);
            }
            $localId = $this->map->addEntityChecked($this->productsEntity, $createFields, 'product v3');
            $checkpoint = $this->map->attachProduct(
                $checkpoint,
                $externalId,
                $localId,
                $imageState
            );
        } elseif ($localId < 1) {
            if (($row->applied_hash ?? null) !== null) {
                throw new ManifestException('product v3 map has hash without local_id');
            }
            $candidates = $this->map->findEntityRows($this->productsEntity, ['external_id' => $externalId]);
            if (count($candidates) > 1) {
                throw new ManifestException('product v3 pending reservation has ambiguous external_id');
            }
            if (count($candidates) === 1) {
                $localId = (int) reset($candidates)->id;
            } else {
                if ($createName === null) {
                    throw new ManifestException('product v3 create recovery requires manifest-language name');
                }
                $createFields = $structural;
                if ($slug === '') {
                    $createFields['url'] = $this->nextProductUrl($createName);
                }
                $localId = $this->map->addEntityChecked($this->productsEntity, $createFields, 'product v3');
            }
            $checkpoint = $this->map->attachProduct($row, $externalId, $localId, $imageState);
        } else {
            $this->map->updateEntityChecked($this->productsEntity, $localId, $structural, 'product v3');
        }

        $expectedUrl = $preservedStructure === null ? $slug : $preservedStructure['url'];
        if (!$this->urlMatches($this->productsEntity, $localId, $expectedUrl,
            $preservedStructure !== null)) {
            throw new ManifestException('product v3 explicit slug was mutated after write');
        }
        if ($preservedStructure !== null) {
            $after = $this->map->findEntityOne($this->productsEntity, ['id'=>$localId]);
            if ($after === false || !property_exists($after, 'main_category_id')
                || $after->main_category_id !== $preservedStructure['main_category_id']
                || !property_exists($after, 'external_id')
                || $after->external_id !== $preservedStructure['external_id']) {
                throw new ManifestException('v5 mapped product structure or external ID changed');
            }
        }

        $this->applyV3ProductTranslations($localId, (array) $data['translations']);
        if ($preservedStructure === null) {
            $this->reconcileProductCategories($localId, $categories, $mainCategoryId);
        }
        $featureValues = (array) $data['feature_values'];
        $this->v5ProtectedValueIds = [];
        $this->v5CurrentProductExternal = $externalId;
        if ($this->schemaMajor === 5 && $this->v5AdoptionPlan !== null) {
            // Historical holds remain protected when their source pair is no longer projected.
            foreach ($this->v5AdoptionPlan->holds()[$externalId] ?? [] as $hold) {
                foreach ($hold['existing_local_value_ids'] ?? [] as $id) {
                    $this->v5ProtectedValueIds[$id] = $id;
                }
            }
            $featureValues = array_values(array_filter($featureValues, function (array $pair) use ($externalId): bool {
                $key = (string) $pair['feature_external_id'] . ':' . (string) ($pair['option_external_id'] ?? '');
                $hold = $this->v5AdoptionPlan->hold($externalId, $key);
                return $hold === null;
            }));
        }
        try {
            $this->reconcileWithReviewedFeatureLinkAdoptions($externalId, $localId, $featureValues, $stats);
        } catch (ManifestException $e) {
            throw $this->featureConflict($e, ['source_product_id'=>$externalId, 'local_product_id'=>$localId]);
        } finally {
            $this->v5ProtectedValueIds = [];
            $this->v5CurrentProductExternal = '';
        }
        $this->reconcileVariants($localId, (array) $data['variants'], $stats);
        $this->reconcileImages($localId, $externalId, $images, $stats);
        if ($this->schemaMajor >= 4) {
            $this->reconcileProductDocuments($externalId, $localId, $documents, $stats);
        }
        $this->map->finalizeProduct($checkpoint, $externalId, $localId, $hash, $imageState);

        if ($createFlow) {
            $stats->upserted++;
        } else {
            $stats->updated++;
        }
    }

    /** @param object|null $mapRow @return array{url:string,main_category_id:mixed,external_id:mixed}|null */
    private function preservedV5MappedProductStructure($mapRow): ?array
    {
        if ($this->schemaMajor !== 5 || $this->v5AdoptionPlan === null
            || !$this->v5AdoptionPlan->preserveMappedProductStructure()
            || $mapRow === null || ($mapRow->local_id ?? null) === null) {
            return null;
        }
        $localId = (int) $mapRow->local_id;
        if ($localId < 1) { return null; } // Native pending-reservation recovery has no physical row yet.
        $physical = $this->map->findEntityOne($this->productsEntity, ['id'=>$localId]);
        if ($physical === false || (int) ($physical->id ?? 0) !== $localId
            || !is_string($physical->url ?? null) || $physical->url === ''
            || !property_exists($physical, 'main_category_id')
            || !property_exists($physical, 'external_id')) {
            throw new ManifestException('v5 mapped product structure target invalid');
        }
        return ['url'=>$physical->url, 'main_category_id'=>$physical->main_category_id,
            'external_id'=>$physical->external_id];
    }

    /** @param array<int, array<string, mixed>> $documents */
    private function reconcileProductDocuments(string $externalId, int $localId, array $documents, ApplyStats $stats): void
    {
        if ($this->documentsMode === null) {
            // Старая форма от необновлённого ядра (манифест без product_documents): документы строк
            // не применяются и не скачиваются, ранее записанные документы витрины не трогаются.
            if ($documents !== [] && !$this->legacyDocumentsWarned) {
                $this->legacyDocumentsWarned = true;
                $this->warning('CoreSync: ядро прислало документы старой формы, CoreSync 1.9.1 их не применяет,'
                    . ' обновите ядро');
            }

            return;
        }
        // product_documents_store: своё хранилище документов; disabled уже убран в prepare.
        if ($this->documentsMode === Contract::PRODUCT_DOCUMENTS_MODE_STORE && $this->mediaPreview === null) {
            if ($localId < 1) {
                throw new ManifestException('product store documents require local product');
            }
            $this->productDocumentStore->reconcileProduct($externalId, $localId, $documents, $stats);
        }
    }

    /** @param array<int, array<string, mixed>> $translations */
    private function v3CreateName(array $translations): ?string
    {
        foreach ($translations as $translation) {
            if (($translation['language'] ?? null) === $this->manifestLanguage
                && is_string($translation['name'] ?? null)
                && $translation['name'] !== '') {
                return $translation['name'];
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $translations */
    private function applyV3ProductTranslations(int $localId, array $translations): void
    {
        $mapping = [
            'name' => 'name',
            'annotation_html' => 'annotation',
            'description_html' => 'description',
            'seo_title' => 'meta_title',
            'seo_description' => 'meta_description',
            'seo_keywords' => 'meta_keywords',
        ];
        $originalLanguage = (int) $this->languages->getLangId();
        try {
            foreach ($translations as $translation) {
                $hrefLang = (string) $translation['language'];
                $languageId = $this->productContentLanguageIds[$hrefLang];
                $fields = [];
                foreach ($mapping as $source => $target) {
                    if (array_key_exists($source, $translation)) {
                        $fields[$target] = $translation[$source];
                    }
                }
                $this->languages->setLangId($languageId);
                $this->map->updateEntityChecked(
                    $this->productsEntity,
                    $localId,
                    $fields,
                    'product v3 translation ' . $hrefLang
                );
            }
        } finally {
            $this->languages->setLangId($originalLanguage);
        }
    }

    private function nextProductUrl(string $name): string
    {
        $url = str_replace('.', '', Translit::translit($name));
        if ($url === '') {
            $url = 'product';
        }
        while (!empty($this->map->findEntityRows($this->productsEntity, ['url' => $url]))) {
            if (preg_match('/(.+)?_([0-9]+)$/', $url, $parts) === 1) {
                $url = $parts[1] . '_' . ((int) $parts[2] + 1);
            } else {
                $url .= '_1';
            }
        }

        return $url;
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
     * Значения характеристик товара. Разъединение сужено до УПРАВЛЯЕМЫХ характеристик (тех, что
     * есть в карте модуля): `deleteProductValue($productId)` без третьего аргумента сносит ВСЕ
     * значения товара, включая характеристики, которых в снапшоте нет вовсе — на artaz этим
     * исчезли 278 062 связи витрины. Пустой список управляемых id = нечего разъединять: живая
     * сущность на пустом `$featuresIds` роняет фильтр и снова удаляет всё, поэтому фаза выходит
     * до delete.
     *
     * @param array<int, array<string, mixed>> $featureValues
     */
    private function assertReviewedFeatureLinkAdoption(FeatureLinkAdopter $adopter, array $adoption): void
    {
        if (!$adopter->preflight($adoption)) {
            return;
        }
        $key = $adoption['option_key'];
        $policy = $this->v5AdoptionPlan->option($key);
        $this->preflightPolicyOption($policy, $policy['expected_target']['feature_id'],
            $this->map->find(Contract::ENTITY_FEATURE_VALUE, $key));
    }

    private function reconcileWithReviewedFeatureLinkAdoptions(
        string $externalId,
        int $localId,
        array $featureValues,
        ApplyStats $stats
    ): void {
        $adoptions = $this->schemaMajor === 5 && $this->v5AdoptionPlan !== null
            ? $this->v5AdoptionPlan->featureLinkAdoptions($externalId) : [];
        if ($adoptions === []) {
            $this->reconcileProductFeatureValues($localId, $featureValues, $stats);
            return;
        }
        if ($this->featureLinkAdoptionTransaction === null) {
            throw new ManifestException('v5 feature-link adoption transaction service unavailable');
        }
        $committed = new ApplyStats();
        $this->featureLinkAdoptionTransaction->run($localId, $adoptions,
            function () use ($externalId, $localId, $featureValues, $adoptions, $committed): void {
                $adopter = new FeatureLinkAdopter($this->map, $this->featuresValuesEntity);
                foreach ($adoptions as $adoption) {
                    if ($adoption['local_product_id'] !== $localId) {
                        throw new ManifestException('v5 feature-link adoption local product drift: ' . $externalId);
                    }
                    $this->assertReviewedFeatureLinkAdoption($adopter, $adoption);
                    if ($adopter->adopt($adoption)) { ++$committed->featureLinksAdopted; }
                }
                $this->reconcileProductFeatureValues($localId, $featureValues, $committed);
            });
        $stats->featureLinksAdopted += $committed->featureLinksAdopted;
        $stats->featureLinksAdded += $committed->featureLinksAdded;
        $stats->featureLinksRemoved += $committed->featureLinksRemoved;
        $stats->featureLinksProtected += $committed->featureLinksProtected;
    }

    private function reconcileProductFeatureValues(int $productId, array $featureValues, ApplyStats $stats): void
    {
        if ($this->schemaMajor === 5) {
            $desired = [];
            $exactOnly = [];
            foreach ($featureValues as $index => $pair) {
                $featureExternal = (string) ($pair['feature_external_id'] ?? '');
                if (strpos($featureExternal, 'attr-') === 0) { continue; }
                $optionKey = $featureExternal . ':' . (string) ($pair['option_external_id'] ?? '');
                $optionPolicy = $this->v5AdoptionPlan !== null
                    ? $this->v5AdoptionPlan->option($optionKey) : null;
                if ($optionPolicy !== null
                    && ($optionPolicy['mode'] ?? null) === 'preserve_existing_equivalent_set') {
                    $linked = $this->map->productValueIdsChecked($this->featuresValuesEntity, $productId);
                    $matches = array_values(array_intersect($optionPolicy['local_ids'], $linked));
                    if ($matches === []) {
                        $target = $this->v5AdoptionPlan->productTarget($this->v5CurrentProductExternal, $optionKey);
                        if ($target === null) {
                            throw new ManifestException('product v5 split option has no existing physical link');
                        }
                        $matches = [$target['local_value_id']];
                    }
                    foreach ($matches as $valueId) { $desired[$index . ':' . $valueId] = $valueId; }
                    continue;
                }
                $featureId = $this->map->localId(Contract::ENTITY_FEATURE, $featureExternal);
                $valueId = $this->map->localId(Contract::ENTITY_FEATURE_VALUE, $optionKey);
                $row = $valueId !== null
                    ? $this->map->findEntityOne($this->featuresValuesEntity, ['id'=>$valueId])
                    : false;
                if ($featureId === null || $row === false || (int) $row->feature_id !== $featureId) {
                    throw new ManifestException('product v5 option map missing or mismatched before link changes');
                }
                $desired[$index] = $valueId;
            }
            foreach ($featureValues as $index => $pair) {
                $featureExternal = (string) ($pair['feature_external_id'] ?? '');
                $value = (string) ($pair['value'] ?? '');
                if (strpos($featureExternal, 'attr-') !== 0 || $value === '') { continue; }
                $featureId = $this->map->localId(Contract::ENTITY_FEATURE, $featureExternal);
                if ($featureId !== null) {
                    $valueId = $this->resolveFeatureValueId($featureId, $value, $stats);
                    if ($valueId > 0) {
                        $desired[$index] = $valueId;
                        $exactOnly[$valueId] = $valueId;
                    }
                }
            }
            foreach ($this->v5ProtectedValueIds as $valueId) { $desired['held:' . $valueId] = $valueId; }
            (new FeatureLinkReconciler(
                $this->map,
                $this->featuresValuesEntity,
                $this->languages,
                $this->v5LinkLanguageIds
            ))->reconcile($productId, array_values($desired), array_values($exactOnly), $stats);
            return;
        }

        $managedFeatureIds = $this->managedFeatureLocalIds();
        if ($managedFeatureIds === []) {
            return;
        }

        $this->featuresValuesEntity->deleteProductValue($productId, null, $managedFeatureIds);

        foreach ($featureValues as $index => $pair) {
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

            $valueId = $this->resolveFeatureValueId($featureId, $value, $stats);
            if ($valueId > 0) {
                $this->featuresValuesEntity->addProductValue($productId, $valueId);
            }
        }
    }

    /**
     * Локальные id характеристик, которыми владеет модуль. Читаются ОДИН раз за прогон: список
     * общий для всех товаров, а фаза характеристик отрабатывает раньше товарной (orderedFiles),
     * поэтому карта к первому обращению уже полна.
     *
     * @return array<int, int>
     */
    private function managedFeatureLocalIds(): array
    {
        if ($this->managedFeatureLocalIds === null) {
            $this->managedFeatureLocalIds = array_values(array_unique(
                array_map('intval', $this->map->allLocalIds(Contract::ENTITY_FEATURE))
            ));
        }

        return $this->managedFeatureLocalIds;
    }

    /**
     * Id строки значения без вторых копий. Порядок: точный `translit` → точное `value` → вставка.
     * `value`-ветка нужна там, где транслит писал не текущий `Translit::translitAlpha` (другой
     * импортёр/ручная правка в админке): без неё уникальный ключ `(feature_id, translit)` не
     * спасает, а вторая строка того же значения расщепляет фильтр витрины.
     *
     * Провал `add()` = отказ уникального ключа (живой CRUD::add возвращает false при пустом
     * insertId): строку успел закоммитить конкурирующий прогон (HTTP против CLI, долг
     * D-CORESYNC-LOCK-PRIVATE-TMP-NOT-SHARED) — повторяем lookup вместо второй вставки.
     */
    private function resolveFeatureValueId(int $featureId, string $value, ApplyStats $stats): int
    {
        $translit = Translit::translitAlpha($value);
        $valueId = $this->findFeatureValueId($featureId, $value, $translit);
        if ($valueId > 0) {
            return $valueId;
        }

        $valueId = (int) $this->featuresValuesEntity->add([
            'feature_id' => $featureId,
            'value'      => $value,
            'translit'   => $translit,
        ]);
        if ($valueId > 0) {
            return $valueId;
        }

        $valueId = $this->findFeatureValueId($featureId, $value, $translit);
        if ($valueId > 0) {
            return $valueId;
        }

        $stats->errors++;
        $this->error(sprintf(
            'CoreSync apply: значение характеристики не создано и не найдено (feature_id=%d, translit=%s)',
            $featureId,
            $translit
        ));

        return 0;
    }

    private function findFeatureValueId(int $featureId, string $value, string $translit): int
    {
        foreach ($this->featuresValuesEntity->find(['feature_id' => $featureId, 'translit' => $translit]) as $existingValue) {
            return (int) $existingValue->id;
        }
        foreach ($this->featuresValuesEntity->find(['feature_id' => $featureId, 'value' => $value]) as $existingValue) {
            return (int) $existingValue->id;
        }

        return 0;
    }

    /**
     * Варианты товара: upsert по external_id. stock сохраняет explicit NULL как безлимитное наличие;
     * отсутствующий ключ fail-safe нормализуется в 0. Исчезнувший вариант → stock=0. Каждый вариант
     * получает СВОЮ строку карты (entity_type=variant) с per-variant hash — основа bind/price_stock
     * (variant-grain, line-item M3 §0.1).
     *
     * @param array<int, array<string, mixed>> $variants
     */
    private function reconcileVariants(int $productId, array $variants, ApplyStats $stats): void
    {
        $existingByExternal = []; // external_id => variantRow витрины (легаси/уже синхронизированные)
        $existingBySku = []; // exact SKU => rows того же товара (bind-candidate при потерянной variant-map)
        $selectedLocalVariantIds = [];
        foreach ($this->map->findEntityRows($this->variantsEntity, ['product_id' => $productId]) as $variantRow) {
            $existingByExternal[(string) $variantRow->external_id] = $variantRow;
            $sku = (string) ($variantRow->sku ?? '');
            if ($sku !== '') {
                $existingBySku[$sku][] = $variantRow;
            }
        }

        $snapshotIds = [];
        $bindCompleted = $this->map->isBindCompleted();
        $syncPrices = Contract::syncPrices($this->config());
        $preserveMappedStock = $this->schemaMajor === 5 && $this->v5AdoptionPlan !== null
            && $this->v5AdoptionPlan->preserveMappedStock();
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
                'stock'       => $this->normalizedStock($variant),
                'currency_id' => (int) $currencyId,
                'external_id' => $variantExternal, // проставляем ключ ядра (после bind — впервые)
            ];

            // Локализация варианта: variant-карта (bound/синхронизированный) → external_id витрины → создать.
            $mapRow = $this->map->find(Contract::ENTITY_VARIANT, $variantExternal);
            if (($variant['price_action'] ?? null) === Contract::FULL_VARIANT_PRICE_HOLD) {
                $targetId = (int) ($mapRow->local_id ?? 0);
                $targets = $targetId > 0
                    ? $this->map->findEntityRows($this->variantsEntity, ['id' => $targetId])
                    : [];
                if (!in_array($this->schemaMajor, [4, 5], true)
                    || ($variant['source_identity']['id'] ?? null) !== (string) $targetId
                    || $targetId < 1 || count($targets) !== 1 || (int) reset($targets)->product_id !== $productId) {
                    $stats->errors++;
                    $this->warning('CoreSync apply: full variant price hold requires its existing mapped variant');

                    return;
                }
                unset($fields['price'], $fields['currency_id']);
                if ($this->mediaPreview !== null) {
                    $this->mediaPreview->recordPriceHold($variantExternal, reset($targets));
                }
                $selectedLocalVariantIds[$targetId] = true;
            }
            $preservedVariant = $this->preservedV5MappedVariantExternalId($mapRow, $productId);
            if ($preservedVariant !== null) {
                unset($fields['external_id']);
            }
            if (!$syncPrices && (($mapRow !== null && $mapRow->local_id !== null)
                || isset($existingByExternal[$variantExternal]))) {
                unset($fields['price'], $fields['currency_id']);
            }
            if ($preserveMappedStock && (($mapRow !== null && $mapRow->local_id !== null)
                || isset($existingByExternal[$variantExternal]))) {
                unset($fields['stock']);
            }
            if ($mapRow !== null && $mapRow->local_id !== null) {
                $localVariantId = (int) $mapRow->local_id;
                if ($this->schemaMajor >= 3) {
                    $this->map->updateEntityChecked(
                        $this->variantsEntity,
                        $localVariantId,
                        $fields,
                        'variant v3'
                    );
                } else {
                    $this->variantsEntity->update($localVariantId, $fields);
                }
            } elseif (isset($existingByExternal[$variantExternal])) {
                $localVariantId = (int) $existingByExternal[$variantExternal]->id;
                if (array_key_exists('inventory', $variant)
                    && !$this->mapTargetAvailable(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId)) {
                    throw new ManifestException('Variant inventory fallback is already owned: ' . $variantExternal);
                }
                if ($this->schemaMajor >= 3) {
                    $this->map->updateEntityChecked(
                        $this->variantsEntity,
                        $localVariantId,
                        $fields,
                        'variant v3'
                    );
                } else {
                    $this->variantsEntity->update($localVariantId, $fields);
                }
            } elseif ($bindCompleted && isset($existingBySku[$fields['sku']])) {
                $this->missingCompletedBindMapError(Contract::ENTITY_VARIANT, $variantExternal, $stats);

                continue;
            } else {
                $localVariantId = $this->schemaMajor >= 3
                    ? $this->map->addEntityChecked($this->variantsEntity, $fields, 'variant v3')
                    : (int) $this->variantsEntity->add($fields);
            }

            if ($preservedVariant !== null) {
                if ($this->preservedV5MappedVariantExternalId($mapRow, $productId) !== $preservedVariant) {
                    throw new ManifestException('v5 mapped variant external ID changed');
                }
                $selectedLocalVariantIds[$localVariantId] = true;
            }
            $this->recordVariantMap($variantExternal, $localVariantId, $this->variantHash($variant));
            $this->applyVariantInventory($variant, $localVariantId);
        }

        // Исчезнувшие варианты (по ключу ядра) существующего товара → stock=0 (деактивация без удаления).
        foreach ($existingByExternal as $variantExternal => $variantRow) {
            if ($variantExternal !== '' && !isset($snapshotIds[$variantExternal])) {
                if (isset($selectedLocalVariantIds[(int) $variantRow->id])) { continue; }
                if ($preserveMappedStock) { continue; }
                if ($this->schemaMajor >= 3) {
                    $this->map->updateEntityChecked(
                        $this->variantsEntity,
                        (int) $variantRow->id,
                        ['stock' => 0],
                        'stale variant v3'
                    );
                } else {
                    $this->variantsEntity->update((int) $variantRow->id, ['stock' => 0]);
                }
                if ($this->inventoryVariantProducts !== [] && !isset($this->snapshotVariantIds[$variantExternal])) {
                    $this->clearVariantInventory($variantExternal, (int) $variantRow->id);
                }
                $stats->deactivated++;
            }
        }
    }

    /** @param object|null $mapRow @return array{id:int,external_id:mixed}|null */
    private function preservedV5MappedVariantExternalId($mapRow, int $productId): ?array
    {
        if ($this->schemaMajor !== 5 || $this->v5AdoptionPlan === null
            || !$this->v5AdoptionPlan->preserveMappedProductStructure()
            || $mapRow === null || ($mapRow->local_id ?? null) === null
            || (int) $mapRow->local_id < 1) { return null; }
        $localId = (int) $mapRow->local_id;
        $physical = $this->map->findEntityOne($this->variantsEntity, ['id'=>$localId]);
        if ($physical === false || (int) ($physical->id ?? 0) !== $localId
            || (int) ($physical->product_id ?? 0) !== $productId
            || !property_exists($physical, 'external_id')) {
            throw new ManifestException('v5 mapped variant target invalid');
        }
        return ['id'=>$localId, 'external_id'=>$physical->external_id];
    }

    private function assertInventoryOwnership(): void
    {
        if ($this->inventoryVariantProducts === []) {
            return;
        }
        $products = $this->map->allLocalIds(Contract::ENTITY_PRODUCT);
        $allVariantMappings = $this->map->allLocalIds(Contract::ENTITY_VARIANT);
        $localOwnerCounts = array_count_values($allVariantMappings);
        $variants = array_intersect_key($allVariantMappings, $this->inventoryVariantProducts);
        foreach (array_chunk($variants, 500, true) as $chunk) {
            $owners = array_column(
                $this->map->findEntityRows($this->variantsEntity, ['id' => array_values($chunk)]),
                'product_id',
                'id'
            );
            foreach ($chunk as $variantExternal => $localVariantId) {
                if ($localOwnerCounts[$localVariantId] !== 1) {
                    throw new ManifestException('Variant inventory local mapping has multiple owners: ' . $variantExternal);
                }
                $productId = $products[$this->inventoryVariantProducts[$variantExternal]] ?? null;
                if ($productId === null || !isset($owners[$localVariantId])
                    || (int) $owners[$localVariantId] !== $productId) {
                    throw new ManifestException('Variant inventory mapping belongs to another product: ' . $variantExternal);
                }
            }
        }
    }

    private function clearVariantInventory(string $variantExternal, int $localVariantId): void
    {
        $this->applyVariantInventory([
            'external_id' => $variantExternal,
            'inventory' => ['action' => 'replace', 'rows' => []],
        ], $localVariantId);
    }

    private function applyVariantInventory(array $variant, int $localVariantId): void
    {
        if (!array_key_exists('inventory', $variant)) {
            return;
        }
        $rows = VariantInventory::replacement($variant['inventory']);
        if ($this->mediaPreview !== null) {
            $this->mediaPreview->recordVariantInventory((string) $variant['external_id'], $localVariantId, $rows);
        } elseif ($rows !== null) {
            if ($this->variantInventoryStore === null || !$this->settings->get(VariantInventoryStore::READY)) {
                throw new ManifestException('Variant inventory storage has not been upgraded');
            }
            $scope = VariantInventoryStore::scope($this->config());
            $current = $this->variantInventoryStore->forVariants([$localVariantId], $scope)[$localVariantId] ?? [];
            if ($current !== $rows) {
                $this->variantInventoryStore->replace($localVariantId, $scope, $rows);
            }
        }
    }

    private function recordVariantMap(string $variantExternal, int $localVariantId, string $hash): void
    {
        $row = $this->map->find(Contract::ENTITY_VARIANT, $variantExternal);
        if ($row === null) {
            if ($this->schemaMajor >= 3) {
                $this->map->recordCreateChecked(
                    Contract::ENTITY_VARIANT,
                    $variantExternal,
                    $localVariantId,
                    $hash
                );
            } else {
                $this->map->recordCreate(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId, $hash);
            }
        } else {
            if ($this->schemaMajor >= 3) {
                $this->map->recordUpdateChecked($row, $localVariantId, $hash);
            } else {
                $this->map->recordUpdate($row, $localVariantId, $hash);
            }
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
            'stock'    => $this->normalizedStock($variant),
        ];

        if (($variant['price_action'] ?? null) === Contract::FULL_VARIANT_PRICE_HOLD) {
            $canonical['price_action'] = Contract::FULL_VARIANT_PRICE_HOLD;
        }

        return hash('sha256', (string) json_encode($canonical));
    }

    /**
     * Explicit NULL means unlimited stock in OkayCMS. A missing or non-null value keeps the
     * historical fail-safe numeric normalization, so absent snapshot paths remain explicit zero.
     *
     * @param array<string, mixed> $variant
     * @return int|null
     */
    private function normalizedStock(array $variant)
    {
        if (array_key_exists('stock', $variant) && $variant['stock'] === null) {
            return null;
        }

        return (int) ($variant['stock'] ?? 0);
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
        $desired = []; // url_hash => {url, sort, content_sha256}
        foreach ($images as $img) {
            $img = (array) $img;
            $urlHash = (string) ($img['url_hash'] ?? '');
            $url = (string) ($img['url'] ?? '');
            if ($urlHash === '' || $url === '') {
                continue;
            }
            // Хеш СОДЕРЖИМОГО — необязательный ключ снапшота. Отсутствует/негоден ⇒ null, и это
            // значит «усыновлять нельзя, качать», а не «усыновить что угодно».
            $contentSha = $img[Contract::IMAGE_CONTENT_SHA256_KEY] ?? null;
            $desired[$urlHash] = [
                'url'            => $url,
                'sort'           => (int) ($img['sort'] ?? 0),
                'content_sha256' => Contract::isValidContentSha256($contentSha) ? (string) $contentSha : null,
            ];
        }

        $existing = []; // url_hash => durable row
        foreach ($this->coresyncImagesEntity->find(['product_external_id' => $productExternal]) as $imgRow) {
            $existing[(string) $imgRow->url_hash] = $imgRow;
        }

        $installed = []; // url_hash => durable-строка желаемого набора (для переноса файла ниже)
        foreach ($desired as $urlHash => $info) {
            if (isset($existing[$urlHash])) {
                $rowObj = $existing[$urlHash];
                $patch = [];
                if ((int) $rowObj->sort !== $info['sort']) {
                    $patch['sort'] = $info['sort'];
                    if (!empty($rowObj->image_id)) {
                        $imageId = (int) $rowObj->image_id;
                        $image = $this->imagesEntity->get($imageId);
                        $moduleOwnedPosition = (int) $rowObj->sort + 1;
                        if (is_object($image) && (int) ($image->position ?? 0) === $moduleOwnedPosition) {
                            $this->imagesEntity->update($imageId, ['position' => $info['sort'] + 1]);
                        } else {
                            $stats->positionsPreserved++;
                            $this->warning(
                                'CoreSync image: позиция клиента сохранена при изменении sort'
                                . ' (product_id=' . $productId . ', image_id=' . $imageId . ')'
                            );
                        }
                    }
                }
                if ((int) $rowObj->product_local_id !== $productId) {
                    $patch['product_local_id'] = $productId;
                }
                if ($info['content_sha256'] !== null
                    && (string) ($rowObj->content_sha256 ?? '') !== $info['content_sha256']) {
                    $patch[Contract::IMAGE_CONTENT_SHA256_FIELD] = $info['content_sha256'];
                    $patch['state'] = Contract::IMAGE_STATE_PENDING;
                    $patch['attempts'] = 0;
                    $patch['error_code'] = null;
                }
                if (!empty($patch)) {
                    $this->coresyncImagesEntity->update((int) $rowObj->id, $patch);
                }
                $rowId = (int) $rowObj->id;
                $hasImage = !empty($rowObj->image_id);
            } else {
                $rowId = (int) $this->coresyncImagesEntity->add([
                    'product_external_id' => $productExternal,
                    'product_local_id'    => $productId,
                    'url'                 => $info['url'],
                    'url_hash'            => $urlHash,
                    'sort'                => $info['sort'],
                    'state'               => Contract::IMAGE_STATE_PENDING,
                    'attempts'            => 0,
                    'filename'            => null,
                    'image_id'            => null,
                    Contract::IMAGE_CONTENT_SHA256_FIELD => $info['content_sha256'],
                    'error_code'          => null,
                ]);
                $hasImage = false;
            }
            $installed[$urlHash] = [
                'id'             => $rowId,
                'sort'           => $info['sort'],
                'content_sha256' => (string) $info['content_sha256'],
                'has_image'      => $hasImage,
            ];
        }

        // Удалённые из снапшота картинки товара → удаление строк ImagesEntity + durable.
        // 🔴 ДО удаления — перенос уже установленного файла на новую durable-строку ТОГО ЖЕ товара с тем
        // же содержимым. Без переноса смена базы публичного URL (url_hash — хеш URL, он зависит от
        // AWS_URL) делает «пропавшими» ВСЕ строки, и галерея клиента сносится ДО того, как усыновление
        // успеет её подобрать: удаление живёт в текстовой фазе, скачивание — в догоняющей.
        $vanished = [];
        foreach ($existing as $urlHash => $rowObj) {
            if (!isset($desired[$urlHash])) {
                $vanished[$urlHash] = $rowObj;
            }
        }
        $released = $this->rekeyVanishedImages($productId, $vanished, $installed, $stats);

        foreach ($vanished as $rowObj) {
            $imageId = !empty($rowObj->image_id) ? (int) $rowObj->image_id : 0;
            if ($this->mediaPreview !== null) {
                $reused = isset($released[$imageId]);
                $this->mediaPreview->recordProductImage(
                    $rowObj, $reused ? 'retain' : 'unlink', $reused ? 'protected_matching_content' : 'omitted_from_desired'
                );
            }
            if ($imageId > 0 && !isset($released[$imageId])) {
                $this->imagesEntity->delete($imageId);
            }
            $this->coresyncImagesEntity->delete((int) $rowObj->id);
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

    /**
     * Перенос установленного файла галереи с пропавшей durable-строки на новую строку ТОГО ЖЕ товара с
     * тем же содержимым. Пишет ТОЛЬКО durable-строку: ни `ok_images`, ни файлы, ни `main_image_id`
     * (принятая граница усыновления, наследуется дословно; см. {@see GalleryContentAdopter}).
     *
     * Донор годен только в состоянии `done`: лишь у него durable-указатель обязан описывать
     * установленный файл — {@see refreshProductImageState} считает любое другое состояние pending
     * именно потому, что указатель мог остаться на прежней копии. Плюс встречная сверка
     * {@see installedImageMatches}: строка `ok_images` обязана существовать, принадлежать ЭТОМУ
     * товару и иметь то же имя файла. Матчинг по паре (товар, содержимое) — межтоварный перенос
     * структурно невозможен.
     *
     * @param array<string, object>                                                       $vanished  url_hash => durable row
     * @param array<string, array{id:int,sort:int,content_sha256:string,has_image:bool}>  $installed url_hash => новая строка
     * @return array<int, true> image_id, которые НЕЛЬЗЯ удалять: файл перенесён (или перенос не подтверждён)
     */
    private function rekeyVanishedImages(int $productId, array $vanished, array $installed, ApplyStats $stats): array
    {
        $released = [];
        if (empty($vanished) || empty($installed)) {
            return $released;
        }

        $donors = []; // content_sha256 => list<durable row>
        foreach ($vanished as $rowObj) {
            $sha = (string) ($rowObj->content_sha256 ?? '');
            $imageId = !empty($rowObj->image_id) ? (int) $rowObj->image_id : 0;
            if ($imageId <= 0
                || !Contract::isValidContentSha256($sha)
                || (string) $rowObj->state !== Contract::IMAGE_STATE_DONE
                || (int) $rowObj->product_local_id !== $productId
                || (string) ($rowObj->filename ?? '') === ''
                || !$this->installedImageMatches($rowObj, $productId)) {
                continue;
            }
            $donors[$sha][] = $rowObj;
        }
        if (empty($donors)) {
            return $released;
        }

        $needy = [];
        foreach ($installed as $target) {
            if ($target['has_image'] || $target['content_sha256'] === '') {
                continue;
            }
            $needy[] = $target;
        }
        usort($needy, static function (array $a, array $b): int {
            return [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']];
        });

        foreach ($needy as $target) {
            $sha = $target['content_sha256'];
            if (empty($donors[$sha])) {
                continue;
            }
            $donor = array_shift($donors[$sha]);
            $transferred = $this->updateProductImageState($target['id'], [
                'state'    => Contract::IMAGE_STATE_DONE,
                'attempts' => 0,
                'filename' => (string) $donor->filename,
                'image_id' => (int) $donor->image_id,
                'error_code' => null,
            ]);
            // Файл отпускаем в ОБОИХ исходах: подтверждённый перенос — потому что на него теперь
            // указывает новая строка; неподтверждённый — потому что удалить живой файл клиента хуже,
            // чем оставить строку галереи без durable-владельца (её подберёт следующий прогон).
            $released[(int) $donor->image_id] = true;
            if (!$transferred) {
                $this->warning('CoreSync adopt: перенос установленной картинки не подтверждён, файл витрины сохранён');
                continue;
            }
            $stats->imagesAdopted++;
        }

        return $released;
    }

    // ---------------------------------------------------------------- gallery adoption phase

    /**
     * Фаза усыновления галереи по СОДЕРЖИМОМУ — идёт ПЕРЕД догоняющей фазой картинок, чтобы строка,
     * чей файл у товара уже лежит, не качалась второй раз и не создавала вторую строку `ok_images`.
     *
     * 🔴 Матчинг строго по паре (`product_local_id`, content-sha256): кандидаты берутся ТОЛЬКО из
     * галереи ЭТОГО товара. Замер: одни байты лежат под многими строками `ok_images` РАЗНЫХ товаров,
     * поиск по одному хешу выдал бы чужую строку, две durable-строки указали бы на один `image_id`, и
     * цикл удаления снёс бы живую картинку другого товара.
     *
     * Пишет ТОЛЬКО durable-строку и coarse-маркер карты: ни `ok_images`, ни файлы, ни `main_image_id`.
     * Кооперативная отмена между товарами и возобновляемость — как у {@see runImagesPhase}: уже
     * усыновлённые строки уходят в `done` и на следующем проходе не пересчитываются (это и есть кэш
     * хеша своих файлов: пересчёт идёт только по товарам, у которых остались не-`done` строки).
     *
     * @return string Contract::STATUS_CANCELLED | STATUS_APPLIED (не терминальный — индикатор отмены)
     */
    private function runGalleryAdoptionPhase(callable $isCancelled, ApplyStats $stats): string
    {
        if ($this->galleryContentAdopter === null) {
            return Contract::STATUS_APPLIED; // без адоптера фаза не выполняется (rows остаются pending)
        }
        $root = $this->galleryContentAdopter->root();
        if ($root === null) {
            // Небезопасный корень оригиналов: качаем, а не угадываем. Громко — иначе нулевые счётчики
            // усыновления читались бы как «нечего было усыновлять», а не «мы даже не смогли посмотреть».
            $this->warning('CoreSync adopt: усыновление пропущено целиком — корень оригиналов галереи недоступен, картинки поедут скачиванием');

            return Contract::STATUS_APPLIED;
        }

        $byProduct = []; // "localId\0productExternal" => list<row>
        $externalOf = [];
        foreach ($this->coresyncImagesEntity->find([]) as $imgRow) {
            $localId = (int) $imgRow->product_local_id;
            if ($localId <= 0 || (string) $imgRow->state === Contract::IMAGE_STATE_DONE) {
                continue;
            }
            $productExternal = (string) $imgRow->product_external_id;
            $groupKey = $localId . "\0" . $productExternal;
            $byProduct[$groupKey][] = $imgRow;
            $externalOf[$groupKey] = $productExternal;
        }

        foreach ($byProduct as $groupKey => $rows) {
            if ($isCancelled()) {
                $this->info('CoreSync adopt: отмена между товарами');

                return Contract::STATUS_CANCELLED;
            }
            $localId = (int) $rows[0]->product_local_id;
            $productExternal = $externalOf[$groupKey] ?? '';

            $wanted = [];
            foreach ($rows as $imgRow) {
                $sha = (string) ($imgRow->content_sha256 ?? '');
                if (!Contract::isValidContentSha256($sha)) {
                    $stats->imagesAdoptionNoHash++;
                    continue;
                }
                $wanted[] = ['key' => (int) $imgRow->id, 'sha256' => $sha, 'sort' => (int) $imgRow->sort];
            }
            if (empty($wanted)) {
                continue;
            }
            usort($wanted, static function (array $a, array $b): int {
                return [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']];
            });

            // Один image_id — одна durable-строка: лишь done-указатель подтверждает текущее
            // владение. У pending/failed указатель описывает прежнюю копию и не может исключать
            // желаемые байты из кандидатов перепривязки.
            $claimed = [];
            foreach ([['product_local_id' => $localId], ['product_external_id' => $productExternal]] as $filter) {
                foreach ($this->coresyncImagesEntity->find($filter) as $ownedRow) {
                    if ((string) $ownedRow->state === Contract::IMAGE_STATE_DONE
                        && !empty($ownedRow->image_id)) {
                        $claimed[(int) $ownedRow->image_id] = true;
                    }
                }
            }

            $candidates = [];
            foreach ($this->imagesEntity->find(['product_id' => $localId]) as $galleryRow) {
                $imageId = (int) ($galleryRow->id ?? 0);
                $filename = (string) ($galleryRow->filename ?? '');
                if ($imageId <= 0 || $filename === '' || isset($claimed[$imageId])) {
                    continue;
                }
                $candidates[] = [
                    'image_id' => $imageId,
                    'filename' => $filename,
                    'position' => (int) ($galleryRow->position ?? 0),
                ];
            }
            if (empty($candidates)) {
                $stats->imagesAdoptionMissed += count($wanted);
                continue;
            }

            $matched = $this->galleryContentAdopter->match($root, $wanted, $candidates);
            $adopted = false;
            foreach ($wanted as $want) {
                $hit = $matched[$want['key']] ?? null;
                if ($hit === null) {
                    $stats->imagesAdoptionMissed++;
                    continue;
                }
                if (!$this->updateProductImageState((int) $want['key'], [
                    'state'    => Contract::IMAGE_STATE_DONE,
                    'attempts' => 0,
                    'filename' => $hit['filename'],
                    'image_id' => $hit['image_id'],
                    'error_code' => null,
                ])) {
                    $this->warning('CoreSync adopt: durable-запись усыновления не подтверждена, строка останется на скачивание');
                    $stats->imagesAdoptionMissed++;
                    continue;
                }
                if ($this->mediaPreview !== null) {
                    foreach ($rows as $candidateRow) {
                        if ((int) $candidateRow->id === (int) $want['key']) {
                            $adoptedRow = clone $candidateRow;
                            $adoptedRow->image_id = $hit['image_id'];
                            $this->mediaPreview->recordProductImage($adoptedRow, 'reuse', 'matching_local_gallery_content');
                            break;
                        }
                    }
                }
                $stats->imagesAdopted++;
                $adopted = true;
            }
            if ($adopted) {
                $this->refreshProductImageState($localId, $productExternal);
            }
        }

        return Contract::STATUS_APPLIED;
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
        if ($this->imageDownloader === null && $this->mediaPreview === null) {
            return Contract::STATUS_APPLIED; // без загрузчика фаза не выполняется (rows остаются pending)
        }

        // Группируем весь durable-набор по товару. Done-строки не скачиваются повторно, но обязаны
        // участвовать в reconciliation coarse marker после resetForReapply().
        $byProduct = []; // "localId\0productExternal" => list<row>
        $externalOf = []; // pair key => productExternal
        foreach ($this->coresyncImagesEntity->find([]) as $imgRow) {
            $localId = (int) $imgRow->product_local_id;
            if ($localId <= 0) {
                continue;
            }
            $productExternal = (string) $imgRow->product_external_id;
            $groupKey = $localId . "\0" . $productExternal;
            $byProduct[$groupKey][] = $imgRow;
            $externalOf[$groupKey] = $productExternal;
        }

        foreach ($byProduct as $groupKey => $rows) {
            $localId = (int) $rows[0]->product_local_id;
            $productExternal = $externalOf[$groupKey] ?? '';
            $needsMainRefresh = false;
            $forceCoarseImageFailure = false;
            if ($isCancelled()) {
                $this->info('CoreSync images: отмена между товарами');

                return Contract::STATUS_CANCELLED;
            }

            usort($rows, static function ($a, $b): int {
                return (int) $a->sort <=> (int) $b->sort;
            });

            foreach ($rows as $imgRow) {
                if ((string) $imgRow->state === Contract::IMAGE_STATE_DONE) {
                    if ($this->mediaPreview !== null) {
                        $this->mediaPreview->recordProductImage($imgRow, 'retain', 'already_installed');
                    }
                    continue;
                }
                if ($this->isImageRetryExhausted($imgRow)) {
                    // Тик исчерпанную строку не трогает вовсе: ни скачивания, ни попытки, ни записи.
                    continue;
                }
                if ($this->mediaPreview !== null) {
                    $oldDisposition = !empty($imgRow->image_id)
                        ? ($this->exclusivelyOwnedInstalledImage($imgRow, $localId) ? 'unlink' : 'retain') : null;
                    $this->mediaPreview->recordProductImage($imgRow, 'download', 'desired_image_not_installed', $oldDisposition);
                    continue;
                }
                $needsMainRefresh = true;

                $oldImageId = !empty($imgRow->image_id) ? (int) $imgRow->image_id : null;
                $oldFilename = (string) ($imgRow->filename ?? '');
                $attempts = (int) $imgRow->attempts + 1;
                try {
                    $filename = $this->imageDownloader->download((string) $imgRow->url);
                } catch (\Throwable $e) {
                    $this->markProductImageFailed(
                        $imgRow,
                        $attempts,
                        $oldImageId,
                        $oldFilename,
                        $stats,
                        $this->productImageErrorCode('download_exception')
                    );
                    $this->warning('CoreSync image: исключение загрузки, сохранена прежняя managed-копия');

                    continue;
                }
                if ($filename === null) {
                    $this->markProductImageFailed(
                        $imgRow,
                        $attempts,
                        $oldImageId,
                        $oldFilename,
                        $stats,
                        $this->productImageErrorCode('download_failed')
                    );
                    continue;
                }
                // Наблюдаемость: без счётчика скачиваний «ноль скачиваний» неотличимо от невыполненной
                // фазы — она возвращается сразу при отсутствии загрузчика (см. начало метода).
                $stats->imagesDownloaded++;

                $imageId = null;
                try {
                    $addedImageId = $this->imagesEntity->add([
                        'product_id' => $localId,
                        'filename'   => $filename,
                        // 1-based: Okay трактует position 0 как «не задано» → перезаписывает id (стык SAT-RT:
                        // картинка sort=0 иначе уезжала в конец галереи). sort+1 сохраняет порядок по sort.
                        'position'   => (int) $imgRow->sort + 1,
                    ]);
                    $imageId = is_numeric($addedImageId) ? (int) $addedImageId : null;
                    if ($imageId <= 0) {
                        $imageId = null;
                        throw new \RuntimeException('ImagesEntity rejected replacement image');
                    }
                    $durableUpdated = $this->coresyncImagesEntity->update((int) $imgRow->id, [
                        'state'    => Contract::IMAGE_STATE_DONE,
                        'attempts' => $attempts,
                        'filename' => $filename,
                        'image_id' => $imageId,
                        'error_code' => null,
                    ]);
                    if ($durableUpdated === false) {
                        throw new \RuntimeException('Durable image pointer update failed');
                    }
                    if ($oldImageId !== null && $oldImageId !== $imageId) {
                        // Старую row/file удаляем только после доказательства, что это живая копия
                        // ЭТОЙ строки и ни одна соседняя durable-строка уже не владеет ею.
                        if (!$this->exclusivelyOwnedInstalledImage($imgRow, $localId)) {
                            $stats->oldImagesPreserved++;
                            $this->warning(
                                'CoreSync image: прежняя строка галереи сохранена без доказанного единоличного владения'
                                . ' product_id=' . $localId . ' image_id=' . $oldImageId
                            );
                            continue;
                        }

                        // Старая managed row/file доказанно принадлежит только заменяемой строке.
                        try {
                            $oldDeleted = $this->imagesEntity->delete($oldImageId);
                            if ($oldDeleted === false) {
                                throw new \RuntimeException('Old managed image cleanup returned false');
                            }
                        } catch (\Throwable $cleanupError) {
                            $oldImage = $this->imagesEntity->get($oldImageId);
                            if ($oldImage !== null
                                && (int) $oldImage->product_id === $localId
                                && (string) $oldImage->filename === $oldFilename) {
                                // Never delete the replacement while durable still points at it.
                                // First confirm the compensating switch to the proven-live old row.
                                $restoredOldPointer = $this->markProductImageFailed(
                                    $imgRow,
                                    $attempts,
                                    $oldImageId,
                                    $oldFilename,
                                    $stats
                                );
                                if ($restoredOldPointer) {
                                    try {
                                        $replacementDeleted = $this->imagesEntity->delete($imageId);
                                    } catch (\Throwable $rollbackError) {
                                        $replacementDeleted = false;
                                    }
                                    if ($replacementDeleted === false) {
                                        $this->warning('CoreSync image: durable восстановлен на прежнюю копию, cleanup новой managed-строки не выполнен');
                                    } else {
                                        $this->warning('CoreSync image: cleanup прежней managed-строки не выполнен, замена откачена');
                                    }

                                    continue;
                                }

                                // Compensation false/throw means durable still points at the live
                                // replacement. Keep its row/file and make that pointer retryable.
                                $replacementMarkedFailed = $this->updateProductImageState((int) $imgRow->id, [
                                    'state' => Contract::IMAGE_STATE_FAILED,
                                    'attempts' => $attempts,
                                    'filename' => $filename,
                                    'image_id' => $imageId,
                                    'error_code' => 'apply_failed',
                                ]);
                                if (!$replacementMarkedFailed) {
                                    $forceCoarseImageFailure = true;
                                    $this->warning('CoreSync image: не удалось пометить живую replacement-копию failed после сбоя компенсации');
                                }
                                $this->warning('CoreSync image: durable rollback на прежнюю копию не подтверждён, живая replacement-копия сохранена');

                                continue;
                            }
                            // Throw may mean the old row/file was deleted before an extender failed.
                            // The new durable pointer is authoritative; restoring old would be false.
                            $this->warning('CoreSync image: cleanup прежней managed-строки завершился неоднозначно, новая копия сохранена');
                        }
                    }
                } catch (\Throwable $e) {
                    if ($imageId === null) {
                        try {
                            $ownedDeleted = $this->imageDownloader->deleteOwned($filename);
                        } catch (\Throwable $cleanupError) {
                            $ownedDeleted = false;
                        }
                        if ($ownedDeleted === false) {
                            $this->warning('CoreSync image: cleanup свежего unowned-файла не выполнен');
                        }
                        $this->markProductImageFailed($imgRow, $attempts, $oldImageId, $oldFilename, $stats);
                        $this->warning('CoreSync image: замена не установлена, сохранён прежний durable pointer');

                        continue;
                    }

                    if ($imageId !== $oldImageId) {
                        // A throwing durable install is ambiguous. Confirm the durable restore to
                        // the old row before deleting the replacement under every failure path.
                        $restoredOldPointer = $this->markProductImageFailed(
                            $imgRow,
                            $attempts,
                            $oldImageId,
                            $oldFilename,
                            $stats
                        );
                        if (!$restoredOldPointer) {
                            $forceCoarseImageFailure = true;
                            $this->warning('CoreSync image: durable rollback не подтверждён, обе managed-копии сохранены');

                            continue;
                        }

                        try {
                            $replacementDeleted = $this->imagesEntity->delete($imageId);
                            if ($replacementDeleted === false) {
                                $this->warning('CoreSync image: rollback новой managed-строки вернул false');
                            }
                        } catch (\Throwable $cleanupError) {
                            $replacementDeleted = false;
                            $this->warning('CoreSync image: не удалось откатить новую managed-копию после исключения');
                        }
                        if ($replacementDeleted === false) {
                            $this->warning('CoreSync image: durable восстановлен на прежнюю копию, replacement cleanup не выполнен');
                        }
                    }
                    $this->warning('CoreSync image: замена не установлена, сохранён прежний durable pointer');
                }
            }

            if ($needsMainRefresh) {
                $this->assignMainImage($localId);
            }
            $this->refreshProductImageState($localId, $productExternal, $forceCoarseImageFailure);
        }

        return Contract::STATUS_APPLIED;
    }

    /**
     * Исчерпана ли строка для ПУТИ ТИКА: failed с attempts >= капа. Общий предикат обеих очередей
     * (у товарной и категорийной строк поля state/attempts одноимённые). На полном проходе кап не
     * выставлен ($imageTickRetryCap === null) и метод всегда возвращает false.
     *
     * @param object $row
     */
    private function isImageRetryExhausted($row): bool
    {
        return $this->imageTickRetryCap !== null
            && (string) $row->state === Contract::IMAGE_STATE_FAILED
            && (int) $row->attempts >= $this->imageTickRetryCap;
    }

    /**
     * Fail-closed replacement state: retryable failed row keeps the last known-good managed image.
     *
     * @param object $imgRow
     */
    private function markProductImageFailed(
        $imgRow,
        int $attempts,
        ?int $oldImageId,
        string $oldFilename,
        ApplyStats $stats,
        string $errorCode = 'apply_failed'
    ): bool {
        $updated = $this->updateProductImageState((int) $imgRow->id, [
            'state'    => Contract::IMAGE_STATE_FAILED,
            'attempts' => $attempts,
            'filename' => $oldFilename !== '' ? $oldFilename : null,
            'image_id' => $oldImageId,
            'error_code' => substr($errorCode, 0, 64),
        ]);
        $stats->imagesFailed++;

        return $updated;
    }

    private function productImageErrorCode(string $fallback): string
    {
        if ($this->imageDownloader !== null && method_exists($this->imageDownloader, 'lastErrorCode')) {
            try {
                $code = $this->imageDownloader->lastErrorCode();
                if (is_string($code) && $code !== '') {
                    return substr($code, 0, 64);
                }
            } catch (\Throwable $e) {
                // Diagnostic lookup must never replace the original download failure.
            }
        }

        return $fallback;
    }

    /**
     * Durable image-state writes participate in replacement safety: false and throw are both
     * unconfirmed writes and callers must keep whichever managed row is still referenced alive.
     *
     * @param array<string, mixed> $patch
     */
    private function updateProductImageState(int $rowId, array $patch): bool
    {
        try {
            return $this->coresyncImagesEntity->update($rowId, $patch) !== false;
        } catch (\Throwable $e) {
            $this->warning('CoreSync image: durable compensation update завершился исключением');

            return false;
        }
    }

    /**
     * Persist a non-null desired descriptor before the category map hash is checkpointed.  Null is
     * an explicit no-change signal.  A changed descriptor retains the old installed filename until
     * the replacement has passed every downloader check and the category row is updated.
     *
     * @param array<string, mixed> $data normalized v2 category
     */
    private function reconcileCategoryImage(string $externalId, int $localId, array $data): void
    {
        $descriptor = $data['image'] ?? null;
        if ($descriptor === null) {
            return;
        }
        $existing = $this->coresyncCategoryImagesEntity->findOne(['category_external_id' => $externalId]);
        $desired = [
            'category_external_id' => $externalId,
            'category_local_id' => $localId,
            'source_instance' => (string) $data['source_instance'],
            'source_id' => (string) $data['source_id'],
            'url' => (string) $descriptor['url'],
            'sha256' => (string) $descriptor['sha256'],
            'mime' => (string) $descriptor['mime'],
            'bytes' => (int) $descriptor['bytes'],
        ];
        if (empty($existing)) {
            $this->coresyncCategoryImagesEntity->add($desired + [
                'state' => Contract::IMAGE_STATE_PENDING,
                'attempts' => 0,
                'filename' => null,
                'error_code' => null,
            ]);

            return;
        }

        $sameDescriptor = (string) $existing->source_instance === $desired['source_instance']
            && (string) $existing->source_id === $desired['source_id']
            && (string) $existing->url === $desired['url']
            && (string) $existing->sha256 === $desired['sha256']
            && (string) $existing->mime === $desired['mime']
            && (int) $existing->bytes === $desired['bytes'];
        $patch = [];
        if ((int) $existing->category_local_id !== $localId) {
            $patch['category_local_id'] = $localId;
        }
        if (!$sameDescriptor) {
            $patch += $desired;
            $patch['state'] = Contract::IMAGE_STATE_PENDING;
            $patch['attempts'] = 0;
            $patch['error_code'] = null;
            // filename deliberately remains the last successfully installed module-owned file.
        }
        if (!empty($patch)) {
            $this->coresyncCategoryImagesEntity->update((int) $existing->id, $patch);
        }
    }

    /** @return string|null null=success, otherwise a terminal status */
    private function runCategoryImagesPhase(callable $isCancelled, ApplyStats $stats): ?string
    {
        if (!Contract::isSnapshotStructuralV2Plus($this->schemaMajor)) {
            return null;
        }

        if ($this->mediaPreview !== null) {
            foreach ($this->coresyncCategoryImagesEntity->find(['state' => Contract::IMAGE_STATE_DONE]) as $row) {
                $this->mediaPreview->recordCategoryImage($row, 'retain', 'already_installed');
            }
        }
        $pending = [];
        foreach ([Contract::IMAGE_STATE_PENDING, Contract::IMAGE_STATE_FAILED] as $state) {
            foreach ($this->coresyncCategoryImagesEntity->find(['state' => $state]) as $row) {
                if ($this->isImageRetryExhausted($row)) {
                    // Тик исчерпанную строку не трогает вовсе (и не считает её attempted).
                    continue;
                }
                $pending[(int) $row->id] = $row;
            }
        }
        $stats->categoryImagesPending += count($pending);
        foreach ($pending as $row) {
            if ($isCancelled()) {
                return Contract::STATUS_CANCELLED;
            }
            if ($this->mediaPreview !== null) {
                $descriptor = [
                    'url' => (string) $row->url, 'sha256' => (string) $row->sha256,
                    'mime' => (string) $row->mime, 'bytes' => (int) $row->bytes,
                ];
                $identity = (string) $row->source_instance . ':' . (string) $row->source_id;
                $decision = $this->categoryImageDownloader === null
                    ? ['action' => 'download', 'reason' => 'local_asset_state_unavailable']
                    : $this->categoryImageDownloader->previewLocalTarget($descriptor, $identity);
                $this->mediaPreview->recordCategoryImage($row, $decision['action'], $decision['reason']);
                continue;
            }
            if ($this->categoryImageDownloader === null) {
                $this->markCategoryImageFailed($row, 'dependency_unavailable', $stats);
                continue;
            }

            $descriptor = [
                'url' => (string) $row->url,
                'sha256' => (string) $row->sha256,
                'mime' => (string) $row->mime,
                'bytes' => (int) $row->bytes,
            ];
            $identity = (string) $row->source_instance . ':' . (string) $row->source_id;
            $filename = $this->categoryImageDownloader->download($descriptor, $identity);
            if ($filename === null) {
                $this->markCategoryImageFailed(
                    $row,
                    $this->categoryImageDownloader->lastErrorCode() ?? 'download_failed',
                    $stats
                );
                continue;
            }

            $oldFilename = (string) ($row->filename ?? '');
            $updated = $this->categoriesEntity->update((int) $row->category_local_id, ['image' => $filename]);
            if ($updated === false) {
                if ($filename !== $oldFilename) {
                    $this->categoryImageDownloader->deleteOwned($filename);
                }
                $this->markCategoryImageFailed($row, 'category_update_failed', $stats);
                continue;
            }
            $this->coresyncCategoryImagesEntity->update((int) $row->id, [
                'state' => Contract::IMAGE_STATE_DONE,
                'attempts' => (int) $row->attempts + 1,
                'filename' => $filename,
                'error_code' => null,
            ]);
            if ($oldFilename !== '' && $oldFilename !== $filename) {
                $this->categoryImageDownloader->deleteOwned($oldFilename);
            }
        }

        return $stats->categoryImagesFailed > 0 ? Contract::STATUS_FAILED : null;
    }

    /** @param object $row */
    private function markCategoryImageFailed($row, string $code, ApplyStats $stats): void
    {
        $this->coresyncCategoryImagesEntity->update((int) $row->id, [
            'state' => Contract::IMAGE_STATE_FAILED,
            'attempts' => (int) $row->attempts + 1,
            'error_code' => substr($code, 0, 64),
        ]);
        $stats->categoryImagesFailed++;
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

    private function refreshProductImageState(
        int $productId,
        string $productExternal,
        bool $forceFailed = false
    ): void
    {
        if ($productExternal === '') {
            return;
        }
        $anyPending = false;
        $anyFailed = false;
        $rows = $this->coresyncImagesEntity->find([
            'product_local_id' => $productId,
            'product_external_id' => $productExternal,
        ]);
        if (empty($rows)) {
            return; // пустой desired set не доказывает успешное зеркалирование
        }
        foreach ($rows as $imgRow) {
            $state = (string) $imgRow->state;
            if ($state === Contract::IMAGE_STATE_FAILED) {
                $anyFailed = true;
            } elseif ($state !== Contract::IMAGE_STATE_DONE
                || empty($imgRow->image_id)
                || (string) ($imgRow->filename ?? '') === ''
                || !$this->installedImageMatches($imgRow, $productId)) {
                $anyPending = true;
            }
        }
        $state = $forceFailed
            ? Contract::IMAGE_STATE_FAILED
            : ($anyPending
            ? Contract::IMAGE_STATE_PENDING
            : ($anyFailed ? Contract::IMAGE_STATE_FAILED : Contract::IMAGE_STATE_DONE));
        $productMap = $this->map->find(Contract::ENTITY_PRODUCT, $productExternal);
        if ($productMap !== null
            && (int) $productMap->local_id === $productId
            && (string) ($productMap->image_state ?? '') !== $state) {
            $this->map->updateImageState($productExternal, $state);
        }
    }

    /** @param object $imgRow */
    private function exclusivelyOwnedInstalledImage(object $row, int $productId): bool
    {
        if (!$this->installedImageMatches($row, $productId)) {
            return false;
        }
        foreach ($this->coresyncImagesEntity->find(['image_id' => (int) $row->image_id]) as $owner) {
            if ((int) $owner->id !== (int) $row->id) {
                return false;
            }
        }

        return true;
    }

    private function installedImageMatches($imgRow, int $productId): bool
    {
        $image = $this->imagesEntity->get((int) $imgRow->image_id);

        return $image !== null
            && (int) $image->product_id === $productId
            && (string) $image->filename === (string) $imgRow->filename;
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
            if ($this->mediaPreview !== null) {
                $this->mediaPreview->recordSkip('redirect', $externalId, (int) $row->local_id);
            }
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
            // Строка '301', НЕ int: колонка status_code — enum('301',…); int 301 трактуется MySQL как
            // ИНДЕКС enum (вне диапазона) → пустое значение → редирект без кода. Стык SAT-RT.
            'status_code' => '301',
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
        // БЕЗ ведущего слэша: Format/Redirects сверяет request_url с Request::getPageUrl(), который
        // ltrim'ит '/' (стык SAT-RT: с ведущим слэшем редирект не матчился → 404 вместо 301).
        if ($entityType === 'product') {
            $prefix = (string) $this->settings->get('product_routes_template__default');

            return ($prefix !== '' ? $prefix : 'products') . '/' . $slug;
        }
        if ($entityType === 'category') {
            $prefix = (string) $this->settings->get('category_routes_template__default');

            return ($prefix !== '' ? $prefix : 'catalog') . '/' . $slug;
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
        $decision = FullAbsentDecision::fromProducts($mapped, $seenProducts, isset($manifest['absent_policy'])
            ? (string) $manifest['absent_policy'] : null);
        if ($this->mediaPreview !== null) {
            $this->mediaPreview->recordAbsent($decision);
        }
        if (empty($mapped)) {
            return false;
        }
        $absent = $decision->absentProducts();
        $stats->absentCount = count($absent);

        if ($decision->isHeld()) {
            $this->warning(sprintf(
                'CoreSync apply: absent %d из %d (>%d%%) — фаза absent пропущена (held)',
                $stats->absentCount,
                $decision->mappedCount(),
                (int) (Contract::ABSENT_MAX_RATIO * 100)
            ));

            return true;
        }

        $policy = $decision->policy();
        foreach ($absent as $localId) {
            if ($policy === 'hide') {
                $this->productsEntity->update($localId, ['visible' => 0]);
            }
            if ($policy !== 'hide' || $this->inventoryVariantProducts !== []) {
                foreach ($this->variantsEntity->find(['product_id' => $localId]) as $variantRow) {
                    if ($policy !== 'hide') {
                        $this->variantsEntity->update((int) $variantRow->id, ['stock' => 0]);
                    }
                    $variantExternal = (string) $variantRow->external_id;
                    if ($variantExternal !== '' && $this->inventoryVariantProducts !== []
                        && !isset($this->snapshotVariantIds[$variantExternal])) {
                        $this->clearVariantInventory($variantExternal, (int) $variantRow->id);
                    }
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
    private function urlMatches($entity, int $localId, string $expectedSlug, bool $explicit = false): bool
    {
        // Пустой slug ядра = генерация url делегирована приёмнику (Okay строит из имени/id) — коллизию
        // сверять нечего, принимаем сгенерированный url. Пост-проверка ловит мутацию только для ЯВНОГО
        // непустого slug. Стык SAT-RT: без этого демо-товары без канального slug вечно «мутированы» ядром
        // → не попадают в карту → пере-создаются каждый прогон (ломают идемпотентность, растят дубли).
        if ($expectedSlug === '' && !$explicit) {
            return true;
        }
        if ($localId <= 0) {
            return false;
        }
        $saved = $this->map->findEntityOne($entity, ['id' => $localId]);
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

    private function missingCompletedBindMapError(string $type, string $externalId, ApplyStats $stats): void
    {
        $sample = sprintf(
            '%s %s (строка отсутствует в карте при завершённом bind — возможна потеря карты; требуется rebind)',
            $type,
            $externalId
        );
        $stats->errors++;
        $this->bindSample($stats, $sample);
        $this->warning('CoreSync apply: ' . $sample);
    }

    /**
     * Completed-маркер не означает, что каждая будущая строка обязана быть в карте: штатный bind
     * сохраняет unmatched, а новые товары следующих снапшотов законно создаются. Различающий сигнал
     * потери карты — живой exact bind-candidate, который MAP_CREATE пере-создал бы дублем.
     *
     * @param array<string, mixed> $data
     */
    private function hasExistingProductBindCandidate(string $externalId, array $data): bool
    {
        if (!empty($this->map->findEntityRows($this->productsEntity, ['external_id' => $externalId]))) {
            return true;
        }

        if (Contract::isSnapshotStructuralV2Plus($this->schemaMajor)
            && $this->productSourceIdentityValidator->hasIdentifiedProduct($data)) {
            try {
                $identity = $this->productSourceIdentityValidator->validate($data, $this->sourceInstance);
            } catch (\InvalidArgumentException $e) {
                return true; // malformed claimed identity is never permission to create beside live data
            }
            if (!empty($this->map->findEntityRows($this->productsEntity, ['id' => $identity['product_id']]))) {
                return true;
            }
            foreach ($identity['variants'] as $localVariantId) {
                if (!empty($this->map->findEntityRows($this->variantsEntity, ['id' => $localVariantId]))) {
                    return true;
                }
            }

            return false;
        }

        foreach ((array) ($data['variants'] ?? []) as $variant) {
            $sku = (string) (((array) $variant)['sku'] ?? '');
            if ($sku !== '' && !empty($this->map->findEntityRows($this->variantsEntity, ['sku' => $sku]))) {
                return true;
            }
        }

        return false;
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

    /** @param array<string, mixed> $manifest */
    private function schemaMajorFromManifest(array $manifest): int
    {
        $version = $manifest['schema_version'] ?? null;
        if ($version === null) {
            return 1; // legacy direct Applier tests/callers predate the explicit schema field
        }
        if (!is_string($version)
            || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
            throw new ManifestException('Некорректный schema_version перед apply');
        }

        return (int) explode('.', $version, 2)[0];
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
        if ($this->mediaPreview !== null) {
            $this->mediaPreview->recordError($message);
        }
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
