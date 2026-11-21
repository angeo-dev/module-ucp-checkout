<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Integration;

use Angeo\Ucp\Model\Keys\JwkFormatter;
use Angeo\Ucp\Model\Signature\AuthorityPolicy;
use Angeo\Ucp\Model\Signature\JwkVerifier;
use Angeo\Ucp\Model\Signature\ProfileFetcher;
use Angeo\Ucp\Model\Signature\SignatureBase;
use Angeo\Ucp\Model\Signature\SignatureVerifier;
use Angeo\UcpCheckout\Model\WebhookSigner;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The store signs order webhooks (this module); the platform verifies them.
 * Here the platform side is angeo/module-ucp's SignatureVerifier, which
 * follows the spec's verification algorithm, so a webhook that passes here
 * passes a spec-conformant platform.
 */
class WebhookSignatureRoundTripTest extends TestCase
{
    private const STORE_PROFILE = 'https://shop.example/.well-known/ucp';
    private const WEBHOOK_URL   = 'https://platform.example/webhooks/ucp/orders?tenant=42';
    private const BODY          = '{"id":"ord_00112233445566778899aabbccddeeff","status":"processing"}';

    public function testAnEs256WebhookVerifies(): void
    {
        [$pem, $jwk] = $this->esKey();
        $ucpAgent = 'profile="' . self::STORE_PROFILE . '"; version="2026-08-25"';

        $headers = (new WebhookSigner())->sign('POST', self::WEBHOOK_URL, self::BODY, $jwk['kid'], $pem, null, $ucpAgent);

        $result = $this->platformVerifier($jwk)->verify($this->incoming($headers));

        self::assertTrue($result->isVerified(), $result->reason);
        self::assertSame(self::STORE_PROFILE, $result->agentProfileUrl);
    }

    public function testAnEd25519WebhookVerifies(): void
    {
        $seed = random_bytes(32);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . base64_encode(hex2bin('302e020100300506032b657004220420') . $seed)
            . "\n-----END PRIVATE KEY-----\n";
        $jwk = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::b64u(sodium_crypto_sign_publickey($pair))];
        $jwk['kid'] = (new JwkFormatter())->thumbprint($jwk);

        $headers = (new WebhookSigner())->sign(
            'POST',
            self::WEBHOOK_URL,
            self::BODY,
            $jwk['kid'],
            $pem,
            null,
            'profile="' . self::STORE_PROFILE . '"'
        );

        self::assertTrue($this->platformVerifier($jwk)->verify($this->incoming($headers))->isVerified());
    }

    public function testA11StyleWebhookWouldBeSkipped(): void
    {
        // 1.1 sent UCP-Agent but did not sign it, and did not sign @query.
        // A spec verifier skips such a signature: uncovered means unsigned.
        [$pem, $jwk] = $this->esKey();
        $headers = (new WebhookSigner())->sign('POST', 'https://platform.example/webhooks', self::BODY, $jwk['kid'], $pem);
        $headers['UCP-Agent'] = 'profile="' . self::STORE_PROFILE . '"';

        $result = $this->platformVerifier($jwk)->verify($this->incoming($headers, '/webhooks'));

        self::assertFalse($result->isVerified());
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function esKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'];
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => self::b64u(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y'   => self::b64u(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ];
        $jwk['kid'] = (new JwkFormatter())->thumbprint($jwk);

        return [$pem, $jwk];
    }

    /**
     * @param array<string, string> $headers
     */
    private function incoming(array $headers, ?string $uri = null): HttpRequest
    {
        $parts = parse_url(self::WEBHOOK_URL);
        $headers['Host'] = (string) $parts['host'];
        $uri ??= $parts['path'] . '?' . $parts['query'];

        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(static function (string $name) use ($headers) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
            return false;
        });
        $request->method('getContent')->willReturn(self::BODY);
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('POST');
        $request->method('isSecure')->willReturn(true);

        return $request;
    }

    /**
     * @param array<string, string> $storeKey
     */
    private function platformVerifier(array $storeKey): SignatureVerifier
    {
        $fetcher = $this->getMockBuilder(ProfileFetcher::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $fetcher->method('fetch')->willReturnCallback(
            static fn (string $url): ?array => $url === self::STORE_PROFILE ? ['keys' => [$storeKey]] : null
        );

        $authority = $this->createMock(AuthorityPolicy::class);
        $authority->method('isAllowed')->willReturn(true);

        return new SignatureVerifier(
            $fetcher,
            new SignatureBase(),
            new JwkVerifier(),
            new JwkFormatter(),
            $this->createMock(LoggerInterface::class),
            $authority
        );
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
