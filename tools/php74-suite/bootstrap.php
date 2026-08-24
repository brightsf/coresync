<?php

use Composer\Autoload\ClassLoader;

if (isset($GLOBALS['coresync_php74_suite_loader'])) {
    return $GLOBALS['coresync_php74_suite_loader'];
}

$repoRoot = dirname(__DIR__, 2);
$hostRoot = getenv('CORESYNC_OKAY_ROOT') ?: '/host';
$vendorDir = $hostRoot . '/vendor';
$composerDir = $vendorDir . '/composer';

foreach (['ClassLoader.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php'] as $file) {
    if (!is_file($composerDir . '/' . $file)) {
        fwrite(STDERR, "php74-suite: missing host Composer file {$composerDir}/{$file}\n");
        exit(2);
    }
}

require_once $composerDir . '/ClassLoader.php';

$loader = new ClassLoader($vendorDir);

foreach (require $composerDir . '/autoload_psr4.php' as $prefix => $paths) {
    $loader->setPsr4($prefix, $paths);
}
$loader->addClassMap(require $composerDir . '/autoload_classmap.php');

// The canonical module and its tests must win over the older copies in the
// read-only Okay host project. The narrower prefix is intentionally prepended.
$loader->addPsr4(
    'Okay\\Modules\\Format\\CoreSync\\',
    $repoRoot . '/Okay/Modules/Format/CoreSync',
    true
);
$loader->addPsr4(
    'Tests\\Modules\\Format\\CoreSync\\',
    $repoRoot . '/tests/Modules/Format/CoreSync',
    true
);
$loader->register(true);

foreach (require $composerDir . '/autoload_files.php' as $identifier => $file) {
    if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
        $GLOBALS['__composer_autoload_files'][$identifier] = true;
        require $file;
    }
}

$GLOBALS['coresync_php74_suite_loader'] = $loader;

return $loader;
