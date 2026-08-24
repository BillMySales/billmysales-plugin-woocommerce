<?php
/**
 * Plugin Name: BillMySales
 * Description: Notifica a una URL configurable (webhook propio) cuando una orden de WooCommerce llega a alguno de los estados seleccionados, e incluye campos personalizados configurables agregados al checkout por bloques (plantilla estándar). El destino tiene su propio secreto (obligatorio) y puede activarse/desactivarse sin borrar su configuración. Este plugin envía datos del pedido (nombre, apellido, email, teléfono, montos y campos personalizados) a la URL configurada por el propio administrador de la tienda.
 * Version: 1.0.0
 * Author: Derafu
 * Text Domain: billmysales
 * Requires Plugins: woocommerce
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 8.9
 * WC tested up to: 10.9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Evita acceso directo al archivo.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprueba si WooCommerce está activo, sin depender del ORDEN en que
 * WordPress carga los plugins.
 *
 * IMPORTANTE: no se puede usar solo class_exists('WooCommerce') aquí,
 * a nivel superior del archivo. WordPress carga los plugins más o menos
 * en orden alfabético de su carpeta, y "BillMySales" carga antes que
 * "woocommerce" -- en ese momento la clase WooCommerce TODAVÍA no
 * existe, aunque esté activo, así que ese chequeo solo (usado en una
 * versión anterior) cortaba el plugin entero con un falso negativo.
 *
 * Por eso primero se revisa la lista de plugins activos directamente
 * desde la opción guardada (no depende de que la clase ya esté
 * cargada), y además se revisa active_sitewide_plugins para cubrir el
 * caso de WooCommerce activado a nivel de RED en un multisitio.
 * class_exists('WooCommerce') queda solo como respaldo adicional.
 *
 * @return bool
 */
function wcon_is_woocommerce_active()
{
    $active_plugins = (array) get_option('active_plugins', []);

    if (is_multisite()) {
        $network_active = (array) get_site_option('active_sitewide_plugins', []);
        $active_plugins = array_merge($active_plugins, array_keys($network_active));
    }

    if (in_array('woocommerce/woocommerce.php', $active_plugins, true)) {
        return true;
    }

    return class_exists('WooCommerce');
}

// Si WooCommerce no está activo, no hacemos nada.
if (!wcon_is_woocommerce_active()) {
    return;
}

// ============================================================
// SECCIÓN 0: Arranque, constantes y estructura general
// ============================================================
// Todo lo que no pertenece a una sola funcionalidad especifica:
// nombres de las opciones guardadas, compatibilidad con HPOS, el
// registro de la pagina en el menu, y el enrutador de pestañas que
// decide cual de los 2 bloques de funcionalidad mostrar.
// ============================================================

define('WCON_OPTION_KEY', 'wcon_settings');

/**
 * Título fijo que reemplaza el nombre por defecto que WooCommerce le pone
 * al apartado donde caen los campos personalizados en el checkout
 * ("Información adicional del pedido"). No es editable desde el admin a
 * propósito -- si quieres otro texto, cámbialo aquí directamente.
 */
define('WCON_SECTION_TITLE', 'Información de facturación');
define('WCON_FIELDS_OPTION_KEY', 'wcon_custom_fields');
define('WCON_FIELDS_NAMESPACE', 'BillMySales');
define('WCON_VERSION', '1.0.0');

/**
 * Practica estandar recomendada por WooCommerce para que los plugins
 * sean compatibles con HPOS. Si no se declara, WooCommerce muestra un
 * aviso de incompatibilidad.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Registra la página de configuración dentro del menú de WooCommerce.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'BillMySales',
        'BillMySales',
        'manage_woocommerce',
        'BillMySales',
        'wcon_render_settings_page'
    );
});

/**
 * Encola el JS del admin (antes iba como <script> inline en la pantalla
 * de ajustes); se movió a un archivo propio y se carga con
 * wp_enqueue_script(), que es el estándar de WordPress, solo en NUESTRA
 * página de ajustes.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'BillMySales') === false) {
        return;
    }

    wp_enqueue_script(
        'wcon-admin',
        plugins_url('assets/js/admin.js', __FILE__),
        [],
        WCON_VERSION,
        true
    );

    // El "próximo índice" para agregar filas de campos personalizados
    // depende de cuántas filas ya se están mostrando (ver
    // wcon_render_custom_fields_tab()), así que replicamos ese mismo
    // cálculo aquí para pasarlo al JS.
    $fields     = wcon_get_custom_fields();
    $next_index = empty($fields) ? 1 : count($fields);

    wp_localize_script('wcon-admin', 'WconAdmin', [
        'nextIndex' => $next_index,
        'showLabel' => __('Mostrar clave', 'billmysales'),
        'hideLabel' => __('Ocultar clave', 'billmysales'),
    ]);
});

/**
 * Dibuja la página de configuración en el admin, con pestañas para
 * separar "Configuración" (destino/webhook) de "Campos personalizados"
 * (datos extra del checkout). Se usan pestañas -- en vez de un submenú
 * anidado -- porque WordPress no soporta un tercer nivel de menú lateral;
 * las pestañas son la convención estándar que WooCommerce mismo usa en
 * sus propias pantallas de Ajustes.
 */
