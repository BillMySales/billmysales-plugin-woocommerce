#!/usr/bin/env bash
#
# BillMySales for WooCommerce: end-to-end tests (make e2e).
#
# Clones the WooCommerce Docker stack into var/e2e/stack, starts it with the
# plugin loaded, and runs each test case against a local webhook receiver
# (tests/e2e/receiver.php, standing in for BillMySales): the case creates
# something in WooCommerce, the plugin sends the order, and
# tests/e2e/check.php checks what arrived (signature, headers, payload,
# the order's JSON Schema). Two phases: the plugin mounted from
# plugin/ (cases 1-12), then the built zip installed through WordPress
# (cases 13-15). At the end the stack (containers, volumes, clone) and the
# receiver are removed; the results stay in var/e2e until the next run or
# make clean: webhooks/<case>-<time>.body (raw body) and .json (headers,
# decoded payload, status answered), real deliveries to look at or to
# replay against BillMySales; checkout.json, stack.log. E2E_KEEP=1 also
# keeps the stack running (then make e2e-clean).
#
# Needs only Docker on the host. The stack's development ports and the
# receiver's (8099) must be free: stop the WooCommerce development stack.
#
# Environment: TOOLS_IMAGE (set by the Makefile), STACK_REPO, STACK_REF
# (default master), E2E_KEEP, E2E_STACK_ENV (extra "NAME=value" lines, one per
# line, appended to the stack's .env: e.g. WP_VERSION, WC_VERSION and
# PHP_VERSION to test another combination), E2E_STACK_OVERRIDES (names of the
# stack's overrides/<name>.yaml to add, e.g. old-php), E2E_RECEIVER_PORT
# (default 8099, for when another end-to-end run holds it).

set -euo pipefail

PLATFORM=woocommerce
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
E2E="${ROOT}/var/e2e"
STACK="${E2E}/stack"
PROJECT="${PLATFORM}-e2e"
STACK_REPO="${STACK_REPO:-https://github.com/BillMySales/billmysales-docker-${PLATFORM}.git}"
STACK_REF="${STACK_REF:-master}"
TOOLS_IMAGE="${TOOLS_IMAGE:?Run it with make e2e}"
RECEIVER="${PROJECT}-receiver"
RECEIVER_PORT="${E2E_RECEIVER_PORT:-8099}"
RECEIVER_URL="http://host.docker.internal:${RECEIVER_PORT}/"
# A secret with characters that must survive the settings form and JSON.
SECRET='e2e "secret"\x'
SECRET_JSON='e2e \"secret\"\\x'
VERSION="$(sed -n 's/^ \* Version: *//p' "${ROOT}/plugin/billmysales.php")"
ZIP="${ROOT}/dist/billmysales-${PLATFORM}-${VERSION}.zip"
FAILED=0
FROM=0

# --- Helpers -----------------------------------------------------------------

say() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
fail() { printf '    ✘ %s\n' "$*"; FAILED=1; }
pass() { printf '    ✔ %s\n' "$*"; }
expect() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (got \"$1\", expected \"$2\")"; fi; }

compose() { (cd "${STACK}" && docker compose "$@"); }
# WP-CLI in the stack; its container messages go to the log.
wp() { compose run --rm -T wp "$@" 2>> "${E2E}/stack.log"; }
run_queue() { wp action-scheduler run --hooks=billmysales_deliver > /dev/null; }
# The pending deliveries and running one, with Action Scheduler's PHP API: the
# `wp action-scheduler action` commands only exist in newer WooCommerce releases.
pending_ids() { wp eval 'echo implode("\n", as_get_scheduled_actions(["hook" => "billmysales_deliver", "status" => ActionScheduler_Store::STATUS_PENDING, "per_page" => -1], "ids"));'; }
pending_jobs() { pending_ids | sed '/^$/d' | wc -l | tr -d ' '; }
run_action() { wp eval "ActionScheduler::runner()->process_action((int) $1);"; }
received() { find "${E2E}/webhooks" -name '*.json' | wc -l | tr -d ' '; }
respond() { echo "$1" > "${E2E}/respond"; }
port_in_use() { (exec 3<> "/dev/tcp/127.0.0.1/$1") 2> /dev/null; }
env_value() { { printf '%s\n' "${E2E_STACK_ENV:-}"; cat "${STACK}/.env.dev.example"; } | sed -n "s/^$1=//p" | head -1; }

# Starts a test case: what the receiver got before it isn't checked.
case_start() { printf '\n[%s] %s\n' "$1" "$2"; printf '%02d' "$1" > "${E2E}/case"; FROM="$(received)"; }

