<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * angeo_ucp_order_webhook: one row per order event to deliver.
 *
 * The payload is built when the row is sent, not when it is queued, so
 * every delivery carries the full current order (spec: full entity, no
 * deltas). Retries back off 1 min → 5 min → 30 min → 2 h → 12 h, then give up.
 */
class WebhookQueue
{
    private const TABLE = 'angeo_ucp_order_webhook';
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function enqueue(int $orderId, string $sessionId, string $event): void
    {
        $this->resource->getConnection()->insert($this->table(), [
            'event_id'        => 'evt_' . bin2hex(random_bytes(12)),
            'order_id'        => $orderId,
            'session_id'      => $sessionId,
            'event'           => mb_substr($event, 0, 32),
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->table())
                ->where('status = ?', self::STATUS_PENDING)
                ->where('next_attempt_at <= ?', gmdate('Y-m-d H:i:s'))
                ->order('entity_id ASC')
                ->limit($limit)
        );
    }

    public function markSent(int $id, int $httpStatus): void
    {
        $this->update($id, ['status' => self::STATUS_SENT, 'last_status' => $httpStatus, 'last_error' => null]);
    }

    /**
     * Schedule the next try, or give up after the last back-off step.
     */
    public function markFailed(int $id, int $attempts, int $httpStatus, string $error): void
    {
        $data = ['attempts' => $attempts, 'last_status' => $httpStatus, 'last_error' => mb_substr($error, 0, 255)];
        if ($attempts > count(self::BACKOFF)) {
            $data['status'] = self::STATUS_FAILED;
        } else {
            $data['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]);
        }
        $this->update($id, $data);
    }

    public function deleteOlderThan(int $cutoff): int
    {
        return $this->resource->getConnection()->delete(
            $this->table(),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', $cutoff), 'status <> ?' => self::STATUS_PENDING]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(int $id, array $data): void
    {
        $this->resource->getConnection()->update($this->table(), $data, ['entity_id = ?' => $id]);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