function wcon_render_settings_page()
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lee qué pestaña mostrar (UI), no procesa ni guarda ningún dato; el guardado real pasa por options.php con su propio nonce vía settings_fields().
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'configuracion';
    ?>
    <div class="wrap">
        <h1>BillMySales</h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=BillMySales&tab=configuracion" class="nav-tab <?php echo $tab === 'configuracion' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Configuración', 'billmysales'); ?>
            </a>
            <a href="?page=BillMySales&tab=campos" class="nav-tab <?php echo $tab === 'campos' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Campos personalizados', 'billmysales'); ?>
            </a>
        </nav>

        <div style="margin-top:20px;">
            <?php
            if ($tab === 'campos') {
                wcon_render_custom_fields_tab();
            } else {
                wcon_render_configuration_tab();
            }
    ?>
        </div>
    </div>
    <?php
}


// ============================================================
// BLOQUE 1: Destino (URL, secreto, estados que notifican)
// ============================================================
// Todo lo relacionado a la pestaña "Configuración": guardar el
// destino, leerlo, y dibujar su formulario.
// ============================================================

/**
 * Registra el "setting" del destino para que WordPress permita
 * guardarlo de forma segura (protección CSRF incluida vía nonce).
 */
add_action('admin_init', function () {
    register_setting('wcon_settings_group', WCON_OPTION_KEY, [
        'sanitize_callback' => 'wcon_sanitize_settings',
    ]);
});

/**
 * Devuelve la lista de estados validos de WooCommerce, SIN el prefijo "wc-".
 *
 * wc_get_order_statuses() devuelve claves con prefijo "wc-" (ej. "wc-cancelled"),
 * pero el hook woocommerce_order_status_changed entrega el estado sin ese
 * prefijo (ej. "cancelled"). Usamos esta misma forma "limpia" en todo el
 * plugin para no volver a mezclar los dos formatos.
 *
 * @return array Ej: ['pending' => 'Pendiente de pago', 'completed' => 'Completado', ...]
 */
function wcon_get_clean_statuses()
{
    $clean = [];
    foreach (wc_get_order_statuses() as $key => $label) {
        $clean[str_replace('wc-', '', $key)] = $label;
    }
    return $clean;
}

/**
 * Sanitiza y valida los datos del formulario antes de guardarlos.
 *
 * La URL y el secreto son OBLIGATORIOS: si falta alguno, se guarda una
 * configuración vacía en vez de datos a medio llenar, para que el admin
 * note claramente que falta completar algo. Se registra un
 * add_settings_error() explícito para distinguir "nunca hubo config" de
 * "había una config válida y se acaba de borrar".
 *
 * @param array $input Datos crudos del formulario ($_POST ya parseado por WP).
 * @return array Datos limpios a guardar en wp_options.
 */
function wcon_sanitize_settings($input)
{
    $defaults = ['url' => '', 'secret' => '', 'statuses' => [], 'active' => false];
    $previous = wcon_get_settings();

    if (empty($input['url']) || empty($input['secret'])) {
        if (!empty($previous['url']) || !empty($previous['secret'])) {
            add_settings_error(
                WCON_OPTION_KEY,
                'wcon_missing_required_reset',
                __('Faltó la URL y/o el secreto, así que se borró la configuración anterior (ambos campos son obligatorios). Vuelve a completarlos y guarda de nuevo.', 'billmysales'),
                'error'
            );
        } else {
            add_settings_error(
                WCON_OPTION_KEY,
                'wcon_missing_required',
                __('Debes completar la URL y el secreto para guardar la configuración.', 'billmysales'),
                'error'
            );
        }
        return $defaults; // Falta algo obligatorio, no se guarda destino valido.
    }

    // NOTA (fix v3.7.2): antes se usaba sanitize_url(), que es un alias de
    // esc_url_raw() introducido recién en WordPress 5.9. Este plugin
    // declara "Requires at least: 5.6", así que en un sitio con WordPress
    // 5.6, 5.7 u 8.5 esa llamada provoca un error fatal ("Call to
    // undefined function") apenas se intenta guardar la configuración --
    // la pantalla no muestra ningún cambio visible porque el guardado
    // nunca llega a completarse. esc_url_raw() hace exactamente lo mismo
    // y existe desde WordPress 2.8, así que es la forma correcta de
    // cubrir el rango completo de versiones soportadas.
    $url = esc_url_raw(trim($input['url']));

    // El secreto viaja en texto plano por la cabecera X-WCON-Secret, así
    // que si la URL no es HTTPS viaja sin cifrar por la red. No bloqueamos
    // el guardado (puede haber casos legítimos, como pruebas locales),
    // pero sí advertimos con claridad.
    if ($url !== '' && stripos($url, 'https://') !== 0) {
        add_settings_error(
            WCON_OPTION_KEY,
            'wcon_insecure_url',
            __('La URL no usa HTTPS: el secreto viajará sin cifrar por la red. Se recomienda usar una URL https:// para proteger el secreto.', 'billmysales'),
            'warning'
        );
    }

    $clean = [
        'url'      => $url,
        'secret'   => sanitize_text_field($input['secret']),
        'statuses' => [],
        // El checkbox "activo" no se manda en el POST si está desmarcado
        // (así funcionan los checkboxes HTML), por eso se verifica con !empty.
        'active'   => !empty($input['active']),
    ];

    if (!empty($input['statuses']) && is_array($input['statuses'])) {
        $valid_statuses = array_keys(wcon_get_clean_statuses());
        foreach ($input['statuses'] as $status) {
            $status = sanitize_key($status);
            if (in_array($status, $valid_statuses, true)) {
                $clean['statuses'][] = $status;
            }
        }
    }

    return $clean;
}

/**
 * Devuelve la configuración guardada, con valores por defecto seguros.
 * Migra automaticamente el formato antiguo (v2.x: lista de "destinations")
 * tomando solo el primero, ya que esta version solo soporta un destino.
 *
 * @return array ['url' => ..., 'secret' => ..., 'statuses' => [...], 'active' => bool]
 */
