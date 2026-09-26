<?php

declare(strict_types=1);

/**
 * Delivery settings: endpoint URL, secret, statuses that notify.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Reads, validates and stores the delivery settings.
 */
final class Settings
{
    /**
     * Option that stores the settings.
     */
    public const OPTION = 'billmysales_settings';

    /**
     * Settings group of the admin form.
     */
    public const GROUP = 'billmysales_settings_group';

    /**
     * Statuses never offered nor sent: orders in them aren't ready to bill.
     * "pending" is an order awaiting payment (not paid, possibly never
     * finished); "on-hold" is awaiting the merchant's confirmation of the
     * payment (e.g. a bank transfer not yet received).
     */
    public const EXCLUDED_STATUSES = ['pending', 'on-hold'];

    /**
     * Default values.
     *
     * @var array{url: string, secret: string, statuses: string[], active: bool}
     */
    public const DEFAULTS = [
        'url'      => '',
        'secret'   => '',
        'statuses' => [],
        'active'   => false,
    ];

    /**
     * Returns the stored settings, with defaults.
     *
     * @return array{url: string, secret: string, statuses: string[], active: bool}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::DEFAULTS);
    }

    /**
     * Whether deliveries are enabled and fully configured.
     *
     * @param array{url: string, secret: string, statuses: string[], active: bool} $settings Settings.
     * @return bool
     */
    public static function is_ready(array $settings): bool
    {
        return $settings['active'] && '' !== $settings['url'] && '' !== $settings['secret'];
    }

    /**
     * WooCommerce order statuses that can notify, without the "wc-" prefix
     * (the form of the status hooks).
     *
     * @return array<string, string> Status => label.
     */
    public static function statuses(): array
    {
        $statuses = [];
        foreach (wc_get_order_statuses() as $key => $label) {
            $status = 0 === strpos($key, 'wc-') ? substr($key, 3) : $key;
            if (!in_array($status, self::EXCLUDED_STATUSES, true)) {
                $statuses[$status] = $label;
            }
        }
        return $statuses;
    }

    /**
     * Validates the form data before it is stored. The URL and the secret
     * are required: without them nothing is stored, so the admin sees that
     * the integration isn't configured.
     *
     * @param mixed $input Raw form data.
     * @return array{url: string, secret: string, statuses: string[], active: bool}
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $url   = isset($input['url']) ? esc_url_raw(trim((string) $input['url'])) : '';
        // Stored as typed (options.php already unslashes it): it must match
        // the secret in BillMySales.
        $secret = isset($input['secret']) ? (string) $input['secret'] : '';

        if ('' === $url || '' === $secret) {
            add_settings_error(
                self::OPTION,
                'billmysales_missing_required',
                __('The URL and the secret are required: the settings were not saved.', 'billmysales'),
                'error'
            );
            return self::get();
        }

        if (0 !== stripos($url, 'https://')) {
            add_settings_error(
                self::OPTION,
                'billmysales_insecure_url',
                __('The URL does not use HTTPS: order data will travel unencrypted. Use an https:// URL.', 'billmysales'),
                'warning'
            );
        }

        $valid    = array_keys(self::statuses());
        $statuses = [];
        foreach (isset($input['statuses']) && is_array($input['statuses']) ? $input['statuses'] : [] as $status) {
            $status = sanitize_key($status);
            if (in_array($status, $valid, true)) {
                $statuses[] = $status;
            }
        }

        return [
            'url'      => $url,
            'secret'   => $secret,
            'statuses' => $statuses,
            'active'   => !empty($input['active']),
        ];
    }
}
