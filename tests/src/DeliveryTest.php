<?php

declare(strict_types=1);

/**
 * Tests of the delivery queue, the sending and the retries.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Settings;
use BillMySales\WooCommerce\Webhook\Delivery;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;

/**
 * @covers \BillMySales\WooCommerce\Webhook\Delivery
 * @uses \BillMySales\WooCommerce\Settings
 * @uses \BillMySales\WooCommerce\Webhook\Headers
 * @uses \BillMySales\WooCommerce\Webhook\Payload
 * @uses \BillMySales\WooCommerce\Checkout\CheckoutFields
 */
final class DeliveryTest extends TestCase
{
    /**
     * WooCommerce's logger.
     *
     * @var \Mockery\MockInterface
     */
    private \Mockery\MockInterface $logger;

    /**
     * Sets up the mocks every test of the class needs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->options(['billmysales_settings' => $this->settings()]);
        Functions\when('wp_generate_uuid4')->justReturn('uuid-1');
        $this->logger = Mockery::mock();
        Functions\when('wc_get_logger')->justReturn($this->logger);
    }

    /**
     * Hooks are registered.
     *
     * @return void
     */
    public function test_hooks_are_registered(): void
    {
        $delivery = new Delivery();
        $delivery->register();
        $this->assertSame(10, has_action('woocommerce_order_status_changed', [$delivery, 'on_status_changed']));
        $this->assertSame(10, has_action('woocommerce_new_order', [$delivery, 'on_new_order']));
        $this->assertSame(10, has_filter('woocommerce_order_actions', [$delivery, 'order_actions']));
        $this->assertSame(10, has_action('woocommerce_order_action_billmysales_send', [$delivery, 'on_order_action']));
        $this->assertSame(10, has_action(Delivery::HOOK, [$delivery, 'deliver']));
    }

    /**
     * Selected status is queued once.
     *
     * @return void
     */
    public function test_selected_status_is_queued_once(): void
    {
        Functions\expect('as_enqueue_async_action')
            ->once()
            ->with(Delivery::HOOK, [7, 'order.status_changed', 'uuid-1', 0], Delivery::HOOK);

        $delivery = new Delivery();
        $delivery->on_status_changed(7, 'pending', 'processing');
        $delivery->queue(7, 'processing', 'order.created');
    }

    /**
     * New order in a selected status is queued.
     *
     * @return void
     */
    public function test_new_order_in_a_selected_status_is_queued(): void
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_status')->andReturn('completed');
        Functions\when('wc_get_order')->justReturn($order);
        Functions\expect('as_enqueue_async_action')
            ->once()
            ->with(Delivery::HOOK, [8, 'order.created', 'uuid-1', 0], Delivery::HOOK);

