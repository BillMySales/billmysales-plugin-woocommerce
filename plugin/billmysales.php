<?php

declare(strict_types=1);

/**
 * Plugin Name:          BillMySales
 * Plugin URI:           https://www.billmysales.com/apps/datasources/woocommerce
 * Description:          Sends WooCommerce orders to BillMySales when they reach the selected statuses, and adds custom fields to the checkout.
 * Version:              2.0.0
 * Author:               BillMySales
 * Author URI:           https://www.billmysales.com
 * Text Domain:          billmysales
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * WC requires at least: 8.9
 * WC tested up to:      11.1
 * License:              AGPL-3.0-or-later
 * License URI:          https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @package BillMySales\WooCommerce
 */

defined('ABSPATH') || exit;

define('BILLMYSALES_VERSION', '2.0.0');
define('BILLMYSALES_FILE', __FILE__);

spl_autoload_register(
    static function (string $class_name): void {
        $prefix = 'BillMySales\\WooCommerce\\';
        if (0 !== strpos($class_name, $prefix)) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class_name, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require $path;
        }
    }
);

BillMySales\WooCommerce\Plugin::boot();
