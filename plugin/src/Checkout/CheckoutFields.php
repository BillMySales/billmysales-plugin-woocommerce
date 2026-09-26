<?php

declare(strict_types=1);

/**
 * Custom fields of the block checkout.
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce\Checkout;

use WC_Order;

defined('ABSPATH') || exit;

/**
 * Stores the admin-defined checkout fields and adds them to both checkouts:
 * the block checkout ("Additional Checkout Fields" API, WooCommerce 8.9+)
 * and the classic (shortcode) one (the woocommerce_checkout_fields filter,
 * in the billing section). Both store each value in the same order meta, so
 * the payload is the same whichever checkout the store uses.
 */
final class CheckoutFields
{
    /**
     * Option that stores the fields.
     */
    public const OPTION = 'billmysales_checkout_fields';

    /**
     * Settings group of the admin form.
     */
    public const GROUP = 'billmysales_checkout_fields_group';

    /**
     * Namespace of the field ids: WooCommerce stores each value in the
     * order meta "_wc_other/<namespace>/<key>".
     */
    public const NAMESPACE_ID = 'billmysales';

    /**
     * Prefix of the fields' names in the classic checkout's form (not
     * "billing_": WooCommerce would also store them as meta of its own).
     */
    public const CLASSIC_PREFIX = 'billmysales_';

    /**
     * Position of the fields in the classic checkout's billing section
     * (after the email, 110).
     */
    public const CLASSIC_PRIORITY = 200;

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('woocommerce_init', [$this, 'register_fields']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_section_title'], 20);
        add_filter('woocommerce_checkout_fields', [$this, 'classic_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_classic_fields'], 10, 2);
    }

    /**
     * Adds the fields to the classic checkout's billing section. WooCommerce
     * validates the required ones and sanitizes the values.
     *
     * @param array<string, array<string, array<string, mixed>>> $fields Checkout fields, by section.
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function classic_fields(array $fields): array
    {
        foreach (self::get() as $index => $field) {
            $definition = [
                'label'    => $field['label'],
                'required' => $field['required'],
                'type'     => 'text',
                'class'    => ['form-row-wide'],
                'priority' => self::CLASSIC_PRIORITY + $index,
            ];
            if (!empty($field['values'])) {
                $definition['type']    = 'select';
                $definition['options'] = ['' => __('Select an option', 'billmysales')] + array_combine($field['values'], $field['values']);
            }
            $fields['billing'][self::CLASSIC_PREFIX . $field['key']] = $definition;
        }
        return $fields;
    }

    /**
     * Stores the classic checkout's values in the order meta the block
     * checkout uses.
     *
     * @param WC_Order             $order Order being created.
     * @param array<string, mixed> $data  Posted checkout data (sanitized by WooCommerce).
     * @return void
     */
    public function save_classic_fields(WC_Order $order, array $data): void
    {
        foreach (self::get() as $field) {
            $value = $data[self::CLASSIC_PREFIX . $field['key']] ?? '';
            if ('' !== $value) {
                $order->update_meta_data('_wc_other/' . self::NAMESPACE_ID . '/' . $field['key'], $value);
            }
        }
    }

    /**
     * Returns the stored fields.
     *
     * @return array<int, array{key: string, label: string, values: string[], required: bool}>
     */
    public static function get(): array
    {
        $fields = get_option(self::OPTION, []);
        return is_array($fields) ? $fields : [];
    }

    /**
     * Validates the fields form. Rows without a label are dropped; the key
     * is always derived from the label (unique within the list).
     *
     * @param mixed $input Raw form data.
     * @return array<int, array{key: string, label: string, values: string[], required: bool}>
     */
    public static function sanitize($input): array
    {
        $fields = [];
        $keys   = [];
        $rows   = is_array($input) && isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];

        foreach ($rows as $row) {
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ('' === $label) {
                continue;
            }
            $key    = self::unique_key($label, $keys);
            $keys[] = $key;
            $values = [];
            foreach (explode(',', isset($row['values']) ? (string) $row['values'] : '') as $value) {
                $value = sanitize_text_field($value);
                if ('' !== $value) {
                    $values[] = $value;
                }
            }
            $fields[] = [
                'key'      => $key,
                'label'    => $label,
                'values'   => $values,
                'required' => !empty($row['required']),
            ];
        }//end foreach

        return $fields;
    }

    /**
     * Builds a key from a label, with a numeric suffix when it's taken
     * (two "RUT" fields give "rut" and "rut_2", "Razón social" gives
     * "razon-social").
     *
     * @param string   $label Field label.
     * @param string[] $taken Keys already used.
     * @return string
     */
    public static function unique_key(string $label, array $taken): string
    {
        $base = sanitize_key(sanitize_title($label));
        $base = '' === $base ? 'field' : $base;
        $key  = $base;
        $i    = 2;
        while (in_array($key, $taken, true)) {
            $key = $base . '_' . $i;
            ++$i;
        }
        return $key;
    }

    /**
     * Registers the fields in the block checkout, in the "order" location
     * (their own section). A field WooCommerce rejects is logged, so it
     * never breaks the checkout.
     *
     * @return void
     */
    public function register_fields(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        foreach (self::get() as $field) {
            $definition = [
                'id'       => self::NAMESPACE_ID . '/' . $field['key'],
                'label'    => $field['label'],
                'location' => 'order',
                'required' => $field['required'],
                'type'     => 'text',
            ];
            if (!empty($field['values'])) {
                $definition['type']    = 'select';
                $definition['options'] = array_map(
                    static fn ($value) => [
                            'value' => $value,
                            'label' => $value,
                        ],
                    $field['values']
                );
            }
            try {
                woocommerce_register_additional_checkout_field($definition);
            } catch (\Throwable $e) {
                wc_get_logger()->error(
                    sprintf('Checkout field "%s" not registered: %s', $field['key'], $e->getMessage()),
                    ['source' => 'billmysales']
                );
            }
        }//end foreach
    }

    /**
     * Replaces the title of the checkout's "Additional order information"
     * section, where the fields are shown, with "Billing information".
     * WooCommerce Blocks has no PHP filter for it, so the string is
     * replaced through WordPress' JavaScript i18n filter (wp.hooks), which
     * every checkout block depends on.
     *
     * @return void
     */
    public function enqueue_section_title(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || empty(self::get())) {
            return;
        }
        wp_add_inline_script('wp-hooks', self::section_title_script(), 'after');
    }

    /**
     * The inline script of enqueue_section_title().
     *
     * @return string
     */
    public static function section_title_script(): string
    {
        // The filter gets the source (English) text and its text domain,
        // whatever the site's language.
        return sprintf(
            '(function(){if(!window.wp||!wp.hooks){return;}wp.hooks.addFilter("i18n.gettext","billmysales/section-title",function(translation,text,domain){return (text===%1$s&&domain==="woocommerce")?%2$s:translation;});})();',
            wp_json_encode('Additional order information'),
            wp_json_encode(__('Billing information', 'billmysales'))
        );
    }

    /**
     * Values of the fields in an order, in WooCommerce's meta_data shape
     * ({id, key, value}). WooCommerce stores them as private meta ("_"
     * prefix), which its REST API leaves out.
     *
     * @param WC_Order $order Order.
     * @return array<int, array{id: int, key: string, value: mixed}>
     */
    public static function order_meta(WC_Order $order): array
    {
        $meta = [];
        foreach (self::get() as $field) {
            $meta[] = [
                'id'    => 0,
                'key'   => $field['key'],
                'value' => $order->get_meta('_wc_other/' . self::NAMESPACE_ID . '/' . $field['key']),
            ];
        }
        return $meta;
    }
}
