<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 *
 * Stand-in for the factory Magento generates at runtime. Unit tests run
 * without generated code, so CheckoutOwnershipTest loads this when the real
 * class is missing.
 */

declare(strict_types=1);

namespace Magento\Quote\Model;

class QuoteFactory
{
    /**
     * Create a quote.
     *
     * @param array<string, mixed> $data
     * @return Quote|null
     */
    public function create(array $data = [])
    {
        return null;
    }
}
