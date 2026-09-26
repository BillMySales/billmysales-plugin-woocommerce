<?php
/**
 * Settings page (WooCommerce > BillMySales).
 *
 * @package BillMySales\WooCommerce
 */

namespace BillMySales\WooCommerce\Admin;

use BillMySales\WooCommerce\Checkout\CheckoutFields;
use BillMySales\WooCommerce\Plugin;
use BillMySales\WooCommerce\Settings;

defined('ABSPATH') || exit;

/**
 * The settings page, with two tabs (the convention of WooCommerce's own
 * settings): the delivery settings and the checkout fields.
 */
final class SettingsPage
{
    /**
     * Page slug.
     */
    public const PAGE = 'billmysales';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(BILLMYSALES_FILE), [$this, 'action_links']);
    }

    /**
     * Adds the page to the WooCommerce menu.
     *
     * @return void
     */
    public function add_page(): void
    {
        add_submenu_page('woocommerce', 'BillMySales', 'BillMySales', 'manage_woocommerce', self::PAGE, [$this, 'render']);
    }

    /**
     * Registers both options (saved through options.php, nonce included).
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting(Settings::GROUP, Settings::OPTION, ['sanitize_callback' => [Settings::class, 'sanitize']]);
        register_setting(CheckoutFields::GROUP, CheckoutFields::OPTION, ['sanitize_callback' => [CheckoutFields::class, 'sanitize']]);
    }

    /**
     * Adds a "Settings" link to the plugin's row in the plugins list.
     *
     * @param string[] $links Action links.
     * @return string[]
     */
    public function action_links(array $links): array
    {
        array_unshift(
            $links,
            sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=' . self::PAGE)), esc_html__('Settings', 'billmysales'))
        );
        return $links;
    }

    /**
     * Loads the page's style and script, on this page only.
     *
     * @param string $hook_suffix Current admin page.
     * @return void
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if ('woocommerce_page_' . self::PAGE !== $hook_suffix) {
            return;
        }
        wp_enqueue_style('billmysales-admin', plugins_url('assets/css/admin.css', BILLMYSALES_FILE), [], BILLMYSALES_VERSION);
        wp_enqueue_script('billmysales-admin', plugins_url('assets/js/admin.js', BILLMYSALES_FILE), [], BILLMYSALES_VERSION, true);
        wp_localize_script(
            'billmysales-admin',
            'BillMySalesAdmin',
            [
                'showSecret' => __('Show secret', 'billmysales'),
                'hideSecret' => __('Hide secret', 'billmysales'),
            ]
        );
    }

    /**
     * Renders the page.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // Only picks the tab to show (compared with a fixed value, nothing
        // processed or stored): no nonce needed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab  = isset($_GET['tab']) && 'checkout-fields' === $_GET['tab'] ? 'checkout-fields' : 'settings';
        $tabs = [
            'settings'        => __('Settings', 'billmysales'),
            'checkout-fields' => __('Checkout fields', 'billmysales'),
        ];
        ?>
        <div class="wrap">
            <h1>BillMySales</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $id => $label) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&tab=' . $id)); ?>" class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php settings_errors(); ?>
            <?php 'checkout-fields' === $tab ? $this->render_fields_tab() : $this->render_settings_tab(); ?>
        </div>
        <?php
    }

    /**
     * The "Settings" tab: status, URL, secret, statuses that notify.
     *
     * @return void
     */
    private function render_settings_tab(): void
    {
        $settings = Settings::get();
        $name     = Settings::OPTION;
        ?>
        <p><?php esc_html_e('Orders are sent to BillMySales when they reach one of the selected statuses, signed with the secret.', 'billmysales'); ?></p>
        <?php if ('' === $settings['url'] || '' === $settings['secret']) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('Set the URL and the secret: no orders are sent until both are saved.', 'billmysales'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields(Settings::GROUP); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'billmysales'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[active]" value="1" <?php checked($settings['active']); ?> />
                            <?php esc_html_e('Active', 'billmysales'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When unchecked, no orders are sent; the settings are kept.', 'billmysales'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="billmysales-url"><?php esc_html_e('Notification URL', 'billmysales'); ?> *</label></th>
                    <td><input type="url" id="billmysales-url" name="<?php echo esc_attr($name); ?>[url]" value="<?php echo esc_attr($settings['url']); ?>" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="billmysales-secret"><?php esc_html_e('Secret', 'billmysales'); ?> *</label></th>
                    <td>
                        <input type="password" id="billmysales-secret" name="<?php echo esc_attr($name); ?>[secret]" value="<?php echo esc_attr($settings['secret']); ?>" class="regular-text" autocomplete="off" required />
                        <button type="button" class="button button-secondary hide-if-no-js" id="billmysales-secret-toggle" aria-label="<?php esc_attr_e('Show secret', 'billmysales'); ?>">
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                        </button>
                        <p class="description"><?php esc_html_e('The secret shared with BillMySales, used to sign each notification.', 'billmysales'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Statuses that notify', 'billmysales'); ?> *</th>
                    <td>
                        <?php foreach (Settings::statuses() as $status => $label) : ?>
                            <label class="billmysales-status">
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[statuses][]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $settings['statuses'], true)); ?> />
                                <?php echo esc_html($label); ?> <code><?php echo esc_html($status); ?></code>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('An order is sent each time it changes to one of these statuses.', 'billmysales'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save settings', 'billmysales')); ?>
        </form>
        <?php
    }

    /**
     * The "Checkout fields" tab: a list of fields the admin can add, edit
     * and remove.
     *
     * @return void
     */
    private function render_fields_tab(): void
    {
        $fields = CheckoutFields::get();
        if (empty($fields)) {
            $fields = [self::empty_field()];
        }
        ?>
        <p><?php esc_html_e('These fields are added to the checkout (block and classic) and sent to BillMySales with the order.', 'billmysales'); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields(CheckoutFields::GROUP); ?>
            <div id="billmysales-fields" data-next-index="<?php echo esc_attr((string) count($fields)); ?>">
                <?php
                foreach ($fields as $index => $field) {
                    $this->render_field_row((string) $index, $field);
                }
        ?>
            </div>
            <p><button type="button" class="button" id="billmysales-add-field"><?php esc_html_e('+ Add field', 'billmysales'); ?></button></p>
            <?php submit_button(__('Save fields', 'billmysales')); ?>
        </form>
        <template id="billmysales-field-template">
            <?php $this->render_field_row('__INDEX__', self::empty_field()); ?>
        </template>
        <?php
    }

    /**
     * An empty field row.
     *
     * @return array{key: string, label: string, values: string[], required: bool}
     */
    private static function empty_field(): array
    {
        return [
            'key'      => '',
            'label'    => '',
            'values'   => [],
            'required' => false,
        ];
    }

    /**
     * One field of the list (also the template the script clones).
     *
     * @param string                                                              $index Row index, or "__INDEX__" in the template.
     * @param array{key: string, label: string, values: string[], required: bool} $field Field.
     * @return void
     */
    private function render_field_row(string $index, array $field): void
    {
        $name = CheckoutFields::OPTION . '[fields][' . $index . ']';
        ?>
        <div class="billmysales-field">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Label', 'billmysales'); ?> *</th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($field['label']); ?>" class="regular-text" placeholder="<?php esc_attr_e('E.g. RUT, Company name, Business activity', 'billmysales'); ?>" />
                        <?php if ('' !== $field['key']) : ?>
                            <p class="description">
                                <?php
                                /* translators: %s: the field's key. */
                                printf(esc_html__('Key: %s', 'billmysales'), '<code>' . esc_html($field['key']) . '</code>');
                            ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Values', 'billmysales'); ?></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($name); ?>[values]" value="<?php echo esc_attr(implode(', ', $field['values'])); ?>" class="regular-text" placeholder="<?php esc_attr_e('E.g. Receipt, Invoice', 'billmysales'); ?>" />
                        <p class="description"><?php esc_html_e('Comma-separated values show the field as a list of those options; empty, it is a free text field.', 'billmysales'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Required', 'billmysales'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[required]" value="1" <?php checked($field['required']); ?> />
                            <?php esc_html_e('The customer must fill it in to place the order', 'billmysales'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td><button type="button" class="button-link-delete billmysales-remove-field"><?php esc_html_e('Remove this field', 'billmysales'); ?></button></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
