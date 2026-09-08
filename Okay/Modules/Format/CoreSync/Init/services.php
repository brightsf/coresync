<?php

namespace Okay\Modules\Format\CoreSync\Init;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\Apply\CurlImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Apply\CategoryImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Apply\ImageDownloader;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryAdoptionPlanReader;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryContentAdopter;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryFileProbe;
use Okay\Modules\Format\CoreSync\Core\Apply\LegacyGalleryAdopter;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\CategoryV2Validator;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\Orders\AckService;
use Okay\Modules\Format\CoreSync\Core\Orders\EventPingClient;
use Okay\Modules\Format\CoreSync\Core\Orders\OrderPullProvider;
use Okay\Modules\Format\CoreSync\Core\Orders\OrdersStatusMarker;
use Okay\Modules\Format\CoreSync\Core\Orders\OrdersSyncGateway;
use Okay\Modules\Format\CoreSync\Core\Orders\RequestPullProvider;
use Okay\Modules\Format\CoreSync\Core\Ops\ResetCeremony;
use Okay\Modules\Format\CoreSync\Extenders\OrdersHelperExtender;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Core\ProductContentLanguageCatalog;
use Okay\Modules\Format\CoreSync\Core\ProductV3Validator;
use Okay\Modules\Format\CoreSync\Core\ReportClient;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Core\Update\ArtifactDownloader;
use Okay\Modules\Format\CoreSync\Core\Update\ModuleSwapper;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMarker;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaUpgrader;
use Okay\Modules\Format\CoreSync\Core\Update\TarSafeExtractor;
use Okay\Modules\Format\CoreSync\Core\Update\Updater;
use Psr\Log\LoggerInterface;

