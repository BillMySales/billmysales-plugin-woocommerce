<?php

declare(strict_types=1);

/**
 * Tests of the plugin bootstrap.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use BillMySales\WooCommerce\Plugin;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * @covers \BillMySales\WooCommerce\Plugin
 * @uses \BillMySales\WooCommerce\Admin\SettingsPage
 * @uses \BillMySales\WooCommerce\Checkout\CheckoutFields
 * @uses \BillMySales\WooCommerce\Webhook\Delivery
 */
final class PluginTest extends TestCase
{
    /**
     * Boot waits for WooCommerce.
     *
     * @return void
     */
    public function test_boot_waits_for_woocommerce(): void
    {
        Plugin::boot();
        $this->assertSame(10, has_action('before_woocommerce_init', [Plugin::class, 'declare_compatibility']));
        $this->assertSame(10, has_action('plugins_loaded', [Plugin::class, 'init']));
    }

    /**
     * Compatible with HPOS and the block checkout.
     *
     * @return void
     */
    public function test_compatible_with_hpos_and_the_block_checkout(): void
    {
        Actions\expectDone('test_features_util_declare_compatibility')->once()->with('custom_order_tables', BILLMYSALES_FILE, true);
        Actions\expectDone('test_features_util_declare_compatibility')->once()->with('cart_checkout_blocks', BILLMYSALES_FILE, true);
        Plugin::declare_compatibility();
        $this->assertTrue(class_exists(FeaturesUtil::class));
    }

    /**
     * Nothing without WooCommerce.
     *
     * @return void
     */
    public function test_nothing_without_woocommerce(): void
    {
        Plugin::init();
        $this->assertFalse(has_action('init', [Plugin::class, 'load_textdomain']));
        $this->assertFalse(has_action('admin_menu'));
    }

    /**
     * Parts are registered with WooCommerce.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @return void
     */
    public function test_parts_are_registered_with_woocommerce(): void
    {
        eval('class WooCommerce {}');
        Functions\when('plugin_basename')->justReturn('billmysales/billmysales.php');
        Plugin::init();
        $this->assertSame(10, has_action('init', [Plugin::class, 'load_textdomain']));
        $this->assertTrue(has_action('admin_menu'));
        $this->assertTrue(has_action('woocommerce_init'));
        $this->assertTrue(has_action('woocommerce_order_status_changed'));
    }

    /**
     * Translations from the languages directory.
     *
     * @return void
     */
    public function test_translations_from_the_languages_directory(): void
    {
        Functions\when('plugin_basename')->justReturn('billmysales/billmysales.php');
        Functions\expect('load_plugin_textdomain')->once()->with('billmysales', false, 'billmysales/languages');
        Plugin::load_textdomain();
    }
}