        (new Delivery())->on_new_order(8);
    }

    /**
     * New order that does not exist is ignored.
     *
     * @return void
     */
    public function test_new_order_that_does_not_exist_is_ignored(): void
    {
        Functions\when('wc_get_order')->justReturn(false);
        Functions\expect('as_enqueue_async_action')->never();
        (new Delivery())->on_new_order(8);
    }

    /**
     * Other statuses are not queued.
     *
     * @return void
     */
    public function test_other_statuses_are_not_queued(): void
    {
        Functions\expect('as_enqueue_async_action')->never();

        $delivery = new Delivery();
        $delivery->queue(7, 'cancelled', 'order.status_changed');
        $delivery->queue(7, 'pending', 'order.status_changed');
    }

    /**
     * Nothing is queued when inactive.
     *
     * @return void
     */
    public function test_nothing_is_queued_when_inactive(): void
    {
        $this->options(['billmysales_settings' => $this->settings(['active' => false])]);
        Functions\expect('as_enqueue_async_action')->never();
        (new Delivery())->queue(7, 'processing', 'order.status_changed');
    }

    /**
     * Delivered.
     *
     * @return void
     */
    public function test_delivered(): void
    {
        $order = $this->order(7);
        $this->post(['response' => ['code' => 200]]);
        Functions\expect('as_schedule_single_action')->never();
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: sent (HTTP 200).');
        $this->logger->shouldReceive('log')->once()->with('info', 'Order #7 delivered (order.status_changed, uuid-1, HTTP 200).', ['source' => 'billmysales']);

        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 0);
    }

    /**
     * Request is signed and carries the standard headers.
     *
     * @return void
     */
    public function test_request_is_signed_and_carries_the_standard_headers(): void
    {
        $order = $this->order(7);
        Functions\expect('wp_remote_post')->once()->andReturnUsing(function (string $url, array $args) {
            $this->assertSame('https://billmysales.example/hook', $url);
            $this->assertSame(Delivery::TIMEOUT, $args['timeout']);
            $this->assertSame(base64_encode(hash_hmac('sha256', $args['body'], 's3cret', true)), $args['headers']['X-BillMySales-Signature']);
            $this->assertSame('uuid-1', $args['headers']['X-BillMySales-Delivery']);
            $this->assertSame('11.1.2', $args['headers']['X-BillMySales-Platform-Version']);
            $this->assertSame('{"id":7,"status":"processing"}', $args['body']);
            return ['response' => ['code' => 202]];
        });
        $this->http();
        $this->logger->shouldReceive('log')->once();
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: sent (HTTP 202).');

        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 0);
    }

    /**
     * Retryable failure is retried with the same delivery.
     *
     * @dataProvider retryable_failures
     *
     * @param array<string, mixed>|\WP_Error $response What wp_remote_post() returns.
     * @param string                         $reason   The error in the note and the log.
     *
     * @return void
     */
    public function test_retryable_failure_is_retried_with_the_same_delivery($response, string $reason): void
    {
        $order = $this->order(7);
        $this->post($response);
        Functions\when('human_time_diff')->justReturn('30 mins');
        $order->shouldReceive('add_order_note')->once()->with("BillMySales: not sent ({$reason}), retry 3 in 30 mins.");
        $due = Mockery::on(static fn ($time): bool => abs($time - (time() + Delivery::RETRY_DELAYS[2])) <= 5);
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with($due, Delivery::HOOK, [7, 'order.status_changed', 'uuid-1', 3], Delivery::HOOK);
        $this->logger->shouldReceive('log')->once()->with('warning', sprintf('Order #7 not delivered (%s), retry 3 in %d s.', $reason, Delivery::RETRY_DELAYS[2]), ['source' => 'billmysales']);

        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 2);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public function retryable_failures(): array
    {
        return [
            'network error' => [new \WP_Error('http_request_failed', 'cURL error 7'), 'cURL error 7'],
            'server error' => [['response' => ['code' => 503]], 'HTTP 503'],
            'rate limited' => [['response' => ['code' => 429]], 'HTTP 429'],
        ];
    }

    /**
     * Given up after the last retry.
     *
     * @return void
     */
    public function test_given_up_after_the_last_retry(): void
    {
        $order = $this->order(7);
        $this->post(['response' => ['code' => 500]]);
        Functions\expect('as_schedule_single_action')->never();
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: not sent (HTTP 500), no more retries. Use "Send to BillMySales" in the order actions to try again.');
        $this->logger->shouldReceive('log')->once()->with('error', 'Order #7 not delivered (HTTP 500), given up.', ['source' => 'billmysales']);

        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', count(Delivery::RETRY_DELAYS));
    }

    /**
     * Rejected is not retried.
     *
     * @return void
     */
    public function test_rejected_is_not_retried(): void
    {
        $order = $this->order(7);
        $this->post(['response' => ['code' => 401], 'body' => " bad signature\n"]);
        Functions\expect('as_schedule_single_action')->never();
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: rejected (HTTP 401: bad signature). Fix the cause and use "Send to BillMySales" in the order actions.');
        $this->logger->shouldReceive('log')->once()->with('error', 'Order #7 rejected by BillMySales (order.status_changed, uuid-1): HTTP 401: bad signature', ['source' => 'billmysales']);

        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 0);
    }

    /**
     * Payload error is retried.
     *
     * @return void
     */
    public function test_payload_error_is_retried(): void
    {
        $order = $this->order(7, false);
        $response = Mockery::mock();
        $response->shouldReceive('is_error')->andReturn(true);
        $response->shouldReceive('as_error->get_error_message')->andReturn('rest_forbidden');
        Functions\when('rest_do_request')->justReturn($response);
        Functions\expect('wp_remote_post')->never();
        Functions\expect('as_schedule_single_action')->once();
        Functions\when('human_time_diff')->justReturn('1 min');
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: not sent (payload: rest_forbidden), retry 1 in 1 min.');
        $this->logger->shouldReceive('log')->once()->with('warning', 'Order #7 not delivered (payload: rest_forbidden), retry 1 in 60 s.', ['source' => 'billmysales']);

        (new Delivery())->deliver($order->get_id(), 'order.status_changed', 'uuid-1', 0);
    }

    /**
     * Nothing is sent when inactive or the order is gone.
     *
     * @return void
     */
    public function test_nothing_is_sent_when_inactive_or_the_order_is_gone(): void
    {
        Functions\expect('wp_remote_post')->never();
        Functions\when('wc_get_order')->justReturn(false);
        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 0);

        $this->order(7);
        $this->options(['billmysales_settings' => $this->settings(['active' => false])]);
        (new Delivery())->deliver(7, 'order.status_changed', 'uuid-1', 0);
    }

    /**
     * Retryable statuses.
     *
     * @return void
     */
    public function test_retryable_statuses(): void
    {
        foreach ([408, 429, 500, 502, 503] as $code) {
            $this->assertTrue(Delivery::is_retryable($code), (string) $code);
        }
        foreach ([400, 401, 403, 404, 422] as $code) {
            $this->assertFalse(Delivery::is_retryable($code), (string) $code);
        }
    }

    /**
     * Excluded statuses are never selectable.
     *
     * @return void
     */
    public function test_excluded_statuses_are_never_selectable(): void
    {
        $this->assertSame(['pending', 'on-hold'], Settings::EXCLUDED_STATUSES);
    }

    /**
     * Sent right away without action scheduler.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @return void
     */
    public function test_sent_right_away_without_action_scheduler(): void
    {
        $order = $this->order(7);
        $this->post(['response' => ['code' => 200]]);
        $order->shouldReceive('add_order_note')->once();
        $this->logger->shouldReceive('log')->once()->with('info', 'Order #7 delivered (order.status_changed, uuid-1, HTTP 200).', ['source' => 'billmysales']);
        (new Delivery())->queue(7, 'processing', 'order.status_changed');
        $this->assertFalse(function_exists('as_enqueue_async_action'));
    }

    /**
     * Send action offered for billable orders when configured.
     *
     * @return void
     */
    public function test_send_action_offered_for_billable_orders_when_configured(): void
    {
        Functions\stubTranslationFunctions();
        $delivery = new Delivery();
        $this->assertSame(['x' => 'X', 'billmysales_send' => 'Send to BillMySales'], $delivery->order_actions(['x' => 'X'], $this->status_order('completed')));
        $this->assertSame(['x' => 'X'], $delivery->order_actions(['x' => 'X'], $this->status_order('on-hold')));
        $this->assertSame(['x' => 'X'], $delivery->order_actions(['x' => 'X']));
        $this->options(['billmysales_settings' => $this->settings(['secret' => ''])]);
        $this->assertSame(['x' => 'X'], $delivery->order_actions(['x' => 'X'], $this->status_order('completed')));
    }

    /**
     * Send action queues a new delivery whatever the selected statuses.
     *
     * @return void
     */
    public function test_send_action_queues_a_new_delivery_whatever_the_selected_statuses(): void
    {
        Functions\stubTranslationFunctions();
        $order = $this->status_order('cancelled');
        $order->shouldReceive('get_id')->andReturn(9);
        $order->shouldReceive('add_order_note')->once()->with('BillMySales: sending requested.');
        Functions\expect('as_enqueue_async_action')->once()->with(Delivery::HOOK, [9, 'order.resent', 'uuid-1', 0], Delivery::HOOK);
        (new Delivery())->on_order_action($order);
    }

    /**
     * Send action ignored for orders not ready to bill.
     *
     * @return void
     */
    public function test_send_action_ignored_for_orders_not_ready_to_bill(): void
    {
        Functions\expect('as_enqueue_async_action')->never();
        $order = $this->status_order('pending');
        $order->shouldReceive('add_order_note')->never();
        (new Delivery())->on_order_action($order);
        (new Delivery())->on_order_action(null);
    }

    /**
     * An order in a status.
     *
     * @param string $status Status, without the "wc-" prefix.
     * @return WC_Order&\Mockery\MockInterface
     */
    private function status_order(string $status)
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_status')->andReturn($status);
        return $order;
    }

    /**
     * An order whose REST representation is {"id": <id>, "status": "processing"}.
     *
     * @param int  $id   Order id.
     * @param bool $rest Whether the REST API answers (mocked) for it.
     * @return WC_Order&\Mockery\MockInterface
     */
    private function order(int $id, bool $rest = true)
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_id')->andReturn($id);
        Functions\when('wc_get_order')->justReturn($order);
        if ($rest) {
            $response = Mockery::mock();
            $response->shouldReceive('is_error')->andReturn(false);
            Functions\when('rest_do_request')->justReturn($response);
            $server = Mockery::mock();
            $server->shouldReceive('response_to_data')->andReturn(['id' => $id, 'status' => 'processing', '_links' => []]);
            Functions\when('rest_get_server')->justReturn($server);
        }
        return $order;
    }

    /**
     * wp_remote_post() returns $response.
     *
     * @param array<string, mixed>|\WP_Error $response
     *
     * @return void
     */
    private function post($response): void
    {
        Functions\when('wp_remote_post')->justReturn($response);
        $this->http();
    }

    /**
     * The HTTP helpers around wp_remote_post().
     *
     * @return void
     */
    private function http(): void
    {
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('home_url')->justReturn('https://shop.example/');
        Functions\when('WC')->justReturn((object) ['version' => '11.1.2']);
        Functions\when('is_wp_error')->alias(static fn ($value) => $value instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['response']['code'] ?? '');
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($response) => $response['body'] ?? '');
    }
}
