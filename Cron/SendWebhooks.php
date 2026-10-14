<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Cron;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\WebhookSender;

/**
 * Every minute: deliver due order webhooks.
 */
class SendWebhooks
{
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly UcpConfig $ucpConfig
    ) {
    }

    public function execute(): void
    {
        if ($this->ucpConfig->isEnabled() && $this->ucpConfig->isOrderDeclared()) {
            $this->sender->sendDue();
        }
    }
}
