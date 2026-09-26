<?php

declare(strict_types=1);

/**
 * Runs when the plugin is deleted from the admin (not on deactivation):
 * removes its options and pending delivery jobs, on every site of a
 * multisite network.
 *
 * @package BillMySales\WooCommerce
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$billmysales_cleanup = static function (): void {
    delete_option('billmysales_settings');
    delete_option('billmysales_checkout_fields');
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('billmysales_deliver');
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $billmysales_site_id) {
        switch_to_blog($billmysales_site_id);
        $billmysales_cleanup();
        restore_current_blog();
    }
} else {
    $billmysales_cleanup();
}
