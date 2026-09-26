# Changelog

All notable changes to this plugin. Versions follow [Semantic Versioning](https://semver.org).

## [2.0.0] - 2026-09-26

Rewritten:

- Orders are sent in the background (WooCommerce's Action Scheduler), retried
  on network errors, timeouts, rate limits and server errors (after 1 min,
  5 min, 30 min, 2 h and 12 h).
- Fixed: 1.0.0 also sent the secret in a header (`X-WCON-Secret`); only
  the HMAC-SHA256 signature is sent.
- Standard BillMySales headers (`X-BillMySales-*`) plus the ones BillMySales'
  WooCommerce datasource reads (`X-WC-Webhook-Signature`,
  `X-WC-Webhook-Source`, which was missing).
- Each attempt recorded as a private order note; "Send to BillMySales" in
  the order actions sends an order again.
- "Pending payment" and "On hold" are never sent: not ready to bill.
- Custom fields for the block and the classic checkout.
- English source strings, with Spanish (`es_ES`, `es_CL`) translations.
- Settings stored in new `billmysales_*` options (reconfigure after the
  update).

## [1.0.0] - 2026-08-31

- Webhook to a configurable URL when an order reaches the selected
  statuses, signed with HMAC-SHA256.
- Custom fields for the block checkout.
