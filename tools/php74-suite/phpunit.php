<?php

if (PHP_VERSION_ID < 70400 || PHP_VERSION_ID >= 80100) {
    fwrite(STDERR, 'php74-suite: supported matrix is PHP 7.4 or 8.0, got ' . PHP_VERSION . "\n");
    exit(2);
}

define('PHPUNIT_COMPOSER_INSTALL', __DIR__ . '/bootstrap.php');
require PHPUNIT_COMPOSER_INSTALL;

PHPUnit\TextUI\Command::main();
