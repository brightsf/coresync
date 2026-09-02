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
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;
use Okay\Modules\Format\CoreSync\Core\FileCheckpointStore;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
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

    public function __construct(
        EntityFactory $entityFactory,
        Settings $settings,
        NdjsonGzReader $reader,
        ?Languages $languages = null,
        ?LoggerInterface $logger = null,
        ?ImageDownloader $imageDownloader = null,
        ?CategoryV2Validator $categoryV2Validator = null,
        ?CategoryImageDownloader $categoryImageDownloader = null,
        ?GalleryContentAdopter $galleryContentAdopter = null
    ) {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->reader = $reader;
        $this->languages = $languages;
        $this->logger = $logger;
        $this->imageDownloader = $imageDownloader;
        $this->categoryV2Validator = $categoryV2Validator ?? new CategoryV2Validator();
        $this->productSourceIdentityValidator = new ProductSourceIdentityValidator();
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
        $this->schemaMajor = $schemaMajor ?? $this->schemaMajorFromManifest($manifest);
        if (!in_array($this->schemaMajor, Contract::SNAPSHOT_SCHEMA_MAJORS, true)) {
            throw new ManifestException('Неподдерживаемый schema major перед apply');
        }
        $config = $this->config();
        $this->sourceInstance = $sourceInstance ?? (string) ($config[Contract::SETTINGS_SOURCE_INSTANCE_FIELD] ?? '');
        if ($this->schemaMajor === 2 && !Contract::isValidSourceInstance($this->sourceInstance)) {
            throw new ManifestException('Для snapshot v2 обязателен безопасный source_instance');
        }
        if ($this->schemaMajor === 2 && $this->languages === null) {
            throw new ManifestException('Для snapshot v2 недоступен сервис Languages');
        }
        $this->boot();

        // --- Bind-фаза: карта пуста (product+variant) И каталог непуст → связывание, каталог не пишется ---
        if ($this->shouldBind()) {
            return $this->runBind($manifest, $stagingDir, $checkpoints, $isCancelled, $stats);
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
        $this->boot();

        $productImagesStatus = $this->runImagesPhase($isCancelled, $stats);
        if ($productImagesStatus === Contract::STATUS_CANCELLED) {
            return $productImagesStatus;
        }
        $categoryImagesStatus = $this->runCategoryImagesPhase($isCancelled, $stats);

        return $categoryImagesStatus ?? $productImagesStatus;
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
            if ($this->schemaMajor === 2 && $phaseErrors > 0) {
                return Contract::STATUS_FAILED; // strict v2: do not checkpoint a partially rejected file
            }

            $checkpoints->setStatus($name, Contract::FILE_APPLIED);
        }

        // --- absent-фаза (только записи карты, отсутствующие в снапшоте) ---
        if ($isCancelled()) {
            return Contract::STATUS_CANCELLED;
        }
        $held = $this->applyAbsent($manifest, $stats, $seenProducts);

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
            if ($this->schemaMajor === 2 && $stats->errors > $errorsBefore) {
                return Contract::STATUS_FAILED; // do not checkpoint an invalid v2 dictionary file
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
        if ($this->schemaMajor === 2) {
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
            $this->schemaMajor === 2 ? (string) $data['source_id'] : null
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
        if ($slug === '') {
            return [];
        }

        return $this->dictionaryRowsByExactField($entity, 'url', $slug);
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
        string $slug,
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
            $candidates = $this->dictionarySlugRows($entity, $slug);
        }
        if (count($candidates) === 0) {
            return ['row' => null, 'marker_missing' => false, 'conflict' => false];
        }
        if (count($candidates) > 1) {
            $this->dictionaryApplyConflict($stats, $entityType, $externalId, 'duplicate slug');

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
        $stats->conflicts++;
        $stats->errors++;
        $sample = $entityType . ' ' . $externalId . ' (' . $reason . ')';
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
        if ($this->schemaMajor === 2 && $this->productSourceIdentityValidator->hasIdentifiedProduct($data)) {
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
    ): void {
        try {
            $identity = $this->productSourceIdentityValidator->validate($data, $this->sourceInstance);
        } catch (\InvalidArgumentException $e) {
            $this->productIdentityConflict($stats, $productExternal, $e->getMessage());

            return;
        }

        $localProductId = $identity['product_id'];
        $products = $this->productsEntity->find(['id' => $localProductId]);
        if (count($products) !== 1) {
            $this->productIdentityConflict($stats, $productExternal, 'local product id not found');

            return;
        }
        if (!$this->mapTargetAvailable(Contract::ENTITY_PRODUCT, $productExternal, $localProductId)) {
            $this->productIdentityConflict($stats, $productExternal, 'local product map ownership conflict');

            return;
        }

        foreach ($identity['variants'] as $variantExternal => $localVariantId) {
            $rows = $this->variantsEntity->find(['id' => $localVariantId]);
            if (count($rows) !== 1 || (int) reset($rows)->product_id !== $localProductId) {
                $this->productIdentityConflict($stats, $productExternal, 'local variant missing or belongs to another product');

                return;
            }
            if (!$this->mapTargetAvailable(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId)) {
                $this->productIdentityConflict($stats, $productExternal, 'local variant map ownership conflict');

                return;
            }
        }

        $this->map->recordBind(Contract::ENTITY_PRODUCT, $productExternal, $localProductId);
        $stats->bound++;
        foreach ($identity['variants'] as $variantExternal => $localVariantId) {
            $this->map->recordBind(Contract::ENTITY_VARIANT, $variantExternal, $localVariantId);
            $stats->bound++;
        }
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

    private function productIdentityConflict(ApplyStats $stats, string $productExternal, string $reason): void
    {
        $stats->conflicts++;
        $sample = $productExternal . ' (source identity: ' . $reason . ')';
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
                'stock'       => $this->normalizedStock($variant),
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
        $this->coresyncCategoryImagesEntity = null;
        if ($this->schemaMajor === 2) {
            $this->coresyncCategoryImagesEntity = $this->entityFactory->get(CoreSyncCategoryImagesEntity::class);
        }

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
        if ($this->schemaMajor === 2) {
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
        // v1 empty slug delegates generation; v2 presence is sparse and explicit empty means clear.
        if (($this->schemaMajor === 1 && $slug !== '')
            || ($this->schemaMajor === 2 && !empty($data['slug_present']))) {
            $fields['url'] = $slug;
        }

        if ($decision === Contract::MAP_CREATE) {
            $identity = $this->discoverDictionaryMapBeforeCreate(
                Contract::ENTITY_CATEGORY,
                $externalId,
                $slug,
                $this->categoriesEntity,
                $stats,
                $parentId,
                $this->schemaMajor === 2 ? (string) $data['source_id'] : null
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
            if ($this->schemaMajor === 2) {
                $localId = $this->createV2Category($fields, (array) $data['translations']);
            } else {
                $localId = (int) $this->categoriesEntity->add($fields);
            }
            if (!$this->urlMatches($this->categoriesEntity, $localId, $slug, $this->schemaMajor === 2 && !empty($data['slug_present']))) {
                $this->slugMutationError('category', $externalId, $slug, $stats);

                return 'done';
            }
            if ($this->schemaMajor === 2) {
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
        if ($this->schemaMajor === 2) {
            $this->applyV2Translations($localId, (array) $data['translations']);
        }
        if (!$this->urlMatches($this->categoriesEntity, $localId, $slug, $this->schemaMajor === 2 && !empty($data['slug_present']))) {
            $this->slugMutationError('category', $externalId, $slug, $stats);

            return 'done';
        }
        if ($this->schemaMajor === 2) {
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
        foreach ($this->map->findEntityRows($this->variantsEntity, ['product_id' => $productId]) as $variantRow) {
            $existingByExternal[(string) $variantRow->external_id] = $variantRow;
            $sku = (string) ($variantRow->sku ?? '');
            if ($sku !== '') {
                $existingBySku[$sku][] = $variantRow;
            }
        }

        $snapshotIds = [];
        $bindCompleted = $this->map->isBindCompleted();
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
            if ($mapRow !== null && $mapRow->local_id !== null) {
                $localVariantId = (int) $mapRow->local_id;
                $this->variantsEntity->update($localVariantId, $fields);
            } elseif (isset($existingByExternal[$variantExternal])) {
                $localVariantId = (int) $existingByExternal[$variantExternal]->id;
                $this->variantsEntity->update($localVariantId, $fields);
            } elseif ($bindCompleted && isset($existingBySku[$fields['sku']])) {
                $this->missingCompletedBindMapError(Contract::ENTITY_VARIANT, $variantExternal, $stats);

                continue;
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
            'stock'    => $this->normalizedStock($variant),
        ];

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
        if ($this->imageDownloader === null) {
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
                    continue;
                }
                if ($this->isImageRetryExhausted($imgRow)) {
                    // Тик исчерпанную строку не трогает вовсе: ни скачивания, ни попытки, ни записи.
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
                        $oldImageExclusivelyOwned = $this->installedImageMatches($imgRow, $localId);
                        if ($oldImageExclusivelyOwned) {
                            foreach ($this->coresyncImagesEntity->find(['image_id' => $oldImageId]) as $ownerRow) {
                                if ((int) $ownerRow->id !== (int) $imgRow->id) {
                                    $oldImageExclusivelyOwned = false;
                                    break;
                                }
                            }
                        }
                        if (!$oldImageExclusivelyOwned) {
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
        if ($this->schemaMajor !== 2) {
            return null;
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

        if ($this->schemaMajor === 2 && $this->productSourceIdentityValidator->hasIdentifiedProduct($data)) {
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
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
