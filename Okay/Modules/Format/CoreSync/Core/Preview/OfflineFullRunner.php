<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use LogicException;
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
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentSynchronizer;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\FeatureLifecycleConflict;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncDocumentsEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use Okay\Modules\Format\ProductDocuments\Entities\ProductDocumentsEntity;

final class OfflineFullRunner
{
    public function run(string $packageDir, string $stateJson, string $assetRoot): array
    {
        $validator = new ManifestValidator();
        $package = OfflineFullPackage::open($packageDir, $validator);
        $state = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['format'] ?? null) !== 1) {
            throw new LogicException('Unsupported offline receiver state');
        }
        $manifest = $package->manifest();
        $major = $validator->major($manifest);
        $settings = new PreviewSettings($state['scope']);
        $language = new PreviewLanguages($state['languages'], $state['main_language_id'], $state['current_language_id']);
        $factory = $this->entityFactory($state['tables'], $language, $major);
        $lifecycle = new PreviewFeatureLifecycle($factory->get(CoreSyncMapEntity::class),
            $factory->get(FeaturesValuesEntity::class), $state['feature_link_table_engines'] ?? []);
        $config = new PreviewConfig($assetRoot);
        $fingerprint = $factory->baselineDigest();
        $media = new FullMediaPreview();
        $docs = $major >= 4 ? new ProductDocumentSynchronizer($factory, null, $settings, $config) : null;
        $adopter = new GalleryContentAdopter(new GalleryFileProbe($config));
        $applier = new Applier($factory, $settings, new NdjsonGzReader(), $language, null, null, null, new CategoryImageDownloader($config), $adopter,
            null, null, null, $docs, null, null, null, null, $media, $lifecycle);
        $stats = new ApplyStats();
        $failure = null;
        $failureContext = [];
        try {
            $status = $applier->apply($manifest, $package->directory(), new PreviewFileCheckpoints(),
                static function (): bool { return false; }, $stats);
        } catch (OfflineProjectionFailure | ManifestException $error) {
            $status = Contract::STATUS_FAILED;
            $failure = $error->getMessage();
            if ($error instanceof FeatureLifecycleConflict) {
                $failureContext = $error->reportFields()['feature_conflict_context'];
            }
        }
        $verifiedAgain = OfflineFullPackage::open($packageDir, $validator);
        if ($verifiedAgain->manifestDigest() !== $package->manifestDigest()
            || $verifiedAgain->missingDocuments() !== $package->missingDocuments()) {
            throw new LogicException('Offline package changed during preview');
        }

        return [
            'status' => $status,
            'failure_reason' => $failure ?? ($status === Contract::STATUS_FAILED ? $media->lastError() : null),
            'failure_phase' => $status === Contract::STATUS_FAILED ? $media->phase() : null,
            'failure_context' => (object) $failureContext,
            'identity_conflicts' => $media->identityConflicts(),
            'bind_conflict_samples' => $stats->conflictSamples,
            'v5_adoption_plan_sha256' => $state['scope']['v5_adoption_plan_sha256'] ?? null,
            'retained_variant_prices' => $media->retainedVariantPrices(),
            'variant_inventory' => $media->variantInventory(),
            'status_basis' => 'catalog_projection_with_conditional_media_fetches',
            'schema_version' => $manifest['schema_version'],
            'manifest_sha256' => $package->manifestDigest(),
            'receiver_state_sha256' => hash('sha256', $stateJson),
            'projected_baseline_sha256' => $fingerprint,
            'unverified_package_documents' => (object) $package->missingDocuments(),
            'stats' => $stats->toArray(),
            'absent' => $media->absent(),
            'skipped' => $media->skips(),
            'changes' => $this->reportChanges($factory->changes()),
            'local_asset_observations_sha256' => hash(
                'sha256', json_encode($media->entries(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            ),
            'media' => $media->entries(),
        ];
    }

    private function reportChanges(array $changes): array
    {
        foreach ([CoreSyncImagesEntity::class, CoreSyncCategoryImagesEntity::class] as $class) {
            if (!isset($changes[$class])) {
                continue;
            }
            foreach ($changes[$class] as &$change) {
                if (!isset($change['fields']['url'])) {
                    continue;
                }
                $change['fields']['remote_url_sha256'] = array_map(static function ($url): ?string {
                    return is_string($url) ? hash('sha256', $url) : null;
                }, $change['fields']['url']);
                unset($change['fields']['url']);
            }
            unset($change);
        }

        if (isset($changes[VariantsEntity::class])) {
            foreach ($changes[VariantsEntity::class] as &$change) {
                if (isset($change['fields']['stock'])) {
                    [$before, $after] = $change['fields']['stock'];
                    $change['fields']['always_in_stock'] = [
                        $change['action'] === 'create' ? null : $before === null,
                        $change['action'] === 'delete' ? null : $after === null,
                    ];
                }
            }
            unset($change);
        }

        return $changes;
    }

    private function entityFactory(array $tables, PreviewLanguages $language, int $major): PreviewEntityFactory
    {
        $rows = function (string $name, array $fields = []) use ($tables): PreviewRows {
            return new PreviewRows($name, $this->table($tables, $name), $fields);
        };
        $translate = function (string $name, string $class, array $fields = []) use ($tables, $rows, $language): PreviewRows {
            return new PreviewLocalizedRows($name, $this->table($tables, $name), $fields, $language,
                $rows($name . '_translation', ['owner_id', 'lang_id']), $class::getLangFields());
        };
        $entities = [
            ProductsEntity::class => $translate('product', ProductsEntity::class, ['url', 'external_id']),
            CategoriesEntity::class => new PreviewCategories($this->table($tables, 'category'),
                $rows('product_category_link', ['product_id', 'category_id']), $language,
                $rows('category_translation', ['owner_id', 'lang_id'])),
            BrandsEntity::class => $translate('brand', BrandsEntity::class, ['url', 'coresync_external_id']),
            FeaturesEntity::class => $translate('feature', FeaturesEntity::class, ['coresync_external_id']),
            FeaturesValuesEntity::class => new PreviewFeatureValues($this->table($tables, 'feature_value'),
                $rows('product_feature_link', ['product_id', 'value_id']), $language,
                $rows('feature_value_translation', ['owner_id', 'lang_id'])),
            VariantsEntity::class => $translate('variant', VariantsEntity::class, ['product_id', 'sku', 'external_id']),
            ImagesEntity::class => $rows('image', ['product_id']),
            CoreSyncMapEntity::class => $rows('map', ['entity_type', 'external_id', 'local_id']),
            CoreSyncImagesEntity::class => $rows('image_map', ['product_local_id', 'product_external_id']),
        ];
        if ($major >= 2) {
            $entities[CoreSyncCategoryImagesEntity::class] = $rows('category_image_map', ['category_external_id', 'state']);
        }
        if ($major >= 4) {
            $entities[CoreSyncDocumentsEntity::class] = $rows('document_map', ['product_external_id']);
            $entities[ProductDocumentsEntity::class] = $rows('document', ['product_id']);
        }
        if (class_exists(Applier::REDIRECTS_ENTITY_CLASS)) {
            $entities[Applier::REDIRECTS_ENTITY_CLASS] = $rows('redirect', ['request_url']);
        }

        return new PreviewEntityFactory($entities);
    }

    private function table(array $tables, string $name): array
    {
        if (!isset($tables[$name]) || !is_array($tables[$name])) {
            throw new LogicException("Offline receiver state is missing table {$name}");
        }

        return $tables[$name];
    }
}
