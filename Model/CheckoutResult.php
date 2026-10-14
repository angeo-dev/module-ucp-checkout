<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * HTTP status + JSON body of one checkout operation.
 */
class CheckoutResult
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body
    ) {
    }
}