function wcon_get_settings()
{
    $raw = get_option(WCON_OPTION_KEY, []);

    // Migración desde v2.x (lista de destinos) -> v3.0 (un solo destino).
    if (empty($raw['url']) && !empty($raw['destinations'][0])) {
        $raw = $raw['destinations'][0];
    }

    return wp_parse_args($raw, [
        'url'      => '',
        'secret'   => '',
        'statuses' => [],
        'active'   => false,
    ]);
}

/**
 * Dibuja el contenido de la pestaña "Configuración" (URL, secreto, estados).
 */
function wcon_render_configuration_tab()
{
    $settings     = wcon_get_settings();
    $all_statuses = wcon_get_clean_statuses(); // ej: 'cancelled' => 'Cancelado'
    $is_active    = $settings['active'];
    ?>
    <?php settings_errors(); ?>
    <div class="wcon-field" style="border:1px solid #dcdcde;padding:16px;margin-bottom:16px;background:#fff;">
        <p>
            <?php esc_html_e('Configura la URL a la que avisar, en qué estados se debe notificar, y el secreto que BillMySales usará para validar el origen de cada notificación.', 'billmysales'); ?>
        </p>

        <?php if (empty($settings['url']) || empty($settings['secret'])) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Aún no hay una URL y/o secreto guardados. La integración no funcionará hasta completar ambos campos.', 'billmysales'); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('wcon_settings_group'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row" style="width:180px;"><?php esc_html_e('Estado', 'billmysales'); ?></th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(WCON_OPTION_KEY); ?>[active]"
                                value="1"
                                <?php checked($is_active); ?>
                            />
                            <?php esc_html_e('Activo', 'billmysales'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Si se desmarca, deja de notificarse sin borrar la configuración.', 'billmysales'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('URL de notificación*', 'billmysales'); ?></th>
                    <td>
                        <input
                            type="url"
                            name="<?php echo esc_attr(WCON_OPTION_KEY); ?>[url]"
                            value="<?php echo esc_attr($settings['url']); ?>"
                            class="regular-text"
                            placeholder="https://webhooks.billmysales.com/..."
                            required
                        />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Secreto*', 'billmysales'); ?></th>
                    <td>
                        <div style="display:flex;align-items:center;gap:4px;max-width:fit-content;">
                            <input
                                type="password"
                                id="wcon-secret-field"
                                name="<?php echo esc_attr(WCON_OPTION_KEY); ?>[secret]"
                                value="<?php echo esc_attr($settings['secret']); ?>"
                                class="regular-text"
                                required
                            />
                            <button
                                type="button"
                                class="button button-secondary wp-hide-pw hide-if-no-js"
                                data-toggle="0"
                                aria-label="<?php esc_attr_e('Mostrar clave', 'billmysales'); ?>"
                            >
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            </button>
                        </div>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: nombre de la cabecera HTTP */
                                esc_html__('Se envía en la cabecera %s para que BillMySales valide el origen. Es obligatorio: sin esto, la configuración no se guarda.', 'billmysales'),
                                '<code>X-WCON-Secret</code>'
                            );
    ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Estados que notifican*', 'billmysales'); ?></th>
                    <td>
                        <?php foreach ($all_statuses as $status_key => $status_label) :
                            $checked = in_array($status_key, $settings['statuses'], true);
                            ?>
                            <label style="display:inline-block;width:220px;margin-bottom:6px;">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(WCON_OPTION_KEY); ?>[statuses][]"
                                    value="<?php echo esc_attr($status_key); ?>"
                                    <?php checked($checked); ?>
                                />
                                <?php echo esc_html($status_label); ?>
                                <code><?php echo esc_html($status_key); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Guardar configuración', 'billmysales')); ?>
        </form>
    </div>
    <?php
}


// ============================================================
// BLOQUE 2: Campos personalizados del checkout
// ============================================================
// Todo lo relacionado a la pestaña "Campos personalizados": guardar
// la lista de campos, leerla, dibujar su formulario, e inyectarlos
// en el checkout real (por bloques) de WooCommerce.
// ============================================================

/**
 * Registra el "setting" de los campos personalizados.
 */
add_action('admin_init', function () {
    register_setting('wcon_fields_group', WCON_FIELDS_OPTION_KEY, [
        'sanitize_callback' => 'wcon_sanitize_custom_fields',
    ]);
});

/**
 * Genera una clave (meta_key) unica a partir de la etiqueta de un campo,
 * evitando choques con claves ya usadas por otros campos en la misma
 * pasada de guardado.
 *
 * @param string $label       Etiqueta escrita por el admin (ej. "RUT Cliente").
 * @param array  $used_keys   Claves ya asignadas en este guardado, para no repetir.
 * @return string Clave limpia y unica (ej. "rut_cliente", "rut_cliente_2").
 */
function wcon_generate_field_key($label, $used_keys)
{
    $base = sanitize_key(sanitize_title($label));
    if ($base === '') {
        $base = 'campo';
    }

    $key = $base;
    $i   = 2;
    while (in_array($key, $used_keys, true)) {
        $key = $base . '_' . $i;
        $i++;
    }

    return $key;
}

/**
 * Sanitiza y valida la lista completa de campos personalizados.
 *
 * Cada fila necesita al menos una etiqueta para conservarse; filas sin
 * etiqueta (ej. una fila vacia que quedo al agregar de mas) se descartan.
 *
 * IMPORTANTE: las claves (key) de TODOS los campos que llegan en el
 * formulario -- editados y nuevos -- se recolectan primero, antes de
 * generar ninguna clave nueva. Antes esto no pasaba: si un campo editado
 * conservaba una key que por casualidad coincidía con la key recién
 * generada para un campo nuevo en el mismo envío, terminaban dos campos
 * con la misma key (y luego WooCommerce Blocks intenta registrar dos
 * campos con el mismo id, lo que dispara una excepción). Ya no depende
 * de que las filas existentes vengan siempre antes que las nuevas en el HTML.
 *
 * @param array $input Datos crudos del formulario.
 * @return array Lista de campos limpios: [['key','label','values','required'], ...]
 */
