<?php

declare(strict_types=1);

/**
 * Asynchronous delivery of orders to BillMySales, with retries.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce\Webhook;

use BillMySales\WooCommerce\Plugin;
use BillMySales\WooCommerce\Settings;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Queues a delivery when an order reaches a selected status (or the
 * merchant sends it from the order actions), and sends it from Action
 * Scheduler (WooCommerce's own job queue), so the checkout and the admin
 * never wait for BillMySales. Failed deliveries are retried with a growing
 * delay; BillMySales is idempotent, so a repeated delivery is harmless. The
 * payload is built when the job runs, with the order's data at that
 * moment.
 *
 * Every attempt is recorded as a private order note (what the merchant
 * sees in the order) and in WooCommerce's log (source "billmysales", kept
 * for WooCommerce's log retention period).
 */
final class Delivery
{
    /**
     * Action Scheduler hook and group of the delivery jobs.
     */
    public const HOOK = 'billmysales_deliver';

    /**
     * Order action that sends the order again.
     */
    public const ORDER_ACTION = 'billmysales_send';

    /**
     * Delay (seconds) before each retry; after the last one, the delivery
     * is given up.
     */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    /**
     * Request timeout, in seconds (it runs in the background).
     */
    public const TIMEOUT = 30;

    /**
     * Notifications queued in this request (order:status), so the two hooks
     * of a new order don't queue it twice.
     *
     * @var array<string, bool>
     */
    private array $queued = [];

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 3);
        add_action('woocommerce_new_order', [$this, 'on_new_order'], 10, 2);
        add_filter('woocommerce_order_actions', [$this, 'order_actions'], 10, 2);
        add_action('woocommerce_order_action_' . self::ORDER_ACTION, [$this, 'on_order_action']);
        add_action(self::HOOK, [$this, 'deliver'], 10, 4);
    }

    /**
     * An order changed status.
     *
     * @param int    $order_id   Order id.
     * @param string $old_status Previous status.
     * @param string $new_status New status.
     * @return void
     */
    public function on_status_changed(int $order_id, string $old_status, string $new_status): void
    {
        $this->queue($order_id, $new_status, 'order.status_changed');
    }

    /**
     * An order was created (possibly directly in a selected status, e.g.
     * from the admin).
     *
     * @param int           $order_id Order id.
     * @param WC_Order|null $order    Order.
     * @return void
     */
    public function on_new_order(int $order_id, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $this->queue($order_id, $order->get_status(), 'order.created');
        }
    }

    /**
     * Adds "Send to BillMySales" to the order actions, when deliveries are
     * configured and the order's status is one that can be billed.
     *
     * @param array<string, string> $actions Order actions.
     * @param WC_Order|null         $order   Order.
     * @return array<string, string>
     */
    public function order_actions(array $actions, $order = null): array
    {
        if ($order instanceof WC_Order && self::can_send($order)) {
            $actions[self::ORDER_ACTION] = __('Send to BillMySales', 'billmysales');
        }
        return $actions;
    }

    /**
     * The merchant chose "Send to BillMySales": a new delivery, whatever
     * the statuses selected in the settings.
     *
     * @param mixed $order The order WooCommerce passes.
     * @return void
     */
    public function on_order_action($order): void
    {
        if (!$order instanceof WC_Order || !self::can_send($order)) {
            return;
        }
        $order->add_order_note(__('BillMySales: sending requested.', 'billmysales'));
        $this->enqueue($order->get_id(), 'order.resent');
    }

    /**
     * Queues a delivery when the status is one of the selected ones.
     *
     * @param int    $order_id Order id.
     * @param string $status   Status, without the "wc-" prefix.
     * @param string $event    Event name.
     * @return void
     */
    public function queue(int $order_id, string $status, string $event): void
    {
        $settings = Settings::get();
        if (
            !Settings::is_ready($settings)
            || in_array($status, Settings::EXCLUDED_STATUSES, true)
            || !in_array($status, $settings['statuses'], true)
            || isset($this->queued[$order_id . ':' . $status])
        ) {
            return;
        }
        $this->queued[$order_id . ':' . $status] = true;
        $this->enqueue($order_id, $event);
    }

    /**
     * Sends one delivery (an Action Scheduler job); schedules a retry when
     * it fails with an error worth retrying.
     *
     * @param int    $order_id    Order id.
     * @param string $event       Event name.
     * @param string $delivery_id UUID of the notification.
     * @param int    $attempt     Retries done so far.
     * @return void
     */
    public function deliver(int $order_id, string $event, string $delivery_id, int $attempt): void
    {
        $settings = Settings::get();
        $order    = wc_get_order($order_id);
        if (!Settings::is_ready($settings) || !$order instanceof WC_Order) {
            return;
        }

        [$result, $detail] = $this->send($order, $settings, $event, $delivery_id);
        if ('delivered' === $result) {
            /* translators: %s: result, e.g. "HTTP 200". */
            $order->add_order_note(sprintf(__('BillMySales: sent (%s).', 'billmysales'), $detail));
            self::log('info', sprintf('Order #%d delivered (%s, %s, %s).', $order_id, $event, $delivery_id, $detail));
            return;
        }
        if ('rejected' === $result) {
            /* translators: %s: BillMySales' answer, e.g. "HTTP 422: ...". */
            $order->add_order_note(sprintf(__('BillMySales: rejected (%s). Fix the cause and use "Send to BillMySales" in the order actions.', 'billmysales'), $detail));
            self::log('error', sprintf('Order #%d rejected by BillMySales (%s, %s): %s', $order_id, $event, $delivery_id, $detail));
            return;
        }
        if (isset(self::RETRY_DELAYS[$attempt]) && function_exists('as_schedule_single_action')) {
            $delay = self::RETRY_DELAYS[$attempt];
            as_schedule_single_action(time() + $delay, self::HOOK, [$order_id, $event, $delivery_id, $attempt + 1], self::HOOK);
            /* translators: 1: the error, 2: retry number, 3: delay, e.g. "5 mins". */
            $order->add_order_note(sprintf(__('BillMySales: not sent (%1$s), retry %2$d in %3$s.', 'billmysales'), $detail, $attempt + 1, human_time_diff(0, $delay)));
            self::log('warning', sprintf('Order #%d not delivered (%s), retry %d in %d s.', $order_id, $detail, $attempt + 1, $delay));
            return;
        }
        /* translators: %s: the error. */
        $order->add_order_note(sprintf(__('BillMySales: not sent (%s), no more retries. Use "Send to BillMySales" in the order actions to try again.', 'billmysales'), $detail));
        self::log('error', sprintf('Order #%d not delivered (%s), given up.', $order_id, $detail));
    }

    /**
     * Whether an HTTP status is worth retrying: timeouts, rate limits and
     * server errors. Other client errors won't change on a retry.
     *
     * @param int $code HTTP status.
     * @return bool
     */
    public static function is_retryable(int $code): bool
    {
        return 408 === $code || 429 === $code || $code >= 500;
    }

    /**
     * Whether an order can be sent: deliveries configured and a status
     * that can be billed.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    private static function can_send(WC_Order $order): bool
    {
        return Settings::is_ready(Settings::get()) && !in_array($order->get_status(), Settings::EXCLUDED_STATUSES, true);
    }

    /**
     * Queues a new delivery (its own UUID), or sends it right away without
     * Action Scheduler.
     *
     * @param int    $order_id Order id.
     * @param string $event    Event name.
     * @return void
     */
    private function enqueue(int $order_id, string $event): void
    {
        $args = [$order_id, $event, wp_generate_uuid4(), 0];
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, $args, self::HOOK);
        } else {
            $this->deliver(...$args);
        }
    }

    /**
     * Posts the order.
     *
     * @param WC_Order                                                             $order       Order.
     * @param array{url: string, secret: string, statuses: string[], active: bool} $settings    Settings.
     * @param string                                                               $event       Event name.
     * @param string                                                               $delivery_id UUID of the notification.
     * @return array{string, string} The result ("delivered", "retry" or
     *                               "rejected") and its detail.
     */
    private function send(WC_Order $order, array $settings, string $event, string $delivery_id): array
    {
        try {
            $body = (string) wp_json_encode(Payload::build($order));
        } catch (\Throwable $e) {
            return ['retry', 'payload: ' . $e->getMessage()];
        }
        $response = wp_remote_post(
            $settings['url'],
            [
                'timeout' => self::TIMEOUT,
                'headers' => Headers::build($body, $settings['secret'], $event, $delivery_id, home_url('/'), (string) WC()->version),
                'body'    => $body,
            ]
        );
        if (is_wp_error($response)) {
            return ['retry', $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['delivered', 'HTTP ' . $code];
        }
        if (self::is_retryable($code)) {
            return ['retry', 'HTTP ' . $code];
        }
        $answer = trim(substr((string) wp_remote_retrieve_body($response), 0, 300));
        return ['rejected', 'HTTP ' . $code . ('' !== $answer ? ': ' . $answer : '')];
    }

    /**
     * Writes to WooCommerce's log (source "billmysales").
     *
     * @param string $level   Log level.
     * @param string $message Message.
     * @return void
     */
    private static function log(string $level, string $message): void
    {
        wc_get_logger()->log($level, $message, ['source' => Plugin::SLUG]);
    }
}
