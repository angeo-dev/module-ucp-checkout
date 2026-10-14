<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Controller;

use Angeo\UcpCheckout\Controller\Checkout\Cancel;
use Angeo\UcpCheckout\Controller\Checkout\Complete;
use Angeo\UcpCheckout\Controller\Checkout\Create;
use Angeo\UcpCheckout\Controller\Checkout\Resume;
use Angeo\UcpCheckout\Controller\Checkout\Session;
use Angeo\UcpCheckout\Controller\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private const BASES = ['/ucp/v1', '/api/ucp'];

    public function testRoutes(): void
    {
        $id = 'chk_' . str_repeat('a', 32);
        self::assertSame([Create::class, 'create', []], Router::route('/ucp/v1/checkout-sessions', self::BASES));
        self::assertSame([Create::class, 'create', []], Router::route('/ucp/v1/checkout-sessions/', self::BASES));
        self::assertSame([Session::class, 'session', [Router::PARAM_ID => $id]], Router::route("/ucp/v1/checkout-sessions/$id", self::BASES));
        self::assertSame([Complete::class, 'complete', [Router::PARAM_ID => $id]], Router::route("/ucp/v1/checkout-sessions/$id/complete", self::BASES));
        self::assertSame([Cancel::class, 'cancel', [Router::PARAM_ID => $id]], Router::route("/api/ucp/checkout-sessions/$id/cancel", self::BASES));
        $token = str_repeat('0f', 24);
        self::assertSame([Resume::class, 'resume', [Router::PARAM_TOKEN => $token]], Router::route("/ucp/checkout/continue/$token", self::BASES));
    }

    public function testNoMatch(): void
    {
        self::assertNull(Router::route('/ucp/v1/catalog/search', self::BASES));
        self::assertNull(Router::route('/ucp/v1/checkout-sessions/a/b/c', self::BASES));
        self::assertNull(Router::route('/ucp/v1/checkout-sessionsX', self::BASES));
        self::assertNull(Router::route('/ucp/checkout/continue/short', self::BASES));
        self::assertNull(Router::route('/other/checkout-sessions', self::BASES));
    }
}
