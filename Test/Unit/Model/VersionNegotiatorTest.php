<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\VersionNegotiator;
use PHPUnit\Framework\TestCase;

class VersionNegotiatorTest extends TestCase
{
    private function negotiator(array $extra = []): VersionNegotiator
    {
        $config = $this->createMock(UcpConfig::class);
        $config->method('getSupportedVersions')->willReturn($extra);
        return new VersionNegotiator($config);
    }

    public function testParsesDictionaryHeader(): void
    {
        self::assertSame(
            ['profile' => 'https://agent.example/profile', 'version' => '2026-08-25'],
            $this->negotiator()->parseAgentHeader('profile="https://agent.example/profile"; version="2026-08-25"')
        );
        self::assertSame(['profile' => 'p', 'version' => ''], $this->negotiator()->parseAgentHeader('profile="p"'));
        self::assertSame(['profile' => '', 'version' => ''], $this->negotiator()->parseAgentHeader(''));
    }

    public function testSupportedVersions(): void
    {
        $n = $this->negotiator(['2026-01-23' => 'https://shop.example/.well-known/ucp/2026-01-23']);
        self::assertTrue($n->isSupported(''));
        self::assertTrue($n->isSupported(UcpConfig::PROTOCOL_VERSION));
        self::assertTrue($n->isSupported(UcpConfig::PREVIOUS_PROTOCOL_VERSION));
        self::assertTrue($n->isSupported('2026-01-23'));
        self::assertFalse($n->isSupported('2099-01-01'), 'conformance: future version -> 422');
    }
}