function wcon_sanitize_custom_fields($input)
{
    $clean     = [];
    $used_keys = [];

    if (empty($input['fields']) || !is_array($input['fields'])) {
        return $clean;
    }

    foreach ($input['fields'] as $raw_field) {
        if (!empty($raw_field['key'])) {
            $used_keys[] = sanitize_key($raw_field['key']);
        }
    }

    foreach ($input['fields'] as $raw_field) {
        if (empty($raw_field['label'])) {
            continue; // Fila vacia, se descarta.
        }

        $label = sanitize_text_field($raw_field['label']);

        // Si el campo ya existia (edicion), reutilizamos su clave para no
        // "romper" el vinculo con datos ya guardados en ordenes anteriores.
        // Si es un campo nuevo, generamos una clave a partir de la etiqueta.
        $key = !empty($raw_field['key'])
            ? sanitize_key($raw_field['key'])
            : wcon_generate_field_key($label, $used_keys);

        $used_keys[] = $key;

        // Valores separados por coma -> select. Vacio -> texto libre.
        $values_raw = isset($raw_field['values']) ? trim($raw_field['values']) : '';
        $values     = [];
        if ($values_raw !== '') {
            foreach (explode(',', $values_raw) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = sanitize_text_field($value);
                }
            }
        }

        $clean[] = [
            'key'      => $key,
            'label'    => $label,
            'values'   => $values, // Array vacio = campo de texto libre.
            'required' => !empty($raw_field['required']),
        ];
    }

    return $clean;
}

/**
 * Devuelve la lista de campos personalizados guardados.
 *
 * @return array
 */
function wcon_get_custom_fields()
{
    $fields = get_option(WCON_FIELDS_OPTION_KEY, []);
    return is_array($fields) ? $fields : [];
}

/**
 * Dibuja el contenido de la pestaña "Campos personalizados": una lista
 * dinamica de campos que el admin puede agregar/editar/eliminar, cada uno
 * con etiqueta, valores separados por coma (si tiene, se muestra como
 * selector; si no, como texto libre), y si es obligatorio en el checkout.
 * También incluye el campo para personalizar el título de la sección.
 */
function wcon_render_custom_fields_tab()
{
    $fields = wcon_get_custom_fields();

    // Si no hay ningun campo guardado todavia, mostramos una fila vacia
    // para que el formulario no aparezca en blanco sin nada que llenar.
    if (empty($fields)) {
        $fields = [
            ['key' => '', 'label' => '', 'values' => [], 'required' => false],
        ];
    }
    ?>
    <?php settings_errors(); ?>
    <p>
        <?php esc_html_e('Estos campos se agregan al formulario de checkout, en su propio apartado, y se incluyen en la notificación que se envía a BillMySales.', 'billmysales'); ?>
    </p>

    <form method="post" action="options.php">
        <?php settings_fields('wcon_fields_group'); ?>

        <div id="wcon-fields">
            <?php foreach ($fields as $index => $field) :
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wcon_render_custom_field_row() ya escapa cada valor internamente (esc_attr/esc_html) antes de devolver el HTML.
                echo wcon_render_custom_field_row($index, $field);
            endforeach; ?>
        </div>

        <p>
            <button type="button" class="button" id="wcon-add-field">
                <?php esc_html_e('+ Agregar otro campo', 'billmysales'); ?>
            </button>
        </p>

        <?php submit_button(__('Guardar campos', 'billmysales')); ?>
    </form>

    <!-- Plantilla oculta que JS clona para agregar filas nuevas. -->
    <template id="wcon-field-template">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- misma función que arriba, ya escapa internamente.
        echo wcon_render_custom_field_row('__INDEX__', ['key' => '', 'label' => '', 'values' => [], 'required' => false]);
    ?>
    </template>
    <?php
}

/**
 * Dibuja el bloque HTML de UN campo personalizado (etiqueta + valores +
 * obligatorio). Se reutiliza tanto para campos ya guardados como para la
 * plantilla vacia que usa JavaScript al agregar filas nuevas.
 *
 * @param int|string $index Índice numérico, o "__INDEX__" en la plantilla.
 * @param array      $field ['key' => ..., 'label' => ..., 'values' => [...], 'required' => bool]
 * @return string HTML del bloque.
 */
