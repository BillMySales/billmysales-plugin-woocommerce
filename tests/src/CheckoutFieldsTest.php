<?php

declare(strict_types=1);

/**
 * Tests of the checkout fields.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Checkout\CheckoutFields;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;

/**
 * @covers \BillMySales\WooCommerce\Checkout\CheckoutFields
 */
final class CheckoutFieldsTest extends TestCase
{
    /**
     * Sets up the mocks every test of the class needs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_title')->alias(
            static function ($title) {
                $title = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title));
                return trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');
            }
        );
    }

    /**
     * Keys are derived from labels and unique.
     *
     * @return void
     */
    public function test_keys_are_derived_from_labels_and_unique(): void
    {
        $this->assertSame('rut', CheckoutFields::unique_key('RUT', []));
        $this->assertSame('rut_2', CheckoutFields::unique_key('Rut', ['rut']));
        $this->assertSame('field', CheckoutFields::unique_key('***', []));
    }

    /**
     * Sanitize drops empty rows and splits values.
     *
     * @return void
     */
    public function test_sanitize_drops_empty_rows_and_splits_values(): void
    {
        $this->assertSame(
            [
                [
                    'key'      => 'document',
                    'label'    => 'Document',
                    'values'   => ['Receipt', 'Invoice'],
                    'required' => true,
                ],
                [
                    'key'      => 'rut',
                    'label'    => 'RUT',
                    'values'   => [],
                    'required' => false,
                ],
            ],
            CheckoutFields::sanitize(
                [
                    'fields' => [
                        [
                            'label'    => 'Document',
                            'values'   => ' Receipt, ,Invoice ',
                            'required' => '1',
                        ],
                        ['label' => ''],
                        ['label' => 'RUT'],
                    ],
                ]
            )
        );
    }

    /**
     * Hooks are registered.
     *
     * @return void
     */
    public function test_hooks_are_registered(): void
    {
        $fields = new CheckoutFields();
        $fields->register();
        $this->assertSame(10, has_action('woocommerce_init', [$fields, 'register_fields']));
        $this->assertSame(20, has_action('wp_enqueue_scripts', [$fields, 'enqueue_section_title']));
        $this->assertSame(10, has_filter('woocommerce_checkout_fields', [$fields, 'classic_fields']));
        $this->assertSame(10, has_action('woocommerce_checkout_create_order', [$fields, 'save_classic_fields']));
    }