# Checks the requests received since case_start (tests/e2e/check.php).
check() {
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "${ROOT}:/app" -w /app "${TOOLS_IMAGE}" \
        php tests/e2e/check.php var/e2e/webhooks "${FROM}" "$1" || FAILED=1
}

# Expectations of a delivered order (check.php's JSON).
order_expectations() { # <order id> <status> <meta JSON> [extra JSON members]
    printf '{"count": 1, "secret": "%s", "platform": "%s", "plugin_version": "%s", "source": "%s/", "event": "%s", "schema": "tests/e2e/order-schema.json", "order_id": %s, "status": "%s", "total": "%s", "meta": %s%s}' \
        "${SECRET_JSON}" "${PLATFORM}" "${VERSION}" "${WP_URL}" "${EVENT:-order.status_changed}" "$1" "$2" \
        "$(wp wc shop_order get "$1" --field=total --user=1)" "$3" "${4:+, $4}"
}

# Checks an order has a note (the plugin records each attempt in the order).
expect_note() { # <order id> <note>
    if wp wc order_note list "$1" --user=1 --field=note | grep -F -- "$2" > /dev/null; then
        pass "order note: $2"
    else
        fail "no order note \"$2\" (notes: $(wp wc order_note list "$1" --user=1 --field=note | tr '\n' '|'))"
    fi
}

# Places an order through the Store API (what the block checkout uses) and
# prints its id (empty when the checkout was refused; the response goes to
# var/e2e/checkout.json).
checkout() { # <additional_fields JSON>
    local api="${WP_URL}/wp-json/wc/store/v1" headers nonce token
    headers="$(curl -fsS -D - -o /dev/null "${api}/cart")"
    nonce="$(printf '%s' "${headers}" | tr -d '\r' | sed -n 's/^[Nn]once: //p')"
    token="$(printf '%s' "${headers}" | tr -d '\r' | sed -n 's/^[Cc]art-[Tt]oken: //p')"
    curl -fsS -o /dev/null -H "Nonce: ${nonce}" -H "Cart-Token: ${token}" -H 'Content-Type: application/json' \
        -d "{\"id\": ${PRODUCT_ID}, \"quantity\": 2}" "${api}/cart/add-item"
    local address='"first_name": "Ana", "last_name": "Pérez", "address_1": "Av. Siempre Viva 123", "city": "Santiago", "state": "CL-RM", "postcode": "", "country": "CL"'
    curl -sS -o "${E2E}/checkout.json" -H "Nonce: ${nonce}" -H "Cart-Token: ${token}" -H 'Content-Type: application/json' \
        -d "{\"billing_address\": {${address}, \"email\": \"ana@example.com\", \"phone\": \"+56911111111\"}, \"shipping_address\": {${address}}, \"payment_method\": \"cod\", \"additional_fields\": $1}" \
        "${api}/checkout"
    sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' "${E2E}/checkout.json"
}

