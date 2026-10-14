<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller\Checkout;

use Angeo\UcpCheckout\Model\CheckoutResult;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * POST {endpoint}/checkout-sessions/{id}/cancel
 */
class Cancel extends AbstractAction implements HttpPostActionInterface
{
    protected function operation(): ?string
    {
        return 'cancel';
    }

    protected function process(array $body, string $agentProfile): CheckoutResult
    {
        return $this->checkout->cancel($this->checkoutId());
    }
}
