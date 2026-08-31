=== BillMySales ===
Contributors: derafu
Tags: woocommerce, webhook, checkout, custom fields, billing
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.9
WC tested up to: 10.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Notifies your own webhook when a WooCommerce order changes status, including custom checkout block fields.

== Description ==

BillMySales sends an HTTP POST notification to a configurable URL (your own billing service or another external system) whenever a WooCommerce order reaches one of the statuses you select.

**Important — privacy and data sent to third parties:** this plugin sends order data to the URL that you, the store administrator, configure yourself. The data included follows the same structure as WooCommerce's own REST API order response: order ID, number and status, currency and totals (including taxes, shipping and discounts), creation/payment/completion dates, billing and shipping address (name, company, address, email, phone), payment method, the purchased line items, shipping and fee lines, applied coupons, and the values of any custom fields you have defined. No data is sent to Derafu or to any third party not explicitly configured by you; the destination and the authentication secret are the store administrator's own responsibility and configuration.

= Features =

* Configure a destination URL and a secret (sent in the `X-WCON-Secret` header) to validate the origin of each notification.
* Each notification is also signed with HMAC-SHA256 (sent in the `X-WC-Webhook-Signature` header) so the destination can verify payload integrity.
* Choose which order statuses should trigger a notification.
* Enable or disable the integration without losing the saved configuration.
* Add custom fields to the WooCommerce Cart & Checkout Blocks, in their own section.
* Each custom field can be free text or a select (if you define a comma-separated list of values), and can be marked as required.
* Custom field values are automatically included in the outgoing notification.
* Compatible with WooCommerce HPOS (High-Performance Order Storage).

= Requirements =

* WooCommerce 8.9 or later (the "Additional Checkout Fields" API used by the block-based checkout was introduced in that version).
* The store must use the WooCommerce block-based checkout (Cart & Checkout Blocks). The classic checkout is not compatible with this plugin's custom fields feature.

== Installation ==

1. Sube la carpeta `bill-my-sales` a `/wp-content/plugins/`, o instala el plugin desde el repositorio de WordPress.org.
2. Activa el plugin desde el menú "Plugins" de WordPress.
3. Ve a WooCommerce → BillMySales.
4. En la pestaña "Configuración", completa la URL de notificación y el secreto (ambos obligatorios), selecciona los estados que deben notificar, y marca "Activo".
5. Opcionalmente, en la pestaña "Campos del checkout", agrega los campos que quieras incluir en el checkout.

== Frequently Asked Questions ==

= ¿Funciona con el checkout clásico (shortcode)? =

No. Los campos personalizados usan la API de "Additional Checkout Fields" de WooCommerce Blocks, exclusiva del checkout por bloques. La notificación por webhook sí funciona independientemente del tipo de checkout, ya que se basa en los cambios de estado de la orden.

= ¿Dónde se guarda el secreto? =

En la opción `wcon_settings` de la base de datos de WordPress (`wp_options`), y se envía en cada notificación en la cabecera HTTP `X-WCON-Secret`. El mismo secreto también se usa para firmar el cuerpo de cada notificación con HMAC-SHA256, enviado en la cabecera `X-WC-Webhook-Signature`, para que el destino pueda validar que el payload no fue alterado. Se recomienda usar una URL de destino con HTTPS para que el secreto viaje cifrado.

= ¿Qué pasa si desactivo o desinstalo el plugin? =

Al desactivarlo, la configuración se conserva. Al desinstalarlo (eliminarlo desde el admin), se borran las opciones guardadas (`wcon_settings` y `wcon_custom_fields`).

== Changelog ==
= 1.0.0 =
* Primera versión: notificación por webhook configurable según estado de la orden, con campos personalizados en el checkout por bloques.

== Upgrade Notice ==