function wcon_render_custom_field_row($index, $field)
{
    $values_text = implode(', ', $field['values']);
    ob_start();
    ?>
    <div class="wcon-field" style="border:1px solid #dcdcde;padding:16px;margin-bottom:16px;background:#fff;">
        <!-- La clave se mantiene oculta y fija una vez creado el campo, para
             no perder el vinculo con datos ya guardados en ordenes anteriores
             si el admin solo le cambia el nombre visible (label) despues. -->
        <input type="hidden" name="<?php echo esc_attr(WCON_FIELDS_OPTION_KEY); ?>[fields][<?php echo esc_attr($index); ?>][key]" value="<?php echo esc_attr($field['key']); ?>" />

        <table class="form-table" role="presentation" style="margin:0;">
            <tr>
                <th scope="row" style="width:180px;"><?php esc_html_e('Etiqueta*', 'billmysales'); ?></th>
                <td>
                    <input
                        type="text"
                        name="<?php echo esc_attr(WCON_FIELDS_OPTION_KEY); ?>[fields][<?php echo esc_attr($index); ?>][label]"
                        value="<?php echo esc_attr($field['label']); ?>"
                        class="regular-text"
                        placeholder="<?php esc_attr_e('Ej: RUT, Razón social, Giro comercial', 'billmysales'); ?>"
                    />
                    <?php if (!empty($field['key'])) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: clave interna del campo */
                                esc_html__('Clave interna: %s (no cambia aunque edites la etiqueta)', 'billmysales'),
                                '<code>' . esc_html($field['key']) . '</code>'
                            );
                        ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Valores (opcional)', 'billmysales'); ?></th>
                <td>
                    <input
                        type="text"
                        name="<?php echo esc_attr(WCON_FIELDS_OPTION_KEY); ?>[fields][<?php echo esc_attr($index); ?>][values]"
                        value="<?php echo esc_attr($values_text); ?>"
                        class="regular-text"
                        placeholder="<?php esc_attr_e('Ej: Persona natural, Persona jurídica', 'billmysales'); ?>"
                    />
                    <p class="description">
                        <?php esc_html_e('Si escribes valores separados por coma, el campo se mostrará como un selector con esas opciones. Si lo dejas vacío, será un campo de texto libre.', 'billmysales'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Obligatorio', 'billmysales'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr(WCON_FIELDS_OPTION_KEY); ?>[fields][<?php echo esc_attr($index); ?>][required]"
                            value="1"
                            <?php checked(!empty($field['required'])); ?>
                        />
                        <?php esc_html_e('El cliente debe completarlo para poder pagar', 'billmysales'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p style="margin-top:12px;">
            <button type="button" class="button-link-delete wcon-remove-field">
                <?php esc_html_e('Eliminar este campo', 'billmysales'); ?>
            </button>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Inyecta los campos personalizados configurados en el checkout NUEVO POR
 * BLOQUES de WooCommerce (Cart & Checkout Blocks) usando la API oficial de
 * "Additional Checkout Fields".
 *
 * IMPORTANTE: esta API es distinta a la del checkout clasico. El filtro
 * "woocommerce_billing_fields" (que usa el checkout clasico) NO tiene
 * ningun efecto aqui -- el sitio usa el checkout por bloques (secciones
 * "Información de contacto", "Dirección de facturación", "Opciones de
 * pago"), por eso se usa este metodo.
 *
 * Ubicacion "order": WooCommerce Blocks solo permite 3 ubicaciones para
 * campos personalizados: "contact" (info de contacto), "address"
 * (direccion de facturacion/envio) y "order" (nivel orden, en su PROPIO
 * apartado separado, normalmente cerca de las notas del pedido, al final
 * del checkout). Se usa "order" porque se pidio que los campos queden en
 * un apartado distinto al final, no mezclados con los datos de direccion.
 *
 * CONFIRMADO CON UNA ORDEN DE PRUEBA REAL a través del checkout,
 * completando los campos personalizados: WooCommerce guarda el valor en
 * la tabla de meta de la orden con la clave "_wc_other/{namespace}/{key}"
 * (ver wcon_append_custom_fields_to_payload() en el Bloque 3).
 *
 * Cada registro va envuelto en try/catch: woocommerce_register_additional_
 * checkout_field() lanza una excepción si el id está mal formado, el
 * namespace no es válido, o ya existe un campo con ese id. Sin este
 * try/catch, una configuración inválida (ej. dos campos con la misma key
 * por una edición concurrente) tira una excepción sin capturar en
 * woocommerce_init -- es decir, rompe el checkout para TODOS los
 * clientes, no solo para el admin. Ahora el error queda en el log de
 * WooCommerce y el resto de los campos se siguen registrando con
 * normalidad.
 */
add_action('woocommerce_init', function () {
    if (!function_exists('woocommerce_register_additional_checkout_field')) {
        // La version de WooCommerce instalada no soporta esta API todavia
        // (se introdujo en WooCommerce 8.9). En ese caso no hay nada que
        // registrar -- se necesitaria actualizar WooCommerce, o volver al
        // filtro del checkout clasico si el sitio usa ese checkout.
        return;
    }

    foreach (wcon_get_custom_fields() as $custom_field) {
        $field_definition = [
            'id'       => WCON_FIELDS_NAMESPACE . '/' . $custom_field['key'],
            'label'    => $custom_field['label'],
            'location' => 'order',
            'required' => $custom_field['required'],
        ];

        if (!empty($custom_field['values'])) {
            $field_definition['type']    = 'select';
            $field_definition['options'] = array_map(function ($value) {
                return ['value' => $value, 'label' => $value];
            }, $custom_field['values']);
        } else {
            $field_definition['type'] = 'text';
        }

        try {
            woocommerce_register_additional_checkout_field($field_definition);
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    sprintf(
                        'BillMySales: error registrando el campo "%1$s" (id "%2$s"): %3$s',
                        $custom_field['key'],
                        $field_definition['id'],
                        $e->getMessage()
                    ),
                    ['source' => 'BillMySales']
                );
            }
        }
    }
});

/**
 * Reemplaza el título por defecto del bloque "Información adicional del
 * pedido" (Order Information) del checkout por bloques por el título fijo
 * definido en WCON_SECTION_TITLE. No es configurable desde el admin a
 * propósito: el nombre de este apartado es parte del plugin, no algo que
 * el usuario/admin de la tienda deba tocar.
 *
 * WooCommerce Blocks NO ofrece hoy un filtro PHP oficial para cambiar ese
 * título -- el bloque solo se puede mover de posición desde el editor,
 * no renombrar (confirmado en los foros de soporte de WooCommerce). La
 * única forma de cambiarlo es interceptando la traducción del string en
 * JavaScript, con el filtro estándar de WordPress "i18n.gettext" (wp.hooks),
 * disponible desde WP 5.7.
 *
 * Este filtro se engancha como script inline "after" del handle
 * 'wp-hooks' -- un script del core del que dependen TODOS los bundles de
 * bloques de WooCommerce -- para garantizar que nuestro filtro quede
 * registrado antes de que el checkout intente traducir el título,
 * en vez de depender del nombre exacto del handle del bundle del
 * checkout (que puede cambiar entre versiones de WooCommerce).
 *
 * Nota: el texto "Additional order information" es el string ORIGINAL en
 * inglés que usa WooCommerce Blocks internamente (el $text que le llega al
 * filtro de traducción es siempre el original, sin importar el idioma del
 * sitio). Como red de seguridad adicional también se compara contra el
 * texto ya traducido al español actual del plugin de WooCommerce
 * ("Información adicional del pedido"), por si esa cadena de origen
 * cambiara en una futura versión. Si WooCommerce llegara a cambiar el
 * texto y esto dejara de funcionar, revisa el título real en el HTML del
 * checkout y actualiza las dos cadenas de comparación en
 * wcon_build_title_override_script().
 */
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // El bloque "Información adicional del pedido" no se renderiza (ni
    // tiene título que mostrar) si no hay ningún campo personalizado
    // registrado en la ubicación "order".
    if (empty(wcon_get_custom_fields())) {
        return;
    }

    if (!wp_script_is('wp-hooks', 'registered') && !wp_script_is('wp-hooks', 'enqueued')) {
        return;
    }

    wp_add_inline_script('wp-hooks', wcon_build_title_override_script(WCON_SECTION_TITLE), 'after');
}, 20);

