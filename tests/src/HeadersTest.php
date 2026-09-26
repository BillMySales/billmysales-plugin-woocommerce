<?php

declare(strict_types=1);

/**
 * Tests of the delivery headers.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Webhook\Headers;

/**
 * @covers \BillMySales\WooCommerce\Webhook\Headers
 */
final class HeadersTest extends TestCase
{
    /**
     * Signature is base64 HMAC SHA-256.
     *
     * @return void
     */
    public function test_signature_is_base64_hmac_sha256(): void
    {
        $this->assertSame(
            base64_encode(hash_hmac('sha256', '{"id":1}', 's3cret', true)),
            Headers::signature('{"id":1}', 's3cret')
        );
    }

    /**
     * Standard and datasource headers.
     *
     * @return void
     */
    public function test_standard_and_datasource_headers(): void
    {
        $headers   = Headers::build('{"id":1}', 's3cret', 'order.status_changed', 'uuid-1', 'https://shop.example/', '11.1.2');
        $signature = Headers::signature('{"id":1}', 's3cret');

        $this->assertSame($signature, $headers['X-BillMySales-Signature']);
        $this->assertSame($signature, $headers['X-WC-Webhook-Signature']);
        $this->assertSame('woocommerce', $headers['X-BillMySales-Platform']);
        $this->assertSame('11.1.2', $headers['X-BillMySales-Platform-Version']);
        $this->assertSame('1.2.3', $headers['X-BillMySales-Plugin-Version']);
        $this->assertSame('https://shop.example/', $headers['X-BillMySales-Source']);
        $this->assertSame('https://shop.example/', $headers['X-WC-Webhook-Source']);
        $this->assertSame('order.status_changed', $headers['X-BillMySales-Event']);
        $this->assertSame('uuid-1', $headers['X-BillMySales-Delivery']);
        $this->assertSame('BillMySales-woocommerce/1.2.3', $headers['User-Agent']);
    }

    /**
     * Secret is never sent.
     *
     * @return void
     */
    public function test_secret_is_never_sent(): void
    {
        foreach (Headers::build('{}', 's3cret', 'order.created', 'uuid-1', 'https://shop.example/', '11.1.2') as $name => $value) {
            $this->assertStringNotContainsString('s3cret', $name . ': ' . $value);
        }
    }
}
