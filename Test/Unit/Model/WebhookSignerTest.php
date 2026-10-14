<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase
{
    private const URL = 'https://platform.example.com/webhooks/ucp/orders?x=1';
    private const BODY = '{"id":"000000042"}';

    private static function rawToDer(string $raw): string
    {
        $half = intdiv(strlen($raw), 2);
        $enc = static function (string $int): string {
            $int = ltrim($int, "\x00");
            if ($int === '' || (ord($int[0]) & 0x80)) {
                $int = "\x00" . $int;
            }
            return "\x02" . chr(strlen($int)) . $int;
        };
        $seq = $enc(substr($raw, 0, $half)) . $enc(substr($raw, $half));
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    public function testEs256SignatureVerifies(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        $signer = new WebhookSigner();
        $headers = $signer->sign('POST', self::URL, self::BODY, 'shop-2026', $pem, 1760000000);

        self::assertSame('sha-256=:' . base64_encode(hash('sha256', self::BODY, true)) . ':', $headers['Content-Digest']);
        self::assertSame(
            'sig1=("@method" "@authority" "@path" "content-digest" "content-type");created=1760000000;keyid="shop-2026"',
            $headers['Signature-Input']
        );
        self::assertMatchesRegularExpression('/^sig1=:[A-Za-z0-9+\/=]+:$/', $headers['Signature']);

        $raw = base64_decode(substr($headers['Signature'], 6, -1));
        self::assertSame(64, strlen($raw), 'ES256 r||s is 64 bytes');

        $base = $signer->signatureBase('POST', self::URL, $headers['Content-Digest'], 'application/json', substr($headers['Signature-Input'], 5));
        self::assertStringContainsString("\"@authority\": platform.example.com\n\"@path\": /webhooks/ucp/orders\n", $base);
        self::assertSame(1, openssl_verify($base, self::rawToDer($raw), $public, OPENSSL_ALGO_SHA256));
    }

    public function testEd25519SignatureVerifies(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            self::markTestSkipped('ext-sodium missing');
        }
        $seed = random_bytes(32);
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode(hex2bin('302e020100300506032b657004220420') . $seed), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        $signer = new WebhookSigner();
        $headers = $signer->sign('POST', self::URL, self::BODY, 'ed', $pem, 1760000000);
        $raw = base64_decode(substr($headers['Signature'], 6, -1));
        $base = $signer->signatureBase('POST', self::URL, $headers['Content-Digest'], 'application/json', substr($headers['Signature-Input'], 5));

        self::assertTrue(sodium_crypto_sign_verify_detached($raw, $base, sodium_crypto_sign_publickey($keypair)));
    }

    public function testRejectsRsa(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $this->expectException(\RuntimeException::class);
        (new WebhookSigner())->sign('POST', self::URL, self::BODY, 'k', $pem);
    }
}
