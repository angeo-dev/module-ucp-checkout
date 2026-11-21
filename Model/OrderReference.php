<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * The UCP order id: `ord_` plus the random part of the checkout id.
 *
 * Before 1.2.0 the order id was Magento's increment id (000000123…), which
 * is sequential. Combined with an unsigned UCP-Agent header that made
 * `GET /orders/{id}` enumerable: anyone who knew a platform's public
 * profile URL could walk the numbers and read addresses and tracking. The
 * checkout id carries 128 random bits and is known only to the platform
 * that created the checkout, so the order id inherits exactly that secrecy
 * and needs no extra column.
 */
class OrderReference
{
    private const ORDER_PREFIX    = 'ord_';
    private const CHECKOUT_PREFIX = 'chk_';

    public static function fromCheckoutId(string $checkoutId): string
    {
        return self::ORDER_PREFIX . substr($checkoutId, strlen(self::CHECKOUT_PREFIX));
    }

    /**
     * The checkout id behind an order id, or null if it is not one.
     */
    public static function toCheckoutId(string $orderId): ?string
    {
        if (!preg_match('/^' . self::ORDER_PREFIX . '([a-f0-9]{32})$/', $orderId, $m)) {
            return null;
        }

        return self::CHECKOUT_PREFIX . $m[1];
    }

    private function __construct()
    {
    }
}
