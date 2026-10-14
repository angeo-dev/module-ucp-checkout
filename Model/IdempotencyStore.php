<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Idempotency-Key handling (UCP REST binding): the result of a mutating
 * request is kept for at least 24 hours; the same key with the same body
 * replays it byte for byte, the same key with a different body is 409.
 */
class IdempotencyStore
{
    private const TABLE = 'angeo_ucp_checkout_idempotency';
    public const MAX_KEY_LENGTH = 255;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array{hash: string, status: int, body: string}|null
     */
    public function find(string $operation, string $key, string $sessionId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['request_hash', 'http_status', 'response_body'])
                ->where('operation = ?', $operation)
                ->where('idempotency_key = ?', $key)
                ->where('session_id = ?', $sessionId)
                ->limit(1)
        );
        if (!$row) {
            return null;
        }
        return [
            'hash'   => (string) $row['request_hash'],
            'status' => (int) $row['http_status'],
            'body'   => (string) $row['response_body'],
        ];
    }

    public function save(
        string $operation,
        string $key,
        string $sessionId,
        string $hash,
        int $status,
        string $body
    ): void {
        try {
            $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
                'operation'       => $operation,
                'idempotency_key' => $key,
                'session_id'      => $sessionId,
                'request_hash'    => $hash,
                'http_status'     => $status,
                'response_body'   => $body,
            ]);
        } catch (DuplicateException) {
            // A concurrent request with the same key won the race. Its stored
            // result is what later replays return; ours is still correct.
        }
    }

    public function deleteOlderThan(int $cutoff): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', $cutoff)]
        );
    }
}
