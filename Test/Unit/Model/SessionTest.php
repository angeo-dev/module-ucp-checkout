<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\Messages;
use Angeo\UcpCheckout\Model\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private function session(string $status, int $expiresAt): Session
    {
        return new Session(null, 'chk_x', 1, 1, $status, '', 't', [], [], null, null, null, null, $expiresAt, 0);
    }

    public function testExpiryOnlyForOpenSessions(): void
    {
        self::assertTrue($this->session(Session::STATUS_INCOMPLETE, 100)->isExpired(100));
        self::assertFalse($this->session(Session::STATUS_INCOMPLETE, 100)->isExpired(99));
        self::assertFalse($this->session(Session::STATUS_COMPLETED, 100)->isExpired(500));
        self::assertTrue($this->session(Session::STATUS_CANCELED, 100)->isTerminal());
    }

    public function testMessageShapes(): void
    {
        $m = new Messages();
        self::assertSame(
            ['type' => 'error', 'code' => 'missing', 'content' => 'x', 'severity' => 'recoverable', 'path' => '$.buyer.email'],
            $m->error('missing', 'x', Messages::SEVERITY_RECOVERABLE, '$.buyer.email')
        );
        self::assertSame(['type' => 'warning', 'code' => 'c', 'content' => 'y'], $m->warning('c', 'y'));
        $body = $m->errorBody('2026-08-25', [$m->error('not_found', 'z', Messages::SEVERITY_UNRECOVERABLE)]);
        self::assertSame(['version' => '2026-08-25', 'status' => 'error'], $body['ucp']);
        self::assertTrue(Messages::hasSeverity($body['messages'], Messages::SEVERITY_UNRECOVERABLE));
        self::assertFalse(Messages::hasSeverity($body['messages'], Messages::SEVERITY_RECOVERABLE));
    }
}
