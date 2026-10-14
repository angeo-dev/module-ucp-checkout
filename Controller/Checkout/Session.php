<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller\Checkout;

use Angeo\UcpCheckout\Model\CheckoutResult;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;

/**
 * GET {endpoint}/checkout-sessions/{id} — Get Checkout
 * PUT {endpoint}/checkout-sessions/{id} — Update Checkout (full replacement)
 */
class Session extends AbstractAction implements HttpGetActionInterface, HttpPutActionInterface
{
    protected function operation(): ?string
    {
        return $this->isPut() ? 'update' : null;
    }

    protected function process(array $body, string $agentProfile): CheckoutResult
    {
        return $this->isPut()
            ? $this->checkout->update($this->checkoutId(), $body)
            : $this->checkout->get($this->checkoutId());
    }

    private function isPut(): bool
    {
        return strtoupper((string) $this->request->getMethod()) === 'PUT';
    }
}
