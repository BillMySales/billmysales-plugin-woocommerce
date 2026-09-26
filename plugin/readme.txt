=== BillMySales ===
Contributors: billmysales
Tags: woocommerce, billing, invoices, webhook, checkout fields
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.9
WC tested up to: 11.1
Stable tag: 2.0.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Sends WooCommerce orders to BillMySales when they reach the selected statuses, and adds custom fields to the checkout.

== Description ==

[BillMySales](https://www.billmysales.com) connects your store with your billing system. This plugin sends each order to BillMySales when it reaches one of the statuses you select, and BillMySales issues the corresponding document.

* Orders are sent in the background (WooCommerce's Action Scheduler), so the checkout never waits for BillMySales; failed deliveries are retried.
* Each notification is signed with HMAC-SHA256 using the secret shared with BillMySales.
* Each attempt is recorded in the order's notes; "Send to BillMySales" in the order actions sends it again.
* Custom fields for the checkout, block or classic (e.g. RUT, business activity, receipt or invoice), free text or a list of values, optional or required; their values are sent with the order.
* Compatible with HPOS (High-Performance Order Storage) and the block-based cart and checkout.

**Data sent:** the order as WooCommerce's REST API represents it (number, status, dates, totals and taxes, billing and shipping addresses, customer email and phone, payment method, line items, shipping, fees and coupons) plus the custom fields, only to the URL you configure.

== Installation ==

1. Download `billmysales-woocommerce-<version>.zip` from the [releases](https://github.com/BillMySales/billmysales-plugin-woocommerce/releases).
2. In WordPress, go to Plugins > Add New > Upload Plugin, upload the zip and activate it.
3. Go to WooCommerce > BillMySales, enter the notification URL and the secret given by BillMySales, select the statuses that notify and check "Active".
4. Optionally, add checkout fields in the "Checkout fields" tab.

== Frequently Asked Questions ==

= Does it work with the classic (shortcode) checkout? =

Yes. The custom fields are shown in the block checkout in their own section and in the classic checkout in the billing section, and are sent the same way.

= Why can't "Pending payment" and "On hold" be selected? =

Orders in those statuses aren't ready to bill: a pending order awaits its payment, and an on-hold order awaits the confirmation of the payment (e.g. a bank transfer not received yet).

= Where do I see the deliveries? =

In each order's notes (one private note per attempt: sent, retry, rejected). After a rejection, fix the cause and choose "Send to BillMySales" in the order actions. The details are also in WooCommerce > Status > Logs (source "billmysales", deleted after WooCommerce's log retention period), and the queued jobs in Tools > Scheduled Actions (group "billmysales_deliver").

= What happens when I deactivate or delete the plugin? =

Deactivating keeps the settings. Deleting the plugin removes its settings and pending deliveries.

== Changelog ==

= 2.0.0 =
* Rewritten: orders sent in the background with retries, fixed: 1.0.0 also sent the secret in a header, standard BillMySales headers, deliveries recorded in the order notes, "Send to BillMySales" order action, English source strings with Spanish translations.

= 1.0.0 =
* First version: webhook on the selected order statuses, block checkout fields.
