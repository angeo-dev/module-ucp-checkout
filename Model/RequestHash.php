<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Fingerprint of a request for Idempotency-Key matching.
 *
 * JSON bodies are canonicalised (keys sorted, whitespace dropped), so the
 * same request sent with different key order or formatting still replays;
 * any change in values is a different request and gets 409.
 */
class RequestHash
{
    public function hash(string $method, string $path, string $body): string
    {
        $decoded = json_decode($body === '' ? 'null' : $body, true);
        $canonical = json_last_error() === JSON_ERROR_NONE
            ? json_encode(self::sortKeys($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;

        return hash('sha256', strtoupper($method) . "\n" . $path . "\n" . $canonical);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map([self::class, 'sortKeys'], $value);
    }
}