/**
 * Arma el pequeño script inline que registra el filtro "i18n.gettext"
 * para reemplazar el título de la sección de campos adicionales.
 *
 * @param string $custom_title Título fijo (WCON_SECTION_TITLE).
 * @return string JS listo para wp_add_inline_script().
 */
function wcon_build_title_override_script($custom_title)
{
    $custom_json      = wp_json_encode($custom_title);
    $default_en_json  = wp_json_encode('Additional order information');
    $default_es_json  = wp_json_encode('Información adicional del pedido');

    return '(function(){'
        . "if(typeof wp==='undefined'||!wp.hooks||typeof wp.hooks.addFilter!=='function'){return;}"
        . "wp.hooks.addFilter('i18n.gettext','BillMySales/override-order-info-title',function(translation,text){"
        . 'if(text===' . $default_en_json . '||translation===' . $default_es_json . '){'
        . 'return ' . $custom_json . ';'
        . '}'
        . 'return translation;'
        . '});'
        . '})();';
}


// ============================================================
// BLOQUE 3: Envío de la notificación
// ============================================================
// Todo lo relacionado a construir el mensaje y mandarlo al destino
// cuando corresponde: armar el payload, incluir los campos
// personalizados, enviar el POST, y los hooks que disparan todo esto.
// ============================================================

/**
 * Campos del payload REST de WooCommerce que BillMySales efectivamente lee
 * para su datasource "woocommerce" (confirmado revisando el parser real que
 * usa BillMySales, WoocommerceAppInvoiceParser: schema de validación +
 * método _parse()). Todo lo demás que trae el payload REST completo
 * (_links, refunds, version, cart_hash, order_key, tax_lines, meta_data a
 * nivel de orden, etc.) ese parser lo ignora, así que no se envía.
 *
 * @var string[]
 */
const WCON_ORDER_PAYLOAD_FIELDS = [
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
 * Construye el payload de la orden con SOLO los campos que
 * WoocommerceAppInvoiceParser (el parser de BillMySales para el datasource
 * "woocommerce") efectivamente lee -- ver WCON_ORDER_PAYLOAD_FIELDS.
 *
 * Se arma haciendo una petición REST interna real con rest_do_request() al
 * mismo endpoint publico que expone WooCommerce (GET /wc/v3/orders/{id}),
 * en vez de instanciar WC_REST_Orders_Controller y llamar directo a uno de
 * sus metodos internos. Es el mismo patron que usa el sistema NATIVO de
 * Webhooks de WooCommerce (WC_Webhook::build_payload(), a traves de
 * RestApiUtil::get_endpoint_data()) -- pasar por rest_do_request() es la
 * forma oficial y estable de reusar la logica de un controller REST sin
 * depender del nombre exacto de sus metodos internos. Esos SI cambian sin
 * aviso entre versiones de WooCommerce: este mismo codigo antes llamaba a
 * "prepare_item_for_response()", que WooCommerce dejo de sobreescribir en
 * sus controllers CRUD (lo reemplazaron por "prepare_object_for_response()")
 * sin ningun aviso de deprecacion, y eso rompio el envio de notificaciones
 * por completo.
 *
 * El endpoint de ordenes exige permisos (ver pedidos privados), que no
 * existen cuando este hook corre sin usuario logueado -- un checkout de un
 * cliente invitado, por ejemplo. En vez de suplantar al usuario actual
 * (como hace WC_Webhook::build_payload() con wp_set_current_user(), lo que
 * volveria "administrador" a TODO el request mientras dura la llamada,
 * afectando cualquier otro chequeo de permisos que corra en el medio), se
 * engancha el filtro puntual "woocommerce_rest_check_permissions" -- el
 * mismo que usa internamente wc_rest_check_post_permissions() -- para
 * autorizar la lectura de esta orden especifica unicamente. La identidad
 * del usuario actual nunca cambia.
 *
 * @param WC_Order $order
 * @return array
 */
function wcon_build_payload($order)
{
    $fallback = [
        'id'           => $order->get_id(),
        'number'       => $order->get_order_number(),
        'status'       => $order->get_status(),
        'total'        => $order->get_total(),
        'date_created' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
    ];

    if (!function_exists('rest_do_request')) {
        // Fallback minimo si la REST API de WordPress no esta disponible por algun motivo.
        return $fallback;
    }

    $order_id = $order->get_id();

    $grant_permission = function ($permission, $context, $object_id, $post_type) use ($order_id) {
        if ('shop_order' === $post_type && 'read' === $context && (int) $object_id === $order_id) {
            return true;
        }

        return $permission;
    };

    add_filter('woocommerce_rest_check_permissions', $grant_permission, 10, 4);

    try {
        $request  = new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id);
        $response = rest_do_request($request);

        if ($response->is_error()) {
            if (function_exists('wc_get_logger')) {
                $error_data = $response->get_data();
                wc_get_logger()->error(
                    sprintf(
                        'BillMySales: no se pudo armar el payload REST de la orden #%d: %s',
                        $order_id,
                        $error_data['message'] ?? 'error desconocido'
                    ),
                    ['source' => 'BillMySales']
                );
            }
            return $fallback;
        }

        $full_payload = rest_get_server()->response_to_data($response, false);
    } finally {
        remove_filter('woocommerce_rest_check_permissions', $grant_permission, 10);
    }

    return array_intersect_key($full_payload, array_flip(WCON_ORDER_PAYLOAD_FIELDS));
}

