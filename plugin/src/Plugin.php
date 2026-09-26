<?php

declare(strict_types=1);

/**
 * Plugin bootstrap: registers every hook.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use BillMySales\WooCommerce\Admin\SettingsPage;
use BillMySales\WooCommerce\Checkout\CheckoutFields;
use BillMySales\WooCommerce\Webhook\Delivery;

defined('ABSPATH') || exit;

/**
 * Wires the plugin's parts into WordPress and WooCommerce.
 */
final class Plugin
{
    /**
     * Slug, text domain and prefix of the plugin.
     */
    public const SLUG = 'billmysales';

    /**
     * Platform code sent in the X-BillMySales-Platform header.
     */
    public const PLATFORM = 'woocommerce';

    /**
     * Registers the hooks. WooCommerce may load after this plugin (plugins
     * load in folder order), so everything that needs it waits for
     * `plugins_loaded`.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('before_woocommerce_init', [self::class, 'declare_compatibility']);
        add_action('plugins_loaded', [self::class, 'init']);
    }

    /**
     * Declares compatibility with HPOS (custom order tables) and the
     * block-based cart and checkout, so WooCommerce shows no warning.
     *
     * @return void
     */
    public static function declare_compatibility(): void
    {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', BILLMYSALES_FILE, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', BILLMYSALES_FILE, true);
        }
    }

    /**
     * Hooks the plugin's parts once WooCommerce is loaded.
     *
     * @return void
     */
    public static function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_action('init', [self::class, 'load_textdomain']);
        (new SettingsPage())->register();
        (new CheckoutFields())->register();
        (new Delivery())->register();
    }

    /**
     * Loads the translations shipped in the plugin's languages directory.
     *
     * @return void
     */
    public static function load_textdomain(): void
    {
        load_plugin_textdomain(self::SLUG, false, dirname(plugin_basename(BILLMYSALES_FILE)) . '/languages');
    }
}
