<?php

declare(strict_types=1);

/**
 * HTTP headers of a delivery.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce\Webhook;

use BillMySales\WooCommerce\Plugin;

defined('ABSPATH') || exit;

/**
 * Builds the headers of a delivery: the standard BillMySales headers plus
 * the ones BillMySales' WooCommerce datasource reads today (the same as
 * WooCommerce's native webhooks).
 */
final class Headers
{
    /**
     * Signature of a body: base64 of its HMAC-SHA256 with the secret.
     *
     * @param string $body   Request body.
     * @param string $secret Shared secret.
     * @return string
     */
    public static function signature(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Headers of a delivery.
     *
     * @param string $body             Request body.
     * @param string $secret           Shared secret.
     * @param string $event            Event, e.g. "order.status_changed".
     * @param string $delivery_id      UUID of the notification (the same on retries).
     * @param string $source           Store URL.
     * @param string $platform_version WooCommerce version.
     * @return array<string, string>
     */
    public static function build(string $body, string $secret, string $event, string $delivery_id, string $source, string $platform_version): array
    {
        $signature = self::signature($body, $secret);
        return [
            'Content-Type'                   => 'application/json',
            'User-Agent'                     => 'BillMySales-' . Plugin::PLATFORM . '/' . BILLMYSALES_VERSION,
            'X-BillMySales-Signature'        => $signature,
            'X-BillMySales-Platform'         => Plugin::PLATFORM,
            'X-BillMySales-Platform-Version' => $platform_version,
            'X-BillMySales-Plugin-Version'   => BILLMYSALES_VERSION,
            'X-BillMySales-Source'           => $source,
            'X-BillMySales-Event'            => $event,
            'X-BillMySales-Delivery'         => $delivery_id,
            // Read by BillMySales' WooCommerce datasource.
            'X-WC-Webhook-Signature'         => $signature,
            'X-WC-Webhook-Source'            => $source,
        ];
    }
}