/**
 * Agrega los campos personalizados DENTRO del array "meta_data" del
 * payload, con el mismo formato {id,key,value} que usa WooCommerce para
 * el resto de sus metadatos nativos (ver ejemplo real: "amount",
 * "authorizationCode", etc. en el payload que ya confirmamos que
 * BillMySales acepta).
 *
 * Los campos personalizados de este plugin se guardan como metadato
 * PRIVADO de la orden (prefijo "_wc_other/..."), y la API REST nativa de
 * WooCommerce excluye por defecto los metadatos que empiezan con "_" --
 * por eso no aparecen solos en wcon_build_payload(). Se agregan aca a
 * mano, con la clave "limpia" (sin el prefijo interno), para que viajen
 * dentro de "meta_data" igual que cualquier otro metadato visible.
 *
 * @param array    $payload
 * @param WC_Order $order
 * @return array
 */
function wcon_append_custom_fields_to_payload($payload, $order)
{
    $custom_fields = wcon_get_custom_fields();
    if (empty($custom_fields)) {
        return $payload;
    }

    if (!isset($payload['meta_data']) || !is_array($payload['meta_data'])) {
        $payload['meta_data'] = [];
    }

    foreach ($custom_fields as $custom_field) {
        $meta_key = '_wc_other/' . WCON_FIELDS_NAMESPACE . '/' . $custom_field['key'];
        $value    = $order->get_meta($meta_key);

        $payload['meta_data'][] = [
            'id'    => 0, // No es un meta_id real de wp_postmeta, solo mantiene el mismo shape.
            'key'   => $custom_field['key'],
            'value' => $value,
        ];
    }

    return $payload;
}

/**
 * Envía el POST al destino configurado.
 *
 * @param array $settings ['url' => ..., 'secret' => ...]
 * @param array $payload
 * @return array|WP_Error Respuesta de wp_remote_post().
 */
function wcon_notify($settings, $payload)
{
    $body = wp_json_encode($payload);

    $signature = base64_encode(
        hash_hmac('sha256', $body, $settings['secret'], true)
    );

    $headers = [
        'Content-Type'            => 'application/json',
        'X-WCON-Secret'           => $settings['secret'],
        'X-WC-Webhook-Signature'  => $signature,
    ];

    return wp_remote_post($settings['url'], [
        'timeout' => 15,
        'headers' => $headers,
        'body'    => $body,
    ]);
}

/**
 * Notifica al destino configurado si esta ACTIVO y el estado indicado
 * está en su lista. Se usa tanto para el hook de "cambio de estado" como
 * para el de "orden creada" (ver mas abajo por que se necesitan los dos).
 * Incluye proteccion contra duplicados dentro de la misma peticion HTTP.
 *
 * @param int      $order_id
 * @param string   $status  Estado actual de la orden (limpio, sin prefijo wc-).
 * @param WC_Order $order
 */
function wcon_notify_if_applicable($order_id, $status, $order)
{
    static $already_notified = [];
    $dedup_key = $order_id . ':' . $status;

    if (isset($already_notified[$dedup_key])) {
        return;
    }
    $already_notified[$dedup_key] = true;

    $settings = wcon_get_settings();

    if (empty($settings['active'])) {
        return;
    }

    if (empty($settings['url']) || empty($settings['secret'])) {
        return;
    }

    if (!in_array($status, $settings['statuses'], true)) {
        return;
    }

    $payload = wcon_build_payload($order);
    $payload = wcon_append_custom_fields_to_payload($payload, $order);

    $response = wcon_notify($settings, $payload);

    if (!function_exists('wc_get_logger')) {
        return;
    }

    $logger  = wc_get_logger();
    $context = ['source' => 'BillMySales'];

    if (is_wp_error($response)) {
        $logger->error(
            sprintf('Orden #%d: fallo al notificar a %s. Error: %s', $order_id, $settings['url'], $response->get_error_message()),
            $context
        );
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $logger->info(
            sprintf('Orden #%d: notificada a %s. Código de respuesta: %s.', $order_id, $settings['url'], $code),
            $context
        );
    }
}