    /**
     * Fields registered in the order section of the block checkout.
     *
     * @return void
     */
    public function test_fields_registered_in_the_order_section_of_the_block_checkout(): void
    {
        $this->options([CheckoutFields::OPTION => [
            ['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true],
            ['key' => 'documento', 'label' => 'Documento', 'values' => ['Boleta', 'Factura'], 'required' => false],
        ]]);
        Functions\expect('woocommerce_register_additional_checkout_field')->once()->with(
            ['id' => 'billmysales/rut', 'label' => 'RUT', 'location' => 'order', 'required' => true, 'type' => 'text']
        );
        Functions\expect('woocommerce_register_additional_checkout_field')->once()->with([
            'id' => 'billmysales/documento', 'label' => 'Documento', 'location' => 'order', 'required' => false, 'type' => 'select',
            'options' => [['value' => 'Boleta', 'label' => 'Boleta'], ['value' => 'Factura', 'label' => 'Factura']],
        ]);
        (new CheckoutFields())->register_fields();
    }

    /**
     * A field WooCommerce rejects is logged not thrown.
     *
     * @return void
     */
    public function test_a_field_woocommerce_rejects_is_logged_not_thrown(): void
    {
        $this->options([CheckoutFields::OPTION => [['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true]]]);
        Functions\when('woocommerce_register_additional_checkout_field')->alias(static function (): void {
            throw new \RuntimeException('duplicated id');
        });
        $logger = Mockery::mock();
        $logger->shouldReceive('error')->once()->with('Checkout field "rut" not registered: duplicated id', ['source' => 'billmysales']);
        Functions\when('wc_get_logger')->justReturn($logger);
        (new CheckoutFields())->register_fields();
    }

    /**
     * Section title script only on the checkout with fields.
     *
     * @return void
     */
    public function test_section_title_script_only_on_the_checkout_with_fields(): void
    {
        Functions\stubTranslationFunctions();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_checkout')->justReturn(true);
        $this->options([CheckoutFields::OPTION => [['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true]]]);
        Functions\expect('wp_add_inline_script')->once()->with('wp-hooks', CheckoutFields::section_title_script(), 'after');
        (new CheckoutFields())->enqueue_section_title();

        $this->options([]);
        (new CheckoutFields())->enqueue_section_title();
        Functions\when('is_checkout')->justReturn(false);
        (new CheckoutFields())->enqueue_section_title();
    }

    /**
     * Section title script replaces the title.
     *
     * @return void
     */
    public function test_section_title_script_replaces_the_title(): void
    {
        Functions\stubTranslationFunctions();
        Functions\when('wp_json_encode')->alias('json_encode');
        $script = CheckoutFields::section_title_script();
        $this->assertStringContainsString('wp.hooks.addFilter("i18n.gettext","billmysales/section-title"', $script);
        $this->assertStringContainsString('text==="Additional order information"&&domain==="woocommerce"', $script);
        $this->assertStringContainsString('?"Billing information":translation', $script);
    }

    /**
     * Order meta has the values of the fields.
     *
     * @return void
     */
    public function test_order_meta_has_the_values_of_the_fields(): void
    {
        $this->options([CheckoutFields::OPTION => [['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true]]]);
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_meta')->with('_wc_other/billmysales/rut')->andReturn('11.111.111-1');
        $this->assertSame([['id' => 0, 'key' => 'rut', 'value' => '11.111.111-1']], CheckoutFields::order_meta($order));
    }

    /**
     * Stored fields that are not a list are ignored.
     *
     * @return void
     */
    public function test_stored_fields_that_are_not_a_list_are_ignored(): void
    {
        $this->options([CheckoutFields::OPTION => 'broken']);
        $this->assertSame([], CheckoutFields::get());
    }

    /**
     * Nothing registered without the additional fields api.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @return void
     */
    public function test_nothing_registered_without_the_additional_fields_api(): void
    {
        $this->options([CheckoutFields::OPTION => [['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true]]]);
        (new CheckoutFields())->register_fields();
        $this->assertFalse(function_exists('woocommerce_register_additional_checkout_field'));
    }

    /**
     * The fields in the classic checkout's billing section.
     *
     * @return void
     */
    public function test_fields_in_the_billing_section_of_the_classic_checkout(): void
    {
        Functions\stubTranslationFunctions();
        $this->options([CheckoutFields::OPTION => [
            ['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true],
            ['key' => 'documento', 'label' => 'Documento', 'values' => ['Boleta', 'Factura'], 'required' => false],
        ]]);
        $fields = (new CheckoutFields())->classic_fields(['billing' => ['billing_email' => ['priority' => 110]], 'order' => []]);

        $this->assertSame(['priority' => 110], $fields['billing']['billing_email']);
        $this->assertSame(
            ['label' => 'RUT', 'required' => true, 'type' => 'text', 'class' => ['form-row-wide'], 'priority' => 200],
            $fields['billing']['billmysales_rut']
        );
        $this->assertSame(
            ['label' => 'Documento', 'required' => false, 'type' => 'select', 'class' => ['form-row-wide'], 'priority' => 201,
                'options' => ['' => 'Select an option', 'Boleta' => 'Boleta', 'Factura' => 'Factura']],
            $fields['billing']['billmysales_documento']
        );
    }

    /**
     * The classic checkout's values stored in the block checkout's meta.
     *
     * @return void
     */
    public function test_classic_values_stored_in_the_block_checkout_meta(): void
    {
        $this->options([CheckoutFields::OPTION => [
            ['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true],
            ['key' => 'documento', 'label' => 'Documento', 'values' => ['Boleta', 'Factura'], 'required' => false],
        ]]);
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('update_meta_data')->once()->with('_wc_other/billmysales/rut', '11.111.111-1');
        (new CheckoutFields())->save_classic_fields($order, ['billmysales_rut' => '11.111.111-1', 'billmysales_documento' => '', 'billing_email' => 'a@b.c']);
    }
}
