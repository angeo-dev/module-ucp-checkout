# Changelog

All notable changes to `angeo/module-ucp-checkout` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-11-21

Security release. Upgrade together with `angeo/module-ucp` 2.2.2 and, if
installed, `angeo/module-ucp-checkout-mcp` 1.0.1 (enforced through
`conflict`).

### Security

- **Checkouts answer only the platform that created them.** Get, update,
  complete and cancel did not compare the caller's `UCP-Agent` profile
  with the creator's; only the order endpoint did. Now every operation
  does, and a checkout of another platform answers 404 like a missing one.
- **Order ids are no longer sequential.** `order.id` (in the checkout and
  in `GET /orders/{id}` and webhooks) was Magento's increment id. With
  signature verification off, anyone who knew a platform's public profile
  URL could walk the numbers and read addresses and tracking. The id is
  now `ord_` + the random part of the checkout id
  (`Model\OrderReference`); the label still shows `Order #000000123`.
  An increment id is still accepted by `GET /orders/{id}`, but only on a
  request whose signature verified.
- **Idempotency keys are scoped to the calling profile.** Two platforms
  sending the same key no longer share a stored result (a create replayed
  another platform's checkout for an identical body). Stored as a
  length-prefixed SHA-256 of profile and key.
- **Webhook delivery uses the shared address guard** from
  `angeo/module-ucp` 2.2.2 (https, port 443, all addresses global,
  connection pinned). 1.1 picked the first public A record, ignored AAAA,
  and missed 100.64.0.0/10.

### Changed

- `UCP-Agent` with a `profile` is required on every checkout request
  (400 without one). Parsing moved to `Angeo\Ucp\Model\AgentHeader`;
  `VersionNegotiator::parseAgentHeader()` delegates to it.
- With a verified signature, the owner is the profile that supplied the
  verifying key.
- Signature failures answer with the spec's codes and statuses
  (`signature_missing`/`signature_invalid`/`key_not_found` 401,
  `digest_mismatch`/`algorithm_unsupported` 400) instead of
  `unauthorized`.
- Idempotency store failures fail closed with 503 (spec), instead of an
  unhandled exception.
- Webhook signatures cover `ucp-agent` and, when the webhook URL has a
  query, `@query` — both required when present, so a strict verifier
  skipped 1.1 signatures. `WebhookSigner::sign()` takes the `UCP-Agent`
  value as a new optional last argument.
- `CheckoutService::get()/update()/complete()/cancel()` take the caller's
  profile as a new optional last argument; null keeps the 1.1 behaviour
  for callers built against it.

### Tests

- 32 → 53 unit tests: ownership on every operation, request policy
  (profile required, conformance header shape, 422 on a future version,
  503 fail-closed, scoped idempotency, spec error codes, verified owner),
  order references, and a round trip that signs webhooks with this module
  and verifies them with `angeo/module-ucp`'s spec verifier (ES256 and
  Ed25519; a 1.1-style webhook is shown to be skipped).

### Build

- `.github/workflows/ucp-live.yml`: the official conformance suite
  (`core,shopping`) against a live store, nightly, with a badge on the
  `badges` branch. Off until the `UCP_LIVE_URL` repository variable is set;
  store test data in `Test/Conformance/live/`.

## [1.1.1] - 2026-10-26

### Security

- **`continue_url` tokens are no longer stored in plain text.** Anyone who
  could read `angeo_ucp_checkout_session` (a backup, a dump, a read-only SQL
  user) could open an open checkout in a browser and see the buyer's email
  and address. The token is now an HMAC of the checkout id with the store's
  crypt key, and the table keeps only its SHA-256. The token is computed
  again for every response, so `continue_url` stays the same for the life of
  the session.

### Upgrade notes

- A data patch rewrites the column for existing sessions. A `continue_url`
  handed out before the upgrade stops working; the agent gets the new one in
  its next checkout response. Sessions live 6 hours by default.
- Rotating the crypt key (`bin/magento encryption:key:change`) changes the
  tokens of open sessions in the same way.
- `SessionRepository::newContinueToken()` now takes the checkout id. With DI
  nothing else changes.

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