/**
 * Hook 1: se dispara cuando WooCommerce CAMBIA el estado de una orden que
 * ya existía (ej: de "pending" a "completed"). No cubre el caso en que una
 * orden nace directamente en un estado (ver Hook 2).
 */
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) {
    wcon_notify_if_applicable($order_id, $new_status, $order);
}, 10, 4);

/**
 * Hook 2: se dispara cuando una orden se CREA por primera vez (ej: vía API
 * o checkout). Necesario porque una orden que nace directamente en
 * "pending" nunca dispara "woocommerce_order_status_changed" — no hubo
 * transición desde ningún estado anterior, simplemente nació así.
 */
add_action('woocommerce_new_order', function ($order_id, $order = null) {
    if ($order === null) {
        $order = wc_get_order($order_id);
    }
    if (!$order) {
        return;
    }
    wcon_notify_if_applicable($order_id, $order->get_status(), $order);
}, 10, 2);


// ============================================================
// BLOQUE 4: Link "Ver detalles" en la lista de plugins
// ============================================================
// BillMySales no está publicado en WordPress.org, así que WordPress no
// tiene de dónde sacar la info para el popup de "Ver detalles" que sí
// aparece en plugins públicos (Akismet, Hello Dolly, etc.), que la
// obtienen consultando la API de wordpress.org.
//
// Para tener ese mismo link y popup en un plugin privado, hay que
// hacerlo manualmente en dos partes:
//   1) Agregar el link "Ver detalles" en la fila del plugin
//      (plugin_row_meta).
//   2) Interceptar la petición de esos detalles ANTES de que WordPress
//      intente consultar wordpress.org, y devolver nuestra propia
//      descripción (plugins_api).
// ============================================================

/**
 * Identificador interno usado en el link y en el filtro plugins_api.
 * No tiene que coincidir con un slug real de wordpress.org -- es solo
 * una clave arbitraria para saber "esta petición de detalles es sobre
 * BillMySales".
 */
define('WCON_DETAILS_SLUG', 'BillMySales');

/**
 * Agrega el link "Ver detalles" en la fila del plugin, dentro de la
 * pantalla Plugins > Instalados. Usa la clase "thickbox", que WordPress
 * ya carga automáticamente en esa pantalla (no hace falta encolar nada
 * adicional).
 *
 * @param array  $links Links ya existentes en la fila (ej. "Ver sitio del plugin").
 * @param string $file  Ruta relativa del archivo principal del plugin que se está dibujando.
 * @return array
 */
add_filter('plugin_row_meta', function ($links, $file) {
    if (plugin_basename(__FILE__) !== $file) {
        return $links;
    }

    $details_url = self_admin_url(
        'plugin-install.php?tab=plugin-information&plugin=' . WCON_DETAILS_SLUG
        . '&TB_iframe=true&width=600&height=550'
    );

    $links[] = sprintf(
        '<a href="%1$s" class="thickbox open-plugin-details-modal" aria-label="%2$s">%3$s</a>',
        esc_url($details_url),
        esc_attr__('Más información sobre BillMySales', 'billmysales'),
        esc_html__('Ver detalles', 'billmysales')
    );

    return $links;
}, 10, 2);

/**
 * Responde la petición de detalles del popup, en vez de dejar que
 * WordPress intente (y falle) consultar la API de wordpress.org para un
 * plugin que no está publicado ahí.
 *
 * Las secciones del array 'sections' son las que se muestran como
 * pestañas dentro del popup (Descripción, Instalación, Registro de
 * cambios, etc.) -- exactamente el mismo formato que usa un readme.txt
 * de wordpress.org, así que si más adelante creas un readme.txt real
 * para este plugin, puedes reutilizar ese mismo contenido aquí.
 *
 * @param false|object|array $result Resultado por defecto (false = "seguir buscando").
 * @param string             $action Acción solicitada por WordPress.
 * @param object             $args   Argumentos de la petición, incluye 'slug'.
 * @return false|object
 */
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== WCON_DETAILS_SLUG) {
        return $result;
    }

    return (object) [
        'name'          => 'BillMySales',
        'slug'          => WCON_DETAILS_SLUG,
        'version'       => WCON_VERSION,
        'author'        => '<a href="https://derafu.org">Derafu</a>',
        'author_profile' => 'https://derafu.org',
        'requires'      => '5.6',
        'tested'        => '7.1',
        'requires_php'  => '7.4',
        'last_updated'  => gmdate('Y-m-d H:i:s', filemtime(__FILE__)),
        'homepage'      => 'https://derafu.org',
        'sections'      => [
            'description' => wpautop(
                'Notifica a una URL configurable (webhook propio) cuando una orden '
                . 'de WooCommerce llega a alguno de los estados seleccionados, e '
                . 'incluye campos personalizados configurables agregados al checkout '
                . 'por bloques (plantilla estándar). El destino tiene su propio '
                . 'secreto (obligatorio) y puede activarse/desactivarse sin borrar su '
                . 'configuración.<br><br>'
                . 'Este plugin envía datos del pedido (nombre, apellido, email, '
                . 'teléfono, montos y campos personalizados) a la URL configurada por '
                . 'el propio administrador de la tienda.'
            ),
            'changelog'   => wpautop(
                '<strong>1.0.0</strong><br>'
                . '- Versión inicial de esta línea.'
            ),
        ],
        // Puedes agregar 'banners' => ['low' => 'URL_IMAGEN'] si quieres
        // una imagen de cabecera en el popup, similar a los plugins
        // públicos.
    ];
}, 10, 3);
