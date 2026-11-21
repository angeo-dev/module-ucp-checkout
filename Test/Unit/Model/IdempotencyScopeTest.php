<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\IdempotencyStore;
use PHPUnit\Framework\TestCase;

class IdempotencyScopeTest extends TestCase
{
    public function testTheSameKeyFromTwoPlatformsIsTwoKeys(): void
    {
        $a = IdempotencyStore::scopedKey('https://a.example/.well-known/ucp', 'key-1');
        $b = IdempotencyStore::scopedKey('https://b.example/.well-known/ucp', 'key-1');

        self::assertNotSame($a, $b);
        self::assertSame($a, IdempotencyStore::scopedKey('https://a.example/.well-known/ucp', 'key-1'));
    }

    public function testTheStoredKeyFitsTheColumnWhateverTheClientSends(): void
    {
        $key = IdempotencyStore::scopedKey('https://a.example/p', str_repeat('k', IdempotencyStore::MAX_KEY_LENGTH));

        self::assertSame(64, strlen($key));
    }

    public function testProfileAndKeyCannotBeShiftedIntoEachOther(): void
    {
        self::assertNotSame(
            IdempotencyStore::scopedKey('https://a.example/p', 'x' . "\n" . 'y'),
            IdempotencyStore::scopedKey('https://a.example/p' . "\n" . 'x', 'y')
        );
    }
}
