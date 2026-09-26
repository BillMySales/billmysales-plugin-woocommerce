<?php

declare(strict_types=1);

/**
 * Tests of the order payload.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Webhook\Payload;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;

/**
 * @covers \BillMySales\WooCommerce\Webhook\Payload
 * @uses \BillMySales\WooCommerce\Checkout\CheckoutFields
 */
final class PayloadTest extends TestCase
{
    /**
     * REST order limited to the fields BillMySales reads plus checkout fields.
     *
     * @return void
     */
    public function test_rest_order_limited_to_the_fields_billmysales_reads_plus_checkout_fields(): void
    {
        $this->options(['billmysales_checkout_fields' => [['key' => 'rut', 'label' => 'RUT', 'values' => [], 'required' => true]]]);
        $order = $this->order(7);
        $order->shouldReceive('get_meta')->with('_wc_other/billmysales/rut')->andReturn('11.111.111-1');
        $this->rest(false, ['id' => 7, 'status' => 'processing', 'total' => '9990', '_links' => [], 'cart_hash' => 'x', 'meta_data' => [['key' => 'internal']]]);

        $this->assertSame(
            ['id' => 7, 'status' => 'processing', 'total' => '9990', 'meta_data' => [['id' => 0, 'key' => 'rut', 'value' => '11.111.111-1']]],
            Payload::build($order)
        );
    }

    /**
     * Without checkout fields there is no meta data.
     *
     * @return void
     */
    public function test_without_checkout_fields_there_is_no_meta_data(): void
    {
        $this->options([]);
        $this->rest(false, ['id' => 7, 'meta_data' => [['key' => 'internal']]]);
        $this->assertSame(['id' => 7], Payload::build($this->order(7)));
    }

    /**
     * The permission filter allows reading this order only and is removed.
     *
     * @return void
     */
    public function test_the_permission_filter_allows_reading_this_order_only_and_is_removed(): void
    {
        $this->options([]);
        $allow = null;
        Filters\expectAdded('woocommerce_rest_check_permissions')->once()->whenHappen(static function ($callback) use (&$allow): void {
            $allow = $callback;
        });
        Filters\expectRemoved('woocommerce_rest_check_permissions')->once();
        $this->rest(false, ['id' => 7]);

        Payload::build($this->order(7));

        $this->assertIsCallable($allow);
        $this->assertTrue($allow(false, 'read', 7, 'shop_order'));
        $this->assertFalse($allow(false, 'read', 8, 'shop_order'));
        $this->assertFalse($allow(false, 'edit', 7, 'shop_order'));
        $this->assertFalse($allow(false, 'read', 7, 'product'));
    }

    /**
     * REST error is thrown and the filter removed.
     *
     * @return void
     */
    public function test_rest_error_is_thrown_and_the_filter_removed(): void
    {
        Filters\expectRemoved('woocommerce_rest_check_permissions')->once();
        $this->rest(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sorry, you cannot view this resource.');
        Payload::build($this->order(7));
    }

    /**
     * An order with an id.
     *
     * @param int $id Order id.
     * @return WC_Order&\Mockery\MockInterface
     */
    private function order(int $id)
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_id')->andReturn($id);
        return $order;
    }

    /**
     * rest_do_request() answers with an error or with $data.
     *
     * @param bool                 $error Whether the REST API answers with an error.
     * @param array<string, mixed> $data  The REST representation of the order.
     * @return void
     */
    private function rest(bool $error, array $data = []): void
    {
        $response = Mockery::mock();
        $response->shouldReceive('is_error')->andReturn($error);
        $response->shouldReceive('as_error->get_error_message')->andReturn('Sorry, you cannot view this resource.');
        Functions\expect('rest_do_request')->once()->with(Mockery::on(
            static fn ($request): bool => $request instanceof \WP_REST_Request && $request->get_method() === 'GET' && $request->get_route() === '/wc/v3/orders/7'
        ))->andReturn($response);
        $server = Mockery::mock();
        $server->shouldReceive('response_to_data')->with($response, false)->andReturn($data);
        Functions\when('rest_get_server')->justReturn($server);
    }
}
