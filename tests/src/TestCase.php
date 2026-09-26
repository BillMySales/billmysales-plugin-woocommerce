<?php

declare(strict_types=1);

/**
 * Base test case.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Sets up and tears down Brain Monkey.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Sets up the mocks every test of the class needs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\stubs(
            [
                '__',
                'esc_url_raw'         => static fn ($url) => $url,
                'sanitize_key'        => static fn ($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)),
                'sanitize_text_field' => static fn ($text) => trim((string) $text),
                'wp_parse_args'       => static fn ($args, $defaults) => array_merge($defaults, $args),
            ]
        );
    }

    /**
     * Stored options (get_option), by name.
     *
     * @param array<string, mixed> $options
     *
     * @return void
     */
    protected function options(array $options): void
    {
        Monkey\Functions\when('get_option')->alias(static fn ($name, $default = false) => $options[$name] ?? $default);
    }

    /**
     * Delivery settings, ready to send.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    protected function settings(array $changes = []): array
    {
        return $changes + [
            'url' => 'https://billmysales.example/hook',
            'secret' => 's3cret',
            'statuses' => ['processing', 'completed'],
            'active' => true,
        ];
    }

    /**
     * Removes the mocks and the state a test changed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
