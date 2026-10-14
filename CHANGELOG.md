# Changelog

All notable changes to `angeo/module-ucp-checkout` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-14

### Added

- **Order capability** (`dev.ucp.shopping.order`): `GET {endpoint}/orders/{id}`
  returns the full order — line status, shipping expectation, shipments with
  tracking, refunds, cancellation, totals. Line ids match the checkout's.
  Only the platform that created the checkout (same `UCP-Agent` profile) can
  read it; everyone else gets 404.
- **Order webhooks**: full order entity POSTed to the platform's
  `webhook_url` (from its profile) on order created, shipment, credit memo
  and cancellation. RFC 9421 signature with `Content-Digest`, `Webhook-Id`,
  `Webhook-Timestamp`, store `UCP-Agent`. Queue table
  `angeo_ucp_order_webhook`, sent every minute, retries 1 min → 12 h. HTTPS
  only, no redirects, host pinned to a checked public address.
- Signing with ES256, ES384 or Ed25519 keys from `app/etc/env.php`
  (`ucp/signing_keys`).
- Registers `dev.ucp.shopping.order` as implemented.

## [1.0.0] - 2026-10-14

First release. UCP spec 2026-08-25.

### Added

- REST checkout sessions: create, get, update (full replacement), complete,
  cancel, under `{endpoint}/checkout-sessions` (default `/ucp/v1`, plus the
  path of a configured REST endpoint).
- Complete Checkout hands off to the store checkout: `requires_escalation`
  with a `requires_buyer_review` message and `continue_url`. Nothing is
  charged through the API.
- `continue_url` page that loads the agent-built cart into the buyer's
  browser (merged into a signed-in customer's cart) and redirects to the
  checkout.
- Observer on `checkout_submit_all_after`: session becomes `completed` with
  `order.id` and `order.permalink_url`.
- **Fulfillment extension** (shipping): the agent sends an address, gets the
  store's live Magento shipping rates as options (cheapest first), and picks
  one; it becomes the `fulfillment` total. Region by code or name; countries
  outside *Allowed Countries* and unknown options are recoverable errors.
- Discount extension: one coupon code per checkout, invalid codes as
  warnings.
- Totals in minor units by ISO 4217 exponent; parts always add up to
  `total` (differences shown as `fee` or folded into `discount`).
- Idempotency-Key store (replay on same body, 409 on different body, 24 h).
- UCP-Agent version negotiation (422 for unsupported versions).
- RFC 9421 inbound signature policy shared with `angeo/module-ucp`.
- Registers checkout, discount and fulfillment as implemented, so
  `angeo/module-ucp` 2.2.0 advertises them when their toggles are on.
- Daily cron cleanup of idempotency keys and old sessions.
- Admin settings: session lifetime, limits, legal links, handoff page.
