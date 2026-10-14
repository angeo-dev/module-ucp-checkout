<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Persistence for checkout sessions. Plain SQL through ResourceConnection:
 * the row is a small mapping table, not an entity anyone extends.
 */
class SessionRepository
{
    private const TABLE = 'angeo_ucp_checkout_session';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function newSessionId(): string
    {
        return 'chk_' . bin2hex(random_bytes(16));
    }

    public function newContinueToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function getBySessionId(string $sessionId): ?Session
    {
        if (!preg_match('/^chk_[0-9a-f]{32}$/', $sessionId)) {
            return null;
        }
        return $this->fetchOne('session_id', $sessionId);
    }

    public function getByContinueToken(string $token): ?Session
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        return $this->fetchOne('continue_token', $token);
    }

    /**
     * The completed session that produced this order, if any.
     */
    public function getByOrderId(int $orderId): ?Session
    {
        return $orderId > 0 ? $this->fetchOne('order_id', (string) $orderId) : null;
    }

    public function getByOrderIncrementId(string $incrementId): ?Session
    {
        if ($incrementId === '' || strlen($incrementId) > 50) {
            return null;
        }
        return $this->fetchOne('order_increment_id', $incrementId);
    }

    /**
     * The newest non-terminal session for a quote, if any.
     */
    public function getOpenByQuoteId(int $quoteId): ?Session
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('quote_id = ?', $quoteId)
            ->where('status NOT IN (?)', [Session::STATUS_COMPLETED, Session::STATUS_CANCELED])
            ->order('entity_id DESC')
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ? $this->hydrate($row) : null;
    }

    public function save(Session $session): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $data = [
            'session_id'         => $session->sessionId,
            'quote_id'           => $session->quoteId,
            'store_id'           => $session->storeId,
            'status'             => $session->status,
            'agent_profile'      => mb_substr($session->agentProfile, 0, 512),
            'continue_token'     => $session->continueToken,
            'item_refs'          => (string) json_encode($session->itemRefs),
            'pending_messages'   => (string) json_encode($session->pendingMessages),
            'snapshot'           => $session->snapshot === null ? null : (string) json_encode($session->snapshot),
            'order_id'           => $session->orderId,
            'order_increment_id' => $session->orderIncrementId,
            'order_url'          => $session->orderUrl,
            'destination_id'     => $session->destinationId,
            'expires_at'         => gmdate('Y-m-d H:i:s', $session->expiresAt),
        ];

        if ($session->entityId === null) {
            $data['created_at'] = gmdate('Y-m-d H:i:s', $session->createdAt);
            $connection->insert($table, $data);
            // AdapterInterface has no lastInsertId(); session_id is unique.
            $session->entityId = (int) $connection->fetchOne(
                $connection->select()->from($table, ['entity_id'])->where('session_id = ?', $session->sessionId)
            );
            return;
        }
        $connection->update($table, $data, ['entity_id = ?' => $session->entityId]);
    }

    /**
     * Delete terminal or expired rows older than the cutoff.
     */
    public function deleteOlderThan(int $cutoff): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['expires_at < ?' => gmdate('Y-m-d H:i:s', $cutoff)]
        );
    }

    private function fetchOne(string $column, string $value): ?Session
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where($column . ' = ?', $value)
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Session
    {
        $decode = static function (mixed $json): array {
            $value = is_string($json) && $json !== '' ? json_decode($json, true) : null;
            return is_array($value) ? $value : [];
        };
        $snapshot = isset($row['snapshot']) && is_string($row['snapshot']) && $row['snapshot'] !== ''
            ? json_decode($row['snapshot'], true)
            : null;

        return new Session(
            (int) $row['entity_id'],
            (string) $row['session_id'],
            (int) $row['quote_id'],
            (int) $row['store_id'],
            (string) $row['status'],
            (string) ($row['agent_profile'] ?? ''),
            (string) $row['continue_token'],
            array_map('strval', $decode($row['item_refs'] ?? null)),
            $decode($row['pending_messages'] ?? null),
            is_array($snapshot) ? $snapshot : null,
            isset($row['order_id']) ? (int) $row['order_id'] : null,
            isset($row['order_increment_id']) ? (string) $row['order_increment_id'] : null,
            isset($row['order_url']) ? (string) $row['order_url'] : null,
            (int) strtotime($row['expires_at'] . ' UTC'),
            (int) strtotime($row['created_at'] . ' UTC'),
            isset($row['destination_id']) ? (string) $row['destination_id'] : null
        );
    }
}
