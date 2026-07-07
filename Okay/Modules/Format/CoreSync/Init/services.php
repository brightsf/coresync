<?php

namespace Okay\Modules\Format\CoreSync\Init;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\Applier;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use Okay\Modules\Format\CoreSync\Core\ReportClient;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
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
    Applier::class => [
        'class' => Applier::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(NdjsonGzReader::class),
            new SR(Languages::class),
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
        ],
    ],
];
