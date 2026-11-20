<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Setup\Patch\Data;

use Angeo\UcpCheckout\Model\ContinueToken;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * 1.1.1: the session table no longer keeps continue_url tokens in plain text.
 *
 * Every row gets the hash of its new, derived token. A continue_url handed
 * out before the upgrade stops working; the agent receives the new one in
 * its next checkout response. Sessions live 6 hours by default, so few
 * links are affected.
 */
class HashContinueTokens implements DataPatchInterface
{
    private const TABLE = 'angeo_ucp_checkout_session';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ContinueToken $continueToken
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $rows = $connection->fetchPairs($connection->select()->from($table, ['entity_id', 'session_id']));

        foreach ($rows as $entityId => $sessionId) {
            $connection->update(
                $table,
                ['continue_token' => ContinueToken::storageHash($this->continueToken->forSession((string) $sessionId))],
                ['entity_id = ?' => (int) $entityId]
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