# Places an order through the classic (shortcode) checkout, as a browser:
# the cart in a cookie session, the form's nonce, then its AJAX submission.
# Prints the order id (empty when refused; the response goes to
# var/e2e/checkout.json).
classic_checkout() { # <extra form fields, as curl arguments...>
    local jar="${E2E}/classic-cookies" nonce
    rm -f "${jar}"
    curl -fsS -c "${jar}" -b "${jar}" -o /dev/null "${WP_URL}/?add-to-cart=${PRODUCT_ID}"
    nonce="$(curl -fsS -c "${jar}" -b "${jar}" "${CLASSIC_URL}" | sed -n 's/.*name="woocommerce-process-checkout-nonce" value="\([^"]*\)".*/\1/p' | head -1)"
    curl -sS -c "${jar}" -b "${jar}" -o "${E2E}/checkout.json" "${WP_URL}/?wc-ajax=checkout" \
        --data-urlencode billing_first_name=Ana --data-urlencode 'billing_last_name=Pérez' \
        --data-urlencode billing_country=CL --data-urlencode 'billing_address_1=Av. Siempre Viva 123' \
        --data-urlencode billing_city=Santiago --data-urlencode billing_state=CL-RM --data-urlencode billing_postcode= \
        --data-urlencode billing_phone=+56911111111 --data-urlencode billing_email=ana@example.com \
        --data-urlencode payment_method=cod --data-urlencode "woocommerce-process-checkout-nonce=${nonce}" "$@"
    rm -f "${jar}"
    sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' "${E2E}/checkout.json"
}

# Writes the stack's .env: the development template, E2E_STACK_ENV, this
# project name and the overrides: E2E_STACK_OVERRIDES and, with "mount", the
# plugin override pointing to plugin/.
stack_env() { # mount|zip
    local files="compose.yaml" override
    if [ "$1" = mount ]; then
        files="${files}:overrides/plugin.yaml"
    fi
    for override in ${E2E_STACK_OVERRIDES:-}; do
        files="${files}:overrides/${override}.yaml"
    done
    {
        cat "${STACK}/.env.dev.example"
        if [ -n "${E2E_STACK_ENV:-}" ]; then
            printf '%s\n' "${E2E_STACK_ENV}"
        fi
        echo "COMPOSE_PROJECT_NAME=${PROJECT}"
        echo "COMPOSE_FILE=${files}"
        if [ "$1" = mount ]; then
            echo "PLUGIN_PATH=${ROOT}/plugin"
            echo "PLUGIN_NAME=billmysales"
        fi
    } > "${STACK}/.env"
}

# shellcheck disable=SC2329 # called by the EXIT trap
cleanup() {
    local status=$?
    if [ "${E2E_KEEP:-0}" = 1 ]; then
        say "Stack kept running (E2E_KEEP=1), results in var/e2e; remove with: make e2e-clean"
        return
    fi
    say "Removing the stack and the receiver; results kept in var/e2e (webhooks/, checkout.json, stack.log)"
    [ -f "${STACK}/compose.yaml" ] && compose down -v --remove-orphans > /dev/null 2>&1 || true
    docker rm -f "${RECEIVER}" > /dev/null 2>&1 || true
    rm -rf "${STACK}" "${E2E}/case" "${E2E}/respond" "${E2E}/cookies"
    exit "${status}"
}

# --- Preparation ---------------------------------------------------------------

[ -f "${ZIP}" ] || die "${ZIP} not found (make e2e builds it)"
# Containers of a run kept with E2E_KEEP=1.
if docker ps -aq --filter "name=^${RECEIVER}$" | grep . > /dev/null \
    || docker ps -aq --filter "label=com.docker.compose.project=${PROJECT}" | grep . > /dev/null; then
    die "the stack of a previous run is still there: make e2e-clean"
fi
port_in_use "${RECEIVER_PORT}" && die "port ${RECEIVER_PORT} (webhook receiver) is in use"

# The previous run's results are replaced.
rm -rf "${E2E}"
mkdir -p "${E2E}/webhooks"
trap cleanup EXIT

say "Cloning ${STACK_REPO} (${STACK_REF})"
git clone -q --depth 1 --branch "${STACK_REF}" "${STACK_REPO}" "${STACK}"
WP_URL="$(env_value WP_URL)"
for port in "$(env_value HTTP_PORT)" "$(env_value HTTPS_PORT)" "$(env_value MAILPIT_PORT)"; do
    port_in_use "${port}" && die "port ${port} is in use: stop the ${PLATFORM} development stack (docker compose down)"
done

say "Starting the webhook receiver (port ${RECEIVER_PORT})"
docker run -d --name "${RECEIVER}" -u "$(id -u):$(id -g)" -p "${RECEIVER_PORT}:${RECEIVER_PORT}" \
    -v "${ROOT}/tests/e2e/receiver.php:/receiver/index.php:ro" -v "${E2E}:/e2e" \
    "${TOOLS_IMAGE}" php -S "0.0.0.0:${RECEIVER_PORT}" -t /receiver > /dev/null

say "Starting the stack, plugin mounted from plugin/ (log: var/e2e/stack.log)"
stack_env mount
compose up -d --wait >> "${E2E}/stack.log" 2>&1 || die "the stack didn't start (var/e2e/stack.log)"
wp plugin activate billmysales > /dev/null
wp option update billmysales_settings --format=json \
    "{\"url\": \"${RECEIVER_URL}\", \"secret\": \"${SECRET_JSON}\", \"statuses\": [\"processing\", \"completed\"], \"active\": true}" > /dev/null
wp option update billmysales_checkout_fields --format=json \
    '[{"key": "rut", "label": "RUT", "values": [], "required": true}, {"key": "documento", "label": "Documento", "values": ["Boleta", "Factura"], "required": false}]' > /dev/null
wp wc payment_gateway update cod --enabled=true --user=1 > /dev/null
PRODUCT_ID="$(wp wc product create --name=Polera --regular_price=9990 --user=1 --porcelain)"
FIELDS='{"billmysales/rut": "11.111.111-1", "billmysales/documento": "Factura"}'
FIELDS_META='{"rut": "11.111.111-1", "documento": "Factura"}'

# --- Cases: plugin mounted -------------------------------------------------------

case_start 1 "Order through the checkout, with the custom fields (processing)"
ORDER="$(checkout "${FIELDS}")"
[ -n "${ORDER}" ] || fail "checkout refused: $(cat "${E2E}/checkout.json")"
run_queue
check "$(order_expectations "${ORDER}" processing "${FIELDS_META}")"
expect_note "${ORDER}" "BillMySales: sent (HTTP 200)."

case_start 2 "Checkout without the required field"
REFUSED="$(checkout '{"billmysales/documento": "Boleta"}')"
expect "${REFUSED}" "" "checkout refused"
if grep -q 'billmysales\\/rut' "${E2E}/checkout.json"; then pass "the error names the field"; else fail "the error doesn't name billmysales/rut"; fi
run_queue
check '{"count": 0}'

case_start 3 "Status changed to completed"
wp wc shop_order update "${ORDER}" --status=completed --user=1 > /dev/null
run_queue
check "$(order_expectations "${ORDER}" completed "${FIELDS_META}")"

case_start 4 "Status changed to one not selected (cancelled)"
wp wc shop_order update "${ORDER}" --status=cancelled --user=1 > /dev/null
run_queue
check '{"count": 0}'

case_start 5 "Deliveries deactivated in the settings"
wp option patch update billmysales_settings active false --format=json > /dev/null
checkout "${FIELDS}" > /dev/null
run_queue
check '{"count": 0}'
wp option patch update billmysales_settings active true --format=json > /dev/null

case_start 6 "BillMySales answers 503: retried with the same delivery"
respond 503
ORDER6="$(checkout "${FIELDS}")"
run_queue
RETRY="$(pending_ids)"
if [ -n "${RETRY}" ]; then pass "a retry is scheduled"; else fail "no retry scheduled"; fi
expect_note "${ORDER6}" "BillMySales: not sent (HTTP 503), retry 1 in "
respond 200
[ -n "${RETRY}" ] && run_action "${RETRY}" > /dev/null
check "$(order_expectations "${ORDER6}" processing "${FIELDS_META}" '"same_delivery": true' | sed 's/"count": 1/"count": 2/')"

case_start 7 "BillMySales answers 401: not retried"
respond 401
ORDER7="$(checkout "${FIELDS}")"
run_queue
check "$(order_expectations "${ORDER7}" processing "${FIELDS_META}")"
expect "$(pending_jobs)" 0 "no retry scheduled"
expect_note "${ORDER7}" "BillMySales: rejected (HTTP 401: error)."
respond 200

case_start 8 "Sent again from the order actions (\"Send to BillMySales\")"
wp eval "do_action('woocommerce_order_action_billmysales_send', wc_get_order(${ORDER7}));" > /dev/null
expect_note "${ORDER7}" "BillMySales: sending requested."
run_queue
EVENT=order.resent check "$(EVENT=order.resent order_expectations "${ORDER7}" processing "${FIELDS_META}")"
expect_note "${ORDER7}" "BillMySales: sent (HTTP 200)."
case_start 9 "No \"Send to BillMySales\" for an order not ready to bill (on-hold)"
wp wc shop_order update "${ORDER7}" --status=on-hold --user=1 > /dev/null
expect "$(wp eval "echo implode(',', array_keys(apply_filters('woocommerce_order_actions', [], wc_get_order(${ORDER7}))));")" "" "no order action"
wp eval "do_action('woocommerce_order_action_billmysales_send', wc_get_order(${ORDER7}));" > /dev/null
run_queue
check '{"count": 0}'

case_start 10 "Order through the classic (shortcode) checkout, with the custom fields"
DEFAULT_CHECKOUT_PAGE="$(wp option get woocommerce_checkout_page_id)"
CLASSIC_PAGE="$(wp post create --post_type=page --post_title='Classic checkout' --post_status=publish --post_content='[woocommerce_checkout]' --porcelain)"
CLASSIC_URL="$(wp eval "echo get_permalink(${CLASSIC_PAGE});")"
wp option update woocommerce_checkout_page_id "${CLASSIC_PAGE}" > /dev/null
# The store's "coming soon" mode hides the storefront from visitors.
wp option update woocommerce_coming_soon no > /dev/null
ORDER10="$(classic_checkout --data-urlencode 'billmysales_rut=44.444.444-4' --data-urlencode 'billmysales_documento=Boleta')"
[ -n "${ORDER10}" ] || fail "classic checkout refused: $(head -c 300 "${E2E}/checkout.json")"
run_queue
check "$(order_expectations "${ORDER10}" processing '{"rut": "44.444.444-4", "documento": "Boleta"}')"

case_start 11 "Classic checkout without the required field"
expect "$(classic_checkout --data-urlencode 'billmysales_documento=Boleta')" "" "checkout refused"
if grep -q 'data-id=\\"billmysales_rut\\"' "${E2E}/checkout.json"; then pass "the error names the field"; else fail "the error doesn't name billmysales_rut"; fi
run_queue
check '{"count": 0}'
wp option update woocommerce_checkout_page_id "${DEFAULT_CHECKOUT_PAGE}" > /dev/null

case_start 12 "Settings saved through the admin form"
JAR="${E2E}/cookies"
curl -fsS -c "${JAR}" -b "${JAR}" -o /dev/null "${WP_URL}/wp-login.php"
curl -fsS -c "${JAR}" -b "${JAR}" -o /dev/null --data-urlencode "log=$(env_value WP_ADMIN_USER)" \
    --data-urlencode "pwd=$(env_value WP_ADMIN_PASSWORD)" -d 'testcookie=1' "${WP_URL}/wp-login.php"
PAGE="$(curl -fsS -b "${JAR}" "${WP_URL}/wp-admin/admin.php?page=billmysales")"
NONCE="$(printf '%s' "${PAGE}" | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)"
if [ -n "${NONCE}" ]; then pass "settings page rendered"; else fail "settings page without its form"; fi
curl -fsS -b "${JAR}" -o /dev/null "${WP_URL}/wp-admin/options.php" \
    --data-urlencode option_page=billmysales_settings_group --data-urlencode action=update \
    --data-urlencode "_wpnonce=${NONCE}" --data-urlencode "billmysales_settings[url]=${RECEIVER_URL}" \
    --data-urlencode "billmysales_settings[secret]=${SECRET}" --data-urlencode 'billmysales_settings[statuses][]=processing' \
    --data-urlencode 'billmysales_settings[statuses][]=pending' --data-urlencode 'billmysales_settings[statuses][]=completed' \
    --data-urlencode 'billmysales_settings[active]=1'
expect "$(wp option get billmysales_settings --format=json)" \
    "{\"url\":\"http:\\/\\/host.docker.internal:${RECEIVER_PORT}\\/\",\"secret\":\"${SECRET_JSON}\",\"statuses\":[\"processing\",\"completed\"],\"active\":true}" \
    "secret kept as typed, \"pending\" dropped"

# --- Cases: the built zip ----------------------------------------------------------

say "Installing $(basename "${ZIP}") through WordPress (no mount)"
stack_env zip
compose up -d --wait >> "${E2E}/stack.log" 2>&1 || die "the stack didn't restart (var/e2e/stack.log)"
compose run --rm -T -v "${ROOT}/dist:/dist:ro" wp plugin install "/dist/$(basename "${ZIP}")" --force --activate \
    >> "${E2E}/stack.log" 2>&1 || die "the zip didn't install (var/e2e/stack.log)"
expect "$(wp plugin get billmysales --field=version)" "${VERSION}" "installed version"

case_start 13 "Order through the checkout, plugin installed from the zip (translated notes)"
ORDER13="$(checkout "${FIELDS}")"
run_queue
check "$(order_expectations "${ORDER13}" processing "${FIELDS_META}")"
expect_note "${ORDER13}" "BillMySales: enviado (HTTP 200)."

case_start 14 "Plugin Check (the wordpress.org review's checks), on the installed zip"
wp plugin install plugin-check --activate > /dev/null
wp plugin check billmysales --format=json > "${E2E}/plugin-check.txt" || true
# grep finds nothing when all is fine (not an error here).
ERRORS="$({ grep -o '"type":"ERROR"' "${E2E}/plugin-check.txt" || true; } | wc -l | tr -d ' ')"
expect "${ERRORS}" 0 "no errors (var/e2e/plugin-check.txt)"
{ grep -o '"type":"WARNING","code":"[^"]*"' "${E2E}/plugin-check.txt" || true; } | sed 's/.*"code":"//;s/"$//' | sort -u \
    | while read -r code; do printf '    ! warning: %s\n' "${code}"; done
wp plugin deactivate plugin-check > /dev/null

case_start 15 "Uninstall"
wp plugin uninstall --deactivate billmysales > /dev/null
expect "$(wp option list --search='billmysales_*' --format=count)" 0 "settings removed"
expect "$(pending_jobs)" 0 "no pending deliveries"

# --- Result ------------------------------------------------------------------------

if [ "${FAILED}" = 0 ]; then
    say "All end-to-end cases passed"
else
    say "Some end-to-end cases FAILED"
fi
exit "${FAILED}"
