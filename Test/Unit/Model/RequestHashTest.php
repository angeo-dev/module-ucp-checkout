<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\RequestHash;
use PHPUnit\Framework\TestCase;

class RequestHashTest extends TestCase
{
    public function testSameJsonDifferentFormattingMatches(): void
    {
        $hash = new RequestHash();
        self::assertSame(
            $hash->hash('POST', '', '{"line_items":[{"item":{"id":"a"},"quantity":1}],"buyer":{"email":"x@y.z"}}'),
            $hash->hash('post', '', "{ \"buyer\": {\"email\": \"x@y.z\"},\n \"line_items\": [{\"quantity\": 1, \"item\": {\"id\": \"a\"}}] }")
        );
    }

    public function testDifferentValuesDiffer(): void
    {
        $hash = new RequestHash();
        self::assertNotSame(
            $hash->hash('POST', '', '{"line_items":[{"item":{"id":"a"},"quantity":1}]}'),
            $hash->hash('POST', '', '{"line_items":[{"item":{"id":"a"},"quantity":2}]}')
        );
        self::assertNotSame($hash->hash('POST', 'chk_1', ''), $hash->hash('POST', 'chk_2', ''));
    }

    public function testListOrderMatters(): void
    {
        $hash = new RequestHash();
        self::assertNotSame($hash->hash('PUT', 'x', '{"a":[1,2]}'), $hash->hash('PUT', 'x', '{"a":[2,1]}'));
    }
}
