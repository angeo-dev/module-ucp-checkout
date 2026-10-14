# Security Policy

## Supported versions

`angeo/module-ucp-checkout` follows SemVer. Only the latest tag receives
security fixes. The latest minor is supported for six months after the next
minor ships.

## Reporting a vulnerability

Email **info@angeo.dev** with the subject line `[security] module-ucp-checkout`.

Please include the module version, Magento and PHP versions, and steps to
reproduce. I aim to acknowledge within 72 hours and ship a fix within 14 days
for confirmed high-severity issues. Please do not open a public GitHub issue
for security problems.

## Security model

- **No payment data.** The module never receives, stores or forwards card or
  bank details. Complete Checkout always hands the buyer off to the store's
  own checkout page (`requires_escalation` + `continue_url`).
- **Guest quotes only.** Agent calls create guest quotes. They never read or
  change a signed-in customer's cart. A customer's cart is touched only when
  that customer opens the `continue_url` in their own browser.
- **Unguessable handles.** Checkout ids are `chk_` + 128 random bits. The
  `continue_url` uses a separate 192-bit token, so knowing a checkout id is
  not enough to load its cart into a browser.
- **Storefront rules apply.** Only enabled products assigned to the current
  website and visible on the storefront (or children of such a configurable)
  can be added. Hidden products answer like missing ones.
- **Limits.** Line items per checkout and quantity per line are capped in
  config; bodies over 256 KB are refused.
- **Signatures.** Requests go through the RFC 9421 policy of
  `angeo/module-ucp` (*Security → Inbound Signature Mode*). With
  `required`, unsigned or bad signatures get 401.
- **No PHP session on agent calls.** Only the `continue_url` page uses the
  shopper's session.

- **Orders.** Get Order answers only the platform profile that created the
  checkout. Webhooks go only to HTTPS URLs that resolve to public
  addresses; the address is pinned for the request and redirects are not
  followed.

In scope: anything that lets a caller read or change a checkout it did not
create, reach a customer's cart or data, place an order, or exceed a
configured limit. Out of scope: rate limiting (do it at the web server or
CDN) and Magento core issues.
