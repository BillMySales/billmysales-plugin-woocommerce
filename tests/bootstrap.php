<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: unit tests with Brain Monkey (WordPress functions
 * mocked per test), no WordPress install.
 *
 * @package BillMySales\WooCommerce
 */

require __DIR__ . '/../vendor/autoload.php';

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
define('BILLMYSALES_VERSION', '1.2.3');
define('BILLMYSALES_FILE', __DIR__ . '/../plugin/billmysales.php');

// WordPress and WooCommerce classes (not when PHPStan loads this file: it
// has their stubs).
if (!defined('__PHPSTAN_RUNNING__')) {
    require_once __DIR__ . '/fixtures/classes.php';
}

// Loads every class up front: the file-level ABSPATH guard would otherwise
// run inside the first test of each class, outside its @covers (risky).
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../plugin/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    require_once $file->getPathname();
}
