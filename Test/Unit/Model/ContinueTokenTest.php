<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\ContinueToken;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ContinueTokenTest extends TestCase
{
    private function token(string $key): ContinueToken
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(
            static fn (string $data): string => hash_hmac('sha256', $data, $key)
        );
        return new ContinueToken($encryptor);
    }

    public function testSameSessionGivesSameToken(): void
    {
        $token = $this->token('k1');
        $id = 'chk_' . str_repeat('a', 32);
        self::assertSame($token->forSession($id), $token->forSession($id));
    }

    public function testTokenShape(): void
    {
        $value = $this->token('k1')->forSession('chk_' . str_repeat('b', 32));
        self::assertSame(ContinueToken::LENGTH, strlen($value));
        self::assertTrue(ContinueToken::isWellFormed($value));
    }

    public function testDifferentSessionsAndKeysGiveDifferentTokens(): void
    {
        $a = 'chk_' . str_repeat('a', 32);
        $b = 'chk_' . str_repeat('b', 32);
        self::assertNotSame($this->token('k1')->forSession($a), $this->token('k1')->forSession($b));
        self::assertNotSame($this->token('k1')->forSession($a), $this->token('k2')->forSession($a));
    }

    public function testStorageHashIsNotTheToken(): void
    {
        $value = $this->token('k1')->forSession('chk_' . str_repeat('c', 32));
        $stored = ContinueToken::storageHash($value);
        self::assertSame(64, strlen($stored));
        self::assertNotSame($value, $stored);
        self::assertStringNotContainsString($value, $stored);
        self::assertSame(hash('sha256', $value), $stored);
    }

    public function testMalformedTokensAreRejected(): void
    {
        self::assertFalse(ContinueToken::isWellFormed(''));
        self::assertFalse(ContinueToken::isWellFormed(str_repeat('a', 47)));
        self::assertFalse(ContinueToken::isWellFormed(str_repeat('A', 48)));
        self::assertFalse(ContinueToken::isWellFormed(str_repeat('a', 48) . "\n"));
        self::assertTrue(ContinueToken::isWellFormed(str_repeat('0f', 24)));
    }
}
