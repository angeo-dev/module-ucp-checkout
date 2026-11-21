<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Signs an outgoing webhook per the UCP Message Signatures spec (RFC 9421
 * request signing + RFC 9530 Content-Digest).
 *
 * Signed components: @method @authority @path content-digest content-type,
 * plus @query when the webhook URL has one and ucp-agent when the header is
 * sent (1.2.0 — the spec requires both to be covered when present), with
 * `created` and `keyid`; no `alg`, which the spec leaves out. Keys: ES256, ES384 (OpenSSL) and Ed25519
 * (libsodium), PKCS#8 PEM — the format `angeo:ucp:keys:generate` prints.
 */
class WebhookSigner
{
    public const COMPONENTS = ['@method', '@authority', '@path', 'content-digest', 'content-type'];

    private const ED25519_PKCS8_PREFIX = '302e020100300506032b657004220420';

    /**
     * @param string|null $ucpAgent UCP-Agent header value to send and cover.
     * @return array<string, string> headers to add
     * @throws \RuntimeException when the key cannot sign
     */
    public function sign(
        string $method,
        string $url,
        string $body,
        string $kid,
        string $privatePem,
        ?int $created = null,
        ?string $ucpAgent = null
    ): array {
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $params = '(' . implode(' ', array_map(
            static fn (string $c): string => '"' . $c . '"',
            self::components($url, $ucpAgent)
        )) . ')'
            . ';created=' . ($created ?? time())
            . ';keyid="' . str_replace('"', '', $kid) . '"';

        $base = $this->signatureBase($method, $url, $digest, 'application/json', $params, $ucpAgent);
        $signature = $this->rawSignature($base, $privatePem);

        $headers = [
            'Content-Type'    => 'application/json',
            'Content-Digest'  => $digest,
            'Signature-Input' => 'sig1=' . $params,
            'Signature'       => 'sig1=:' . base64_encode($signature) . ':',
        ];
        if ($ucpAgent !== null && $ucpAgent !== '') {
            $headers['UCP-Agent'] = $ucpAgent;
        }

        return $headers;
    }

    /**
     * Covered components for a webhook to this URL.
     *
     * @return array<int, string>
     */
    public static function components(string $url, ?string $ucpAgent = null): array
    {
        $components = ['@method', '@authority', '@path'];
        if ((string) parse_url($url, PHP_URL_QUERY) !== '') {
            $components[] = '@query';
        }
        if ($ucpAgent !== null && $ucpAgent !== '') {
            $components[] = 'ucp-agent';
        }
        $components[] = 'content-digest';
        $components[] = 'content-type';

        return $components;
    }

    public function signatureBase(
        string $method,
        string $url,
        string $digest,
        string $contentType,
        string $params,
        ?string $ucpAgent = null
    ): string {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $authority = $host . ($port !== null && $port !== 443 ? ':' . $port : '');
        $path = (string) ($parts['path'] ?? '/');

        $values = [
            '@method'        => strtoupper($method),
            '@authority'     => $authority,
            '@path'          => $path === '' ? '/' : $path,
            '@query'         => '?' . (string) ($parts['query'] ?? ''),
            'ucp-agent'      => (string) $ucpAgent,
            'content-digest' => $digest,
            'content-type'   => $contentType,
        ];

        $lines = [];
        foreach (self::components($url, $ucpAgent) as $component) {
            $lines[] = '"' . $component . '": ' . $values[$component];
        }
        $lines[] = '"@signature-params": ' . $params;

        return implode("\n", $lines);
    }

    private function rawSignature(string $base, string $pem): string
    {
        $der = $this->pemBody($pem);
        if ($der !== null && str_starts_with(bin2hex($der), self::ED25519_PKCS8_PREFIX) && strlen($der) === 48) {
            if (!function_exists('sodium_crypto_sign_detached')) {
                throw new \RuntimeException('Ed25519 signing needs ext-sodium.');
            }
            $keypair = sodium_crypto_sign_seed_keypair(substr($der, 16));
            return sodium_crypto_sign_detached($base, sodium_crypto_sign_secretkey($keypair));
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('Private key could not be read.');
        }
        $details = openssl_pkey_get_details($key);
        $curve = (string) ($details['ec']['curve_name'] ?? '');
        [$algo, $size] = match ($curve) {
            'prime256v1' => [OPENSSL_ALGO_SHA256, 32],
            'secp384r1'  => [OPENSSL_ALGO_SHA384, 48],
            default      => throw new \RuntimeException('Unsupported key type; use ES256, ES384 or Ed25519.'),
        };
        if (!openssl_sign($base, $derSignature, $key, $algo)) {
            throw new \RuntimeException('Signing failed.');
        }
        return self::derToRaw((string) $derSignature, $size);
    }

    /**
     * ECDSA signatures are DER SEQUENCE{r, s} from OpenSSL; JOSE/HTTP
     * signatures use fixed-size r||s.
     */
    public static function derToRaw(string $der, int $size): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new \RuntimeException('Bad ECDSA signature.');
            }
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $int = ltrim($int, "\x00");
            $out .= str_pad($int, $size, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $out;
    }

    private function pemBody(string $pem): ?string
    {
        if (!preg_match('/-----BEGIN PRIVATE KEY-----(.+?)-----END PRIVATE KEY-----/s', $pem, $m)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        return $der === false ? null : $der;
    }
}