return [
    SnapshotHttpClient::class => [
        'class' => SnapshotHttpClient::class,
        'arguments' => [
            new SR(LoggerInterface::class),
        ],
    ],
    ManifestValidator::class => [
        'class' => ManifestValidator::class,
        'arguments' => [],
    ],
    ProductContentLanguageCatalog::class => [
        'class' => ProductContentLanguageCatalog::class,
        'arguments' => [
            new SR(Languages::class),
        ],
    ],
    ProductV3Validator::class => [
        'class' => ProductV3Validator::class,
        'arguments' => [],
    ],
    Describer::class => [
        'class' => Describer::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(ManifestValidator::class),
            new SR(ProductContentLanguageCatalog::class),
        ],
    ],
    SnapshotDownloader::class => [
        'class' => SnapshotDownloader::class,
        'arguments' => [
            new SR(SnapshotHttpClient::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ReportClient::class => [
        'class' => ReportClient::class,
        'arguments' => [
            new SR(LoggerInterface::class),
        ],
    ],
    LockHelper::class => [
        'class' => LockHelper::class,
        'arguments' => [],
    ],
    NdjsonGzReader::class => [
        'class' => NdjsonGzReader::class,
        'arguments' => [],
    ],
    CategoryV2Validator::class => [
        'class' => CategoryV2Validator::class,
        'arguments' => [],
    ],
    CurlImageDownloader::class => [
        'class' => CurlImageDownloader::class,
        'arguments' => [
            new SR(Config::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ImageDownloader::class => [
        'class' => CurlImageDownloader::class,
        'arguments' => [
            new SR(Config::class),
            new SR(LoggerInterface::class),
        ],
    ],
    GalleryAdoptionPlanReader::class => [
        'class' => GalleryAdoptionPlanReader::class,
        'arguments' => [],
    ],
    // Единственный набор проверок файла галереи, общий для подписанного плана и автоматического
    // усыновления по содержимому (второй набор писать нельзя).
    GalleryFileProbe::class => [
        'class' => GalleryFileProbe::class,
        'arguments' => [
            new SR(Config::class),
        ],
    ],
    GalleryContentAdopter::class => [
        'class' => GalleryContentAdopter::class,
        'arguments' => [
            new SR(GalleryFileProbe::class),
            new SR(LoggerInterface::class),
        ],
    ],
    LegacyGalleryAdopter::class => [
        'class' => LegacyGalleryAdopter::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Database::class),
            new SR(QueryFactory::class),
            new SR(Config::class),
            new SR(LoggerInterface::class),
            new SR(GalleryFileProbe::class),
        ],
    ],
    CategoryImageDownloader::class => [
        'class' => CategoryImageDownloader::class,
        'arguments' => [
            new SR(Config::class),
            new SR(LoggerInterface::class),
        ],
    ],
    Applier::class => [
        'class' => Applier::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(NdjsonGzReader::class),
            new SR(Languages::class),
            new SR(LoggerInterface::class),
            new SR(ImageDownloader::class),
            new SR(CategoryV2Validator::class),
            new SR(CategoryImageDownloader::class),
            new SR(GalleryContentAdopter::class),
            new SR(ProductContentLanguageCatalog::class),
            new SR(ProductV3Validator::class),
        ],
    ],
    ArtifactDownloader::class => [
        'class' => ArtifactDownloader::class,
        'arguments' => [],
    ],
    TarSafeExtractor::class => [
        'class' => TarSafeExtractor::class,
        'arguments' => [],
    ],
    ModuleSwapper::class => [
        'class' => ModuleSwapper::class,
        'arguments' => [
            new SR(Config::class),
            new SR(LoggerInterface::class),
        ],
    ],
    Updater::class => [
        'class' => Updater::class,
        'arguments' => [
            new SR(SnapshotHttpClient::class),
            new SR(ArtifactDownloader::class),
            new SR(TarSafeExtractor::class),
            new SR(ModuleSwapper::class),
            new SR(Settings::class),
            new SR(Config::class),
            new SR(LoggerInterface::class),
        ],
    ],
    SchemaMarker::class => [
        'class' => SchemaMarker::class,
        'arguments' => [
            new SR(EntityFactory::class),
        ],
    ],
    SchemaMigrationCatalog::class => [
        'class' => SchemaMigrationCatalog::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Config::class),
        ],
    ],
    SchemaUpgrader::class => [
        'class' => SchemaUpgrader::class,
        'arguments' => [
            new SR(SchemaMarker::class),
            new SR(SchemaMigrationCatalog::class),
            new SR(Settings::class),
            new SR(LoggerInterface::class),
        ],
    ],
    OrderPullProvider::class => [
        'class' => OrderPullProvider::class,
        'arguments' => [
            new SR(Database::class),
            new SR(QueryFactory::class),
            new SR(EntityFactory::class),
            new SR(LoggerInterface::class),
        ],
    ],
    RequestPullProvider::class => [
        'class' => RequestPullProvider::class,
        'arguments' => [
            new SR(Database::class),
            new SR(QueryFactory::class),
            new SR(EntityFactory::class),
            new SR(LoggerInterface::class),
        ],
    ],
    OrdersStatusMarker::class => [
        'class' => OrdersStatusMarker::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(LoggerInterface::class),
        ],
    ],
    AckService::class => [
        'class' => AckService::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(OrdersStatusMarker::class),
            new SR(LoggerInterface::class),
        ],
    ],
    OrdersSyncGateway::class => [
        'class' => OrdersSyncGateway::class,
        'arguments' => [
            new SR(OrderPullProvider::class),
            new SR(RequestPullProvider::class),
            new SR(AckService::class),
        ],
    ],
    EventPingClient::class => [
        'class' => EventPingClient::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(LoggerInterface::class),
        ],
    ],
    OrdersHelperExtender::class => [
        'class' => OrdersHelperExtender::class,
        'arguments' => [
            new SR(EventPingClient::class),
        ],
    ],
    ResetCeremony::class => [
        'class' => ResetCeremony::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(LoggerInterface::class),
        ],
    ],
    SyncRunner::class => [
        'class' => SyncRunner::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(SnapshotHttpClient::class),
            new SR(ManifestValidator::class),
            new SR(SnapshotDownloader::class),
            new SR(ReportClient::class),
            new SR(Applier::class),
            new SR(EntityFactory::class),
            new SR(LockHelper::class),
            new SR(Config::class),
            new SR(LoggerInterface::class),
            new SR(Updater::class),
            new SR(SchemaUpgrader::class),
        ],
    ],
];
