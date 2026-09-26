<?php

declare(strict_types=1);

/**
 * Tests of the settings page.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Admin\SettingsPage;
use BillMySales\WooCommerce\Checkout\CheckoutFields;
use BillMySales\WooCommerce\Settings;
use Brain\Monkey\Functions;

/**
 * @covers \BillMySales\WooCommerce\Admin\SettingsPage
 * @uses \BillMySales\WooCommerce\Settings
 * @uses \BillMySales\WooCommerce\Checkout\CheckoutFields
 */
final class SettingsPageTest extends TestCase
{
    /**
     * Sets up the mocks every test of the class needs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\stubs([
            'admin_url' => static fn ($path = '') => 'https://shop.example/wp-admin/' . $path,
            'plugin_basename' => 'billmysales/billmysales.php',
            'plugins_url' => static fn ($path) => 'https://shop.example/wp-content/plugins/billmysales/' . $path,
            'current_user_can' => true,
            'settings_errors' => static function (): void {
            },
            'settings_fields' => static function ($group): void {
                echo '<input type="hidden" name="option_page" value="' . $group . '" />';
            },
            'checked' => static function ($checked): void {
                echo $checked ? ' checked="checked"' : '';
            },
            'submit_button' => static function ($text): void {
                echo '<button type="submit">' . $text . '</button>';
            },
            'wc_get_order_statuses' => ['wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed'],
        ]);
        $this->options([
            'billmysales_settings' => $this->settings(['statuses' => ['completed']]),
            'billmysales_checkout_fields' => [['key' => 'documento', 'label' => 'Documento', 'values' => ['Boleta', 'Factura'], 'required' => true]],
        ]);
    }

    /**
     * Removes the mocks and the state a test changed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($_GET['tab']);
        parent::tearDown();
    }

    /**
     * Hooks are registered.
     *
     * @return void
     */
    public function test_hooks_are_registered(): void
    {
        $page = new SettingsPage();
        $page->register();
        $this->assertSame(10, has_action('admin_menu', [$page, 'add_page']));
        $this->assertSame(10, has_action('admin_init', [$page, 'register_settings']));
        $this->assertSame(10, has_action('admin_enqueue_scripts', [$page, 'enqueue_assets']));
        $this->assertSame(10, has_filter('plugin_action_links_billmysales/billmysales.php', [$page, 'action_links']));
    }

    /**
     * Page in the WooCommerce menu for shop managers.
     *
     * @return void
     */
    public function test_page_in_the_woocommerce_menu_for_shop_managers(): void
    {
        $page = new SettingsPage();
        Functions\expect('add_submenu_page')->once()->with('woocommerce', 'BillMySales', 'BillMySales', 'manage_woocommerce', 'billmysales', [$page, 'render']);
        $page->add_page();
    }

    /**
     * Both options are registered with their sanitizers.
     *
     * @return void
     */
    public function test_both_options_are_registered_with_their_sanitizers(): void
    {
        Functions\expect('register_setting')->once()->with(Settings::GROUP, Settings::OPTION, ['sanitize_callback' => [Settings::class, 'sanitize']]);
        Functions\expect('register_setting')->once()->with(CheckoutFields::GROUP, CheckoutFields::OPTION, ['sanitize_callback' => [CheckoutFields::class, 'sanitize']]);
        (new SettingsPage())->register_settings();
    }

    /**
     * Settings link first in the plugins list.
     *
     * @return void
     */
    public function test_settings_link_first_in_the_plugins_list(): void
    {
        $this->assertSame(
            ['<a href="https://shop.example/wp-admin/admin.php?page=billmysales">Settings</a>', 'deactivate'],
            (new SettingsPage())->action_links(['deactivate'])
        );
    }

    /**
     * Style and script only on this page.
     *
     * @return void
     */
    public function test_style_and_script_only_on_this_page(): void
    {
        Functions\expect('wp_enqueue_style')->once()->with('billmysales-admin', 'https://shop.example/wp-content/plugins/billmysales/assets/css/admin.css', [], BILLMYSALES_VERSION);
        Functions\expect('wp_enqueue_script')->once()->with('billmysales-admin', 'https://shop.example/wp-content/plugins/billmysales/assets/js/admin.js', [], BILLMYSALES_VERSION, true);
        Functions\expect('wp_localize_script')->once()->with('billmysales-admin', 'BillMySalesAdmin', ['showSecret' => 'Show secret', 'hideSecret' => 'Hide secret']);
        $page = new SettingsPage();
        $page->enqueue_assets('woocommerce_page_billmysales');
        $page->enqueue_assets('edit.php');
    }

    /**
     * Settings tab.
     *
     * @return void
     */
    public function test_settings_tab(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('nav-tab nav-tab-active">Settings', $html);
        $this->assertStringContainsString('value="billmysales_settings_group"', $html);
        $this->assertStringContainsString('name="billmysales_settings[url]" value="https://billmysales.example/hook"', $html);
        $this->assertStringContainsString('name="billmysales_settings[secret]" value="s3cret"', $html);
        $this->assertStringContainsString('value="completed"  checked="checked"', $html);
        $this->assertStringContainsString('value="processing" ', $html);
        $this->assertStringNotContainsString('value="pending"', $html);
        $this->assertStringNotContainsString('notice-warning', $html);
    }

    /**
     * Settings tab warns when not configured.
     *
     * @return void
     */
    public function test_settings_tab_warns_when_not_configured(): void
    {
        $this->options(['billmysales_settings' => []]);
        $this->assertStringContainsString('notice-warning', $this->render());
    }

    /**
     * Checkout fields tab.
     *
     * @return void
     */
    public function test_checkout_fields_tab(): void
    {
        $_GET['tab'] = 'checkout-fields';
        $html = $this->render();
        $this->assertStringContainsString('nav-tab nav-tab-active">Checkout fields', $html);
        $this->assertStringContainsString('value="billmysales_checkout_fields_group"', $html);
        $this->assertStringContainsString('data-next-index="1"', $html);
        $this->assertStringContainsString('name="billmysales_checkout_fields[fields][0][label]" value="Documento"', $html);
        $this->assertStringContainsString('value="Boleta, Factura"', $html);
        $this->assertStringContainsString('Key: <code>documento</code>', $html);
        $this->assertStringContainsString('name="billmysales_checkout_fields[fields][__INDEX__][label]" value=""', $html);
    }

    /**
     * Checkout fields tab starts with an empty row.
     *
     * @return void
     */
    public function test_checkout_fields_tab_starts_with_an_empty_row(): void
    {
        $_GET['tab'] = 'checkout-fields';
        $this->options([]);
        $html = $this->render();
        $this->assertStringContainsString('name="billmysales_checkout_fields[fields][0][label]" value=""', $html);
        $this->assertStringNotContainsString('Key: <code>', $html);
    }

    /**
     * Nothing without permission.
     *
     * @return void
     */
    public function test_nothing_without_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertSame('', $this->render());
    }

    /**
     * The page's HTML.
     *
     * @return string
     */
    private function render(): string
    {
        ob_start();
        (new SettingsPage())->render();
        return (string) ob_get_clean();
    }
}
