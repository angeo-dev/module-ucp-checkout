<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * The secret in continue_url, and what the database keeps of it.
 *
 * The token is an HMAC of the checkout id, keyed with the store's crypt key
 * from app/etc/env.php. The database keeps only a SHA-256 of the token. So a
 * copy of the database (a backup, a dump, a read-only SQL user) cannot open
 * anyone's checkout: that also needs env.php.
 *
 * The token can be computed again from the checkout id, so every response
 * can carry the same continue_url without the database holding it.
 *
 * @since 1.1.1
 */
class ContinueToken
{
    /** Hex characters in a token. 48 = 192 bits. */
    public const LENGTH = 48;

    private const CONTEXT = 'angeo-ucp-checkout-continue|';

    public function __construct(
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * The token for a checkout id.
     */
    public function forSession(string $sessionId): string
    {
        return substr($this->encryptor->hash(self::CONTEXT . $sessionId), 0, self::LENGTH);
    }

    /**
     * What the database stores for a token.
     */
    public static function storageHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Whether a string has the shape of a token. Checked before any lookup.
     */
    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{' . self::LENGTH . '}\z/', $token);
    }
}
