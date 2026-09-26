<?php

declare(strict_types=1);

/**
 * Tests of the delivery settings.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\TestsWooCommerce;

use BillMySales\WooCommerce\Settings;
use Brain\Monkey\Functions;

/**
 * @covers \BillMySales\WooCommerce\Settings
 */
final class SettingsTest extends TestCase
{
    /**
     * Sets up the mocks every test of the class needs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wc_get_order_statuses')->justReturn(
            [
                'wc-pending'    => 'Pending payment',
                'wc-processing' => 'Processing',
                'wc-on-hold'    => 'On hold',
                'wc-completed'  => 'Completed',
            ]
        );
    }

    /**
     * Excluded statuses are not offered.
     *
     * @return void
     */
    public function test_excluded_statuses_are_not_offered(): void
    {
        $this->assertSame(
            [
                'processing' => 'Processing',
                'completed'  => 'Completed',
            ],
            Settings::statuses()
        );
    }

    /**
     * Valid input is kept.
     *
     * @return void
     */
    public function test_valid_input_is_kept(): void
    {
        Functions\expect('add_settings_error')->never();
        $this->assertSame(
            [
                'url'      => 'https://billmysales.example/hook',
                'secret'   => ' a secret\\with "chars" ',
                'statuses' => ['completed'],
                'active'   => true,
            ],
            Settings::sanitize(
                [
                    'url'      => ' https://billmysales.example/hook ',
                    'secret'   => ' a secret\\with "chars" ',
                    'statuses' => ['completed', 'pending', 'unknown'],
                    'active'   => '1',
                ]
            )
        );
    }

    /**
     * Missing secret keeps the stored settings.
     *
     * @return void
     */
    public function test_missing_secret_keeps_the_stored_settings(): void
    {
        $stored = [
            'url'      => 'https://old.example/',
            'secret'   => 'old',
            'statuses' => ['processing'],
            'active'   => true,
        ];
        Functions\when('get_option')->justReturn($stored);
        Functions\expect('add_settings_error')->once();
        $this->assertSame($stored, Settings::sanitize(['url' => 'https://new.example/']));
    }

    /**
     * HTTP URL is saved with a warning.
     *
     * @return void
     */
    public function test_http_url_is_saved_with_a_warning(): void
    {
        Functions\expect('add_settings_error')->once()->with(Settings::OPTION, 'billmysales_insecure_url', \Mockery::any(), 'warning');
        $this->assertSame(
            'http://host.docker.internal:8099/',
            Settings::sanitize(
                [
                    'url'    => 'http://host.docker.internal:8099/',
                    'secret' => 'x',
                ]
            )['url']
        );
    }

    /**
     * Is ready.
     *
     * @return void
     */
    public function test_is_ready(): void
    {
        $this->assertTrue(
            Settings::is_ready(
                [
                    'url'      => 'u',
                    'secret'   => 's',
                    'statuses' => [],
                    'active'   => true,
                ]
            )
        );
        $this->assertFalse(
            Settings::is_ready(
                [
                    'url'      => 'u',
                    'secret'   => 's',
                    'statuses' => [],
                    'active'   => false,
                ]
            )
        );
        $this->assertFalse(
            Settings::is_ready(
                [
                    'url'      => 'u',
                    'secret'   => '',
                    'statuses' => [],
                    'active'   => true,
                ]
            )
        );
    }
}
