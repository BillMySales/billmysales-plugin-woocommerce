BillMySales for WooCommerce
===========================

WordPress plugin that sends WooCommerce orders to
[BillMySales](https://www.billmysales.com) when they reach the selected
statuses (BillMySales then issues the billing document), and adds custom
fields to the checkout, block or classic (e.g. RUT, business activity,
receipt or invoice).

- Orders are sent in the background with WooCommerce's Action Scheduler: the
  checkout and the admin never wait for BillMySales. Failed deliveries are
  retried (network errors, timeouts, HTTP 408, 429 and 5xx: after 1 min,
  5 min, 30 min, 2 h and 12 h); other HTTP errors are logged, not retried.
  BillMySales is idempotent, so a repeated delivery is harmless.
- Each notification is signed with HMAC-SHA256 with the secret shared with
  BillMySales.
- Each attempt is recorded as a private note in the order (sent, retry,
  rejected), and "Send to BillMySales" in the order actions sends an order
  again.
- The custom fields work in both checkouts: the block checkout (their own
  "Billing information" section) and the classic (shortcode) one (in the
  billing section). Both store them the same way, so the payload is the
  same.
- Compatible with HPOS and the block-based cart and checkout.

Requirements: WordPress 6.5+, WooCommerce 8.9+ (tested up to 11.1), PHP 7.4+.

Installation
------------

1. Download `billmysales-woocommerce-<version>.zip` from the
   [releases](https://github.com/BillMySales/billmysales-plugin-woocommerce/releases)
   (not the repository's own zip).
2. WordPress admin > Plugins > Add New > Upload Plugin: upload it and
   activate it.
3. WooCommerce > BillMySales > Settings: the notification URL and the secret
   given by BillMySales, the statuses that notify, and "Active".
4. Optionally, WooCommerce > BillMySales > Checkout fields: each field has a
   label (its key is derived from it), optional comma-separated values (then
   it's a list) and whether it's required. They are shown in their own
   checkout section, "Billing information".

"Pending payment" and "On hold" can't be selected, nor sent from the order
actions: orders in them aren't ready to bill. A pending order awaits its
payment (it may never be paid); an on-hold order awaits the merchant's
confirmation of the payment (e.g. a bank transfer not received yet). Bill
when the payment is confirmed ("Processing", "Completed").

Where to see the deliveries:

- **The order** (WooCommerce > Orders > the order > notes): one private
  note per attempt, e.g. "BillMySales: sent (HTTP 200).", "BillMySales: not
  sent (HTTP 503), retry 1 in 1 min." or "BillMySales: rejected (HTTP 422:
  ...)". After a rejection or the last retry, fix the cause and choose
  **Send to BillMySales** in the order actions (a new delivery, whatever
  the statuses selected).
- **WooCommerce > Status > Logs** (source `billmysales`): the same with the
  delivery UUIDs. WooCommerce deletes old logs itself (30 days by default,
  Settings > Advanced > Logs); no debug mode needed.
- **Tools > Scheduled Actions** (group `billmysales_deliver`): the queued
  deliveries and retries.

Notification
------------

A `POST` to the configured URL, with the order as WooCommerce's REST API
represents it (`GET /wc/v3/orders/<id>`), limited to the fields BillMySales
reads: `id`, `number`, `status`, dates, totals and taxes, `customer_id`,
`billing`, `shipping`, `line_items`, `fee_lines`, `coupon_lines`,
`shipping_lines`, `currency`, `payment_method`, `payment_method_title`. The
checkout fields go in `meta_data`, in WooCommerce's `{id, key, value}` shape
(`id` 0). The payload is built when the job runs, with the order's data at
that moment.

Headers:

| Header | Value |
|---|---|
| `X-BillMySales-Signature` | base64 of the HMAC-SHA256 of the raw body with the secret |
| `X-BillMySales-Platform` | `woocommerce` |
| `X-BillMySales-Platform-Version` | WooCommerce version |
| `X-BillMySales-Plugin-Version` | plugin version |
| `X-BillMySales-Source` | store URL (`home_url('/')`) |
| `X-BillMySales-Event` | `order.status_changed`, `order.created` (an order created directly in a selected status) or `order.resent` ("Send to BillMySales") |
| `X-BillMySales-Delivery` | UUID of the notification (the same on retries) |
| `User-Agent` | `BillMySales-woocommerce/<version>` |
| `X-WC-Webhook-Signature`, `X-WC-Webhook-Source` | the same signature and URL, as WooCommerce's native webhooks send them: what BillMySales' WooCommerce datasource reads today |

Verifying the signature (Python):

```python
expected = base64.b64encode(hmac.new(secret, body, hashlib.sha256).digest()).decode()
hmac.compare_digest(expected, headers["X-BillMySales-Signature"])
```

Development
-----------

Layout: `plugin/` is the plugin (what the zip installs, as the
`billmysales` folder); the repository root has the development tools.

```
plugin/                 billmysales.php (header, autoloader), readme.txt
  src/                  classes (BillMySales\WooCommerce\...)
  assets/js/admin.js    settings page script
  languages/            billmysales.pot, es_ES and es_CL catalogs
tests/src/              PHPUnit unit tests (Brain Monkey, no WordPress)
docker/Dockerfile       tools image (PHP CLI, Composer, Xdebug)
```

Every task runs in Docker containers (the host's PHP and Node.js aren't
used): PHP 7.4, the lowest the plugin supports (so PHPUnit 9.6;
`PHP_VERSION=8.5` runs them on another one), and ESLint in `node:24-alpine`:

```shell
make install        # composer install (dev tools)
make lint           # PHP CS Fixer (PSR-12), dry run; WordPress' security rules and required docblocks (phpcs.xml); ESLint (rules, JSDoc, style)
make fix            # PHP CS Fixer and ESLint's fixes (style)
make analyse        # PHPStan (WordPress and WooCommerce stubs, PHP 7.4)
make test           # PHPUnit, with coverage (var/tests-coverage.txt)
make check          # all of the above + version consistency
make i18n           # .pot from the code, updates and compiles the catalogs
make build          # dist/billmysales-woocommerce-<version>.zip
make clean
```

The version lives in the plugin header (`plugin/billmysales.php`);
`BILLMYSALES_VERSION`, `readme.txt` (Stable tag and changelog) and
`CHANGELOG.md` must match it (`make check`).

Source strings are English. After changing them: `make i18n`, translate the
new entries in `plugin/languages/billmysales-es_ES.po` and `-es_CL.po`.

### End-to-end tests

```shell
make e2e            # about 3 minutes; E2E_KEEP=1 keeps the stack running (then make e2e-clean)
```

`tests/e2e/run.sh` clones the
[WooCommerce Docker stack](https://github.com/BillMySales/billmysales-docker-woocommerce)
into `var/e2e/stack` (`STACK_REPO`, `STACK_REF`, default `master`), starts it
with the plugin loaded and a webhook receiver on port 8099
(`tests/e2e/receiver.php`, standing in for BillMySales: it stores each
request as received and answers the status a case asks for), runs the cases
and removes the stack and the receiver. It needs only Docker. **The stack's development
ports (8101, 8401, 8025) and 8099 must be free: stop the WooCommerce
development stack first** (the script checks them before starting).

Each case does something in WooCommerce and `tests/e2e/check.php` checks
what reached the receiver: the number of requests, the signature
(recomputed from the raw body with the secret, in `X-BillMySales-Signature`
and `X-WC-Webhook-Signature`), the headers (platform, versions, event,
source, delivery UUID, User-Agent, the secret in none), the payload against
the order's JSON Schema (`tests/e2e/order-schema.json`), and the order's id,
status, total and checkout fields.

The deliveries stay in `var/e2e/webhooks` until the next run or `make clean`:
`<case>-<time>.body` is the raw body, `.json` has the headers, the decoded
payload and the status the receiver answered (real WooCommerce orders, to
look at their structure or to replay).

| # | Case | Expected |
|---|---|---|
| 1 | Order through the checkout (Store API) with the custom fields, cash on delivery | 1 delivery, status `processing`, "sent" note |
| 2 | Checkout without the required field | refused, no delivery |
| 3 | Status changed to completed | 1 delivery, status `completed` |
| 4 | Status changed to cancelled (not selected) | no delivery |
| 5 | Deliveries deactivated in the settings | no delivery |
| 6 | Receiver answers 503 | a retry scheduled and noted in the order; run, the same delivery arrives again |
| 7 | Receiver answers 401 | 1 request, no retry, "rejected" note |
| 8 | "Send to BillMySales" in the order actions | note "sending requested", 1 delivery `order.resent` |
| 9 | An on-hold order | no "Send to BillMySales" action, nothing sent |
| 10 | Order through the classic (shortcode) checkout with the custom fields | 1 delivery, the fields in `meta_data` |
| 11 | Classic checkout without the required field | refused, no delivery |
| 12 | Settings saved through the admin form (secret with quotes and `\`, "pending" selected) | secret kept as typed, "pending" dropped |
| 13 | The built zip installed through WordPress (no mount), order through the checkout | 1 delivery, the order note translated (es_CL) |
| 14 | Plugin Check (the wordpress.org review's checks) on the installed zip | no errors (warnings listed; report in `var/e2e/plugin-check.txt`) |
| 15 | Uninstall | settings and pending deliveries removed |

The queue is run with WP-CLI (`wp action-scheduler run`) instead of
waiting for the stack's cron. Not covered: how the admin page and the
checkout look (check them in a browser: mount `plugin/` in the stack with
its `overrides/plugin.yaml`, `PLUGIN_PATH=../billmysales-plugin-woocommerce/plugin`,
after `make i18n` for the compiled translations).

### Releases

Bump the version (plugin header, `BILLMYSALES_VERSION`, `readme.txt`),
add its `CHANGELOG.md` entry, commit and push a `vX.Y.Z` tag. The release
workflow (`.github/workflows/release.yml`) calls the tests (`ci.yml`, PHP 7.4
and 8.5) and then the end-to-end tests (`e2e.yml`), and only when both
passed checks the tag matches the version, runs `make build` and publishes
the GitHub Release with the zip. `ci.yml` and `e2e.yml` also run on every
push and pull request (the end-to-end deliveries are uploaded as the
`e2e-results` artifact). Dependabot
(`.github/dependabot.yml`) opens weekly pull requests for the Composer and
npm tools and the workflows' actions.

### Publishing on wordpress.org (pending)

The plugin is prepared for the wordpress.org directory (GPL-compatible
license, `readme.txt` in its format, the data sent to BillMySales documented,
no `Update URI`, Plugin Check without errors in the end-to-end tests; the
slug `billmysales` is free). Still to do:

- A wordpress.org account (not the same as wordpress.com's) and its username
  in `Contributors:` of `plugin/readme.txt` (today `billmysales`).
- Submit the zip for review.
- Deploy each release to wordpress.org's SVN from the release workflow
  (e.g. `10up/action-wordpress-plugin-deploy`).
- Import the Spanish catalogs into translate.wordpress.org.
- Plugin Check's warning about `load_plugin_textdomain()` (in
  `Plugin::load_textdomain()`): not a blocker, and needed while the plugin
  is installed from the GitHub zip. WordPress (up to 7.1, at least) only
  finds a plugin's own translations (`plugin/languages`) through that call;
  without it, only the language packs of translate.wordpress.org. Keep it
  also once published: WordPress prefers the language packs, and the
  bundled catalogs remain the fallback.

License
-------

Copyright (C) 2026 BillMySales. Licensed under the
[GNU Affero General Public License v3.0 or later](LICENSE) (GPL-compatible,
as WordPress.org requires for plugins; WooCommerce is GPL-3.0-or-later).
