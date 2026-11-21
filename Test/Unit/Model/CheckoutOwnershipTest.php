<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\CheckoutMapper;
use Angeo\UcpCheckout\Model\CheckoutService;
use Angeo\UcpCheckout\Model\Config;
use Angeo\UcpCheckout\Model\Fulfillment;
use Angeo\UcpCheckout\Model\ItemResolver;
use Angeo\UcpCheckout\Model\Messages;
use Angeo\UcpCheckout\Model\Session;
use Angeo\UcpCheckout\Model\SessionRepository;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (!class_exists(QuoteFactory::class)) {
    require_once __DIR__ . '/../Stub/QuoteFactory.php';
}

/**
 * 1.2.0: a checkout answers only the platform that created it. Before,
 * get, update, complete and cancel served anyone holding the id.
 */
class CheckoutOwnershipTest extends TestCase
{
    private const OWNER = 'https://platform.example/.well-known/ucp';
    private const OTHER = 'https://other.example/.well-known/ucp';

    public function testAnotherPlatformGets404OnEveryOperation(): void
    {
        $service = $this->service($this->canceledSession(), quoteTouched: false);

        foreach ([
            'get'      => $service->get('chk_1', self::OTHER),
            'update'   => $service->update('chk_1', ['line_items' => []], self::OTHER),
            'complete' => $service->complete('chk_1', self::OTHER),
            'cancel'   => $service->cancel('chk_1', self::OTHER),
        ] as $operation => $result) {
            self::assertSame(404, $result->status, $operation);
            self::assertSame('not_found', $result->body['messages'][0]['code'] ?? null, $operation);
        }
    }

    public function testAnEmptyProfileIsNobody(): void
    {
        $service = $this->service($this->canceledSession(), quoteTouched: false);

        self::assertSame(404, $service->get('chk_1', '')->status);
    }

    public function testTheOwnerIsServed(): void
    {
        $service = $this->service($this->canceledSession(), quoteTouched: false);

        $get = $service->get('chk_1', self::OWNER);
        $cancel = $service->cancel('chk_1', self::OWNER);

        self::assertSame(200, $get->status);
        self::assertSame('canceled', $get->body['status']);
        self::assertSame(200, $cancel->status, 'Repeated cancel answers the canceled resource.');
    }

    public function testCallersBuiltAgainst11StillWork(): void
    {
        // Null = no profile passed (module-ucp-checkout-mcp 1.0.0). Kept for
        // compatibility; composer.json conflicts with that version.
        self::assertSame(200, $this->service($this->canceledSession(), quoteTouched: false)->get('chk_1')->status);
    }

    private function canceledSession(): Session
    {
        $snapshot = ['ucp' => ['version' => UcpConfig::PROTOCOL_VERSION], 'id' => 'chk_1', 'status' => 'canceled'];

        return new Session(
            1,
            'chk_1',
            10,
            1,
            Session::STATUS_CANCELED,
            self::OWNER,
            str_repeat('a', 64),
            [],
            [],
            $snapshot,
            null,
            null,
            null,
            time() + 3600,
            time()
        );
    }

    private function service(Session $session, bool $quoteTouched): CheckoutService
    {
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->method('getBySessionId')->willReturnCallback(
            static fn (string $id): ?Session => $id === $session->sessionId ? $session : null
        );

        $carts = $this->createMock(CartRepositoryInterface::class);
        if (!$quoteTouched) {
            $carts->expects(self::never())->method('get');
            $carts->expects(self::never())->method('save');
        }

        return new CheckoutService(
            $sessions,
            $this->createMock(CheckoutMapper::class),
            $this->createMock(ItemResolver::class),
            new Messages(),
            $this->createMock(Config::class),
            $this->createMock(UcpConfig::class),
            $this->createMock(QuoteFactory::class),
            $carts,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(Fulfillment::class)
        );
    }
}
