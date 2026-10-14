<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * One row of angeo_ucp_checkout_session. Plain data; SessionRepository
 * loads and saves it.
 */
class Session
{
    public const STATUS_INCOMPLETE          = 'incomplete';
    public const STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    public const STATUS_COMPLETED           = 'completed';
    public const STATUS_CANCELED            = 'canceled';

    /**
     * @param array<string, string> $itemRefs quote item id => item id the agent sent
     * @param array<int, array<string, mixed>> $pendingMessages
     * @param array<string, mixed>|null $snapshot last response once terminal
     */
    public function __construct(
        public ?int $entityId,
        public string $sessionId,
        public int $quoteId,
        public int $storeId,
        public string $status,
        public string $agentProfile,
        public string $continueToken,
        public array $itemRefs,
        public array $pendingMessages,
        public ?array $snapshot,
        public ?int $orderId,
        public ?string $orderIncrementId,
        public ?string $orderUrl,
        public int $expiresAt,
        public int $createdAt,
        public ?string $destinationId = null
    ) {
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELED;
    }

    public function isExpired(int $now): bool
    {
        return !$this->isTerminal() && $now >= $this->expiresAt;
    }
}
