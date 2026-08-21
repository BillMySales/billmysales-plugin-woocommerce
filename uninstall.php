<?php

/**
 * Se ejecuta cuando el usuario elimina el plugin desde el admin de
 * WordPress (no al desactivarlo, solo al borrarlo). WordPress detecta
 * este archivo automáticamente por su nombre y ubicación en la raíz del
 * plugin -- no requiere register_uninstall_hook().
 *
 * Limpia las dos opciones que el plugin guarda en wp_options para no
 * dejar datos huérfanos en la base de datos.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wcon_settings');
delete_option('wcon_custom_fields');

// Por si el sitio es un multisitio y el plugin se desinstala a nivel de
// red, se limpia también en cada subsitio.
if (is_multisite()) {
    $wcon_site_ids = get_sites(['fields' => 'ids']);
    foreach ($wcon_site_ids as $wcon_site_id) {
        switch_to_blog($wcon_site_id);
        delete_option('wcon_settings');
        delete_option('wcon_custom_fields');
        restore_current_blog();
    }
}
