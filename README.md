# Angeo UCP Checkout for Magento 2

[![CI](https://github.com/angeo-dev/module-ucp-checkout/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-ucp-checkout/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-ucp-checkout)](https://packagist.org/packages/angeo/module-ucp-checkout)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![UCP](https://img.shields.io/badge/UCP-2026--08--25-4690BF)](https://ucp.dev)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**AI agents build the order. The buyer pays on your own checkout.**

This module adds the UCP **checkout** capability (`dev.ucp.shopping.checkout`,
spec 2026-08-25) with the **discount** and **fulfillment** extensions to Magento 2. Any agent that
speaks UCP (the protocol behind Google AI Mode and Gemini checkout) can
create a checkout session over REST, add items,
set the buyer, a coupon code and a shipping address, pick one of your live
shipping rates, and see live totals. When the buyer is ready,
the agent sends them to `continue_url`: their cart opens on your store with
the address and shipping method already set, and they pay as usual.

No card data, no payment handler, no PCI scope.

## How it works

| Operation | Endpoint | What happens |
|---|---|---|
| Create Checkout | `POST {endpoint}/checkout-sessions` | New guest quote with the items → `201` |
| Get Checkout | `GET {endpoint}/checkout-sessions/{id}` | Current items, totals, status |
| Update Checkout | `PUT {endpoint}/checkout-sessions/{id}` | **Full replacement**: lines, buyer, discount code, shipping |
| Complete Checkout | `POST {endpoint}/checkout-sessions/{id}/complete` | `requires_escalation` + `continue_url` — never charges |
| Cancel Checkout | `POST {endpoint}/checkout-sessions/{id}/cancel` | Session and quote closed |
| continue_url | `GET /ucp/checkout/continue/{token}` | Loads the cart in the buyer's browser → `/checkout` |

`{endpoint}` is `https://your-store/ucp/v1` by default.

**Status:**

* `incomplete` — something the agent can fix: no email, an unavailable item,
  a quantity out of stock. Messages say what and where (`path`).
* `requires_escalation` — everything the API can collect is there. The rest
  (shipping method, payment) happens on your checkout via `continue_url`.
* `completed` — the buyer placed the order. `order.id` and
  `order.permalink_url` are filled in (an observer on
  `checkout_submit_all_after` closes the loop).
* `canceled` — cancelled by the agent or expired (default 6 hours).

**Shipping (fulfillment extension):** one `shipping` method with one
destination and one group (`supports_multi_group: false`). The agent writes
the address in `fulfillment.methods[0].destinations[0]` (postal address
fields; the region may be a code like `IL` or a name). The response lists your
live Magento rates for that address as `groups[0].options`, cheapest first,
each with its price. The agent chooses one with `groups[0].selected_option_id`
and it appears as the `fulfillment` total. Countries outside *Allowed
Countries* and unknown option ids come back as recoverable errors with a
`path`. An Update without `fulfillment` clears the address and the rate
(full replacement). Pickup is not offered.

**Item ids:** `gid://magento/ProductVariant/{id}` (as returned by
[`angeo/module-ucp-catalog`](https://github.com/angeo-dev/module-ucp-catalog)),
`gid://magento/Product/{id}` for simple products, or a plain SKU. Configurable
children are added through their parent, so the order looks the same as one a
shopper built.

**Idempotency-Key** on create, update, complete and cancel: the same key with
the same body replays the stored response; with a different body you get
`409`. Keys are kept for 24 hours.

**Version negotiation:** `UCP-Agent: profile="…"; version="…"`. Versions this
store serves are accepted; others get `422`.

## Requirements

* Magento Open Source, Mage-OS or Adobe Commerce 2.4.6+ (PHP 8.1 – 8.5)
* [`angeo/module-ucp`](https://github.com/angeo-dev/module-ucp) **2.2.0+**

## Install

```bash
composer require angeo/module-ucp-checkout
bin/magento module:enable Angeo_UcpCheckout
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configure

**Stores → Configuration → Angeo → UCP**

1. *Capabilities → Checkout*: **Yes**. Optional: *Extensions → Discount*
   and *Extensions → Fulfillment*: **Yes**.
2. *Transport → REST Endpoint URL*: leave empty (`{baseUrl}/ucp/v1`), or
   enter your own HTTPS URL — the module answers on its path too.
3. *Checkout Sessions*: legal links (terms, privacy, refund — sent in every
   checkout), session lifetime, limits.
4. Check the profile:

```bash
bin/magento angeo:ucp:validate
curl -s https://your-store/.well-known/ucp | jq '.ucp.capabilities | keys'
```

Exclude `/ucp/` from full-page cache (Varnish, LiteSpeed, Cloudflare). The
responses send `Cache-Control: no-store`.

## Try it

```bash
curl -s -X POST https://your-store/ucp/v1/checkout-sessions \
  -H 'Content-Type: application/json' \
  -H 'UCP-Agent: profile="https://agent.example/profile"' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"line_items":[{"item":{"id":"24-MB02"},"quantity":1}],
       "buyer":{"email":"jane@example.com"}}' | jq '{id, status, totals, continue_url}'
```

## Conformance

The official [UCP conformance suite](https://github.com/Universal-Commerce-Protocol/conformance)
tests completion with payment and webhooks too. This
module does not do those on purpose (see *Not included*), so a full run will
not be 77/77. Discovery, version negotiation, the create/get/update/cancel
lifecycle, idempotency and shipping options are the parts it is built to pass.

## Not included (yet)

* Payment through the agent (payment handlers, AP2, card credentials).
* Pickup, multiple destinations or packages per checkout.
* Order capability and webhooks.
* Checkout over the MCP transport. Agents must use REST.
* Signed-in customer checkouts over the API (identity linking).

## License

MIT. Made by [angeo.dev](https://angeo.dev).
