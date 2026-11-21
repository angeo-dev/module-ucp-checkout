<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\OrderReference;
use Angeo\UcpCheckout\Model\SessionRepository;
use PHPUnit\Framework\TestCase;

class OrderReferenceTest extends TestCase
{
    public function testRoundTripsAFreshCheckoutId(): void
    {
        $repository = $this->getMockBuilder(SessionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $checkoutId = $repository->newSessionId();

        $orderId = OrderReference::fromCheckoutId($checkoutId);

        self::assertMatchesRegularExpression('/^ord_[a-f0-9]{32}$/', $orderId);
        self::assertSame($checkoutId, OrderReference::toCheckoutId($orderId));
    }

    public function testIncrementIdsAreNotOrderReferences(): void
    {
        // What 1.1 handed out: sequential, so never resolved without a signature.
        self::assertNull(OrderReference::toCheckoutId('000000123'));
        self::assertNull(OrderReference::toCheckoutId('ord_000000123'));
        self::assertNull(OrderReference::toCheckoutId('ord_' . str_repeat('A', 32)));
        self::assertNull(OrderReference::toCheckoutId('chk_' . str_repeat('a', 32)));
    }
}
