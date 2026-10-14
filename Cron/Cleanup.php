<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Cron;

use Angeo\UcpCheckout\Model\Config;
use Angeo\UcpCheckout\Model\IdempotencyStore;
use Angeo\UcpCheckout\Model\SessionRepository;
use Angeo\UcpCheckout\Model\WebhookQueue;
use Psr\Log\LoggerInterface;

/**
 * Daily: idempotency keys older than 24 hours (the spec's minimum) and
 * session rows past their retention. Quotes are left to Magento's own
 * quote cleanup.
 */
class Cleanup
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly IdempotencyStore $idempotency,
        private readonly WebhookQueue $webhooks,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $now = time();
        $keys = $this->idempotency->deleteOlderThan($now - 86400);
        $rows = $this->sessions->deleteOlderThan($now - 86400 * $this->config->getRetentionDays());
        $this->webhooks->deleteOlderThan($now - 86400 * $this->config->getRetentionDays());
        if ($keys > 0 || $rows > 0) {
            $this->logger->info(sprintf('[Angeo_UcpCheckout] Cleanup removed %d idempotency keys, %d sessions.', $keys, $rows));
        }
    }
}
