<?php

if ($argc !== 3) {
    fwrite(STDERR, "usage: suite-config.php <skip-list.txt> <generated.xml>\n");
    exit(2);
}

require_once __DIR__ . '/SuiteConfigBuilder.php';

try {
    $builder = new CoreSyncPhp74Suite\SuiteConfigBuilder(dirname(__DIR__, 2));
    $plan = $builder->build($argv[1], $argv[2]);
} catch (Throwable $e) {
    fwrite(STDERR, 'php74-suite: ' . $e->getMessage() . "\n");
    exit(2);
}

foreach ($plan['skipped'] as $skip) {
    fwrite(STDERR, "CORESYNC-SUITE skip={$skip['path']} reason={$skip['reason']}\n");
}

printf("%d %d\n", count($plan['selected']), count($plan['skipped']));
