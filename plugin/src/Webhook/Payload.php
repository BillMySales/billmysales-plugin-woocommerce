<?php

declare(strict_types=1);

/**
 * The order payload sent to BillMySales.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce\Webhook;

use BillMySales\WooCommerce\Checkout\CheckoutFields;
use WC_Order;
use WP_REST_Request;

defined('ABSPATH') || exit;

/**
 * Builds the payload: WooCommerce's own REST representation of the order
 * (GET /wc/v3/orders/<id>), as WooCommerce's native webhooks send it.
 */
final class Payload
{
    /**
     * Fields of the REST order that BillMySales' WooCommerce datasource
     * reads; the rest (links, refunds, cart hash, the order's own
     * meta_data...) is left out.
     */
    public const FIELDS = [
        'id',
        'number',
        'status',
        'date_created',
        'date_paid',
        'date_completed',
        'prices_include_tax',
        'total',
        'total_tax',
        'discount_total',
        'discount_tax',
        'shipping_total',
        'shipping_tax',
        'customer_id',
        'billing',
        'shipping',
        'line_items',
        'fee_lines',
        'coupon_lines',
        'shipping_lines',
        'currency',
        'payment_method',
        'payment_method_title',
    ];

    /**
     * Builds the payload of an order, with the checkout fields' values in
     * meta_data.
     *
     * The REST request runs through rest_do_request(), the stable way to
     * reuse WooCommerce's controller (its internal methods change between
     * versions). Reading an order needs permissions a guest checkout
     * doesn't have: instead of switching the current user (as WooCommerce's
     * webhooks do), a filter allows reading this one order only.
     *
     * @param WC_Order $order Order.
     * @return array<string, mixed>
     * @throws \RuntimeException When WooCommerce's REST API returns an error.
     */
    public static function build(WC_Order $order): array
    {
        $order_id = $order->get_id();
        $allow    = static fn ($permission, $context, $object_id, $post_type) => ('shop_order' === $post_type && 'read' === $context && (int) $object_id === $order_id) ? true : $permission;

        add_filter('woocommerce_rest_check_permissions', $allow, 10, 4);
        try {
            $response = rest_do_request(new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id));
            if ($response->is_error()) {
                throw new \RuntimeException($response->as_error()->get_error_message());
            }
            $data = rest_get_server()->response_to_data($response, false);
        } finally {
            remove_filter('woocommerce_rest_check_permissions', $allow, 10);
        }

        $payload = array_intersect_key((array) $data, array_flip(self::FIELDS));
        $meta    = CheckoutFields::order_meta($order);
        if ($meta) {
            $payload['meta_data'] = $meta;
        }
        return $payload;
    }
}
