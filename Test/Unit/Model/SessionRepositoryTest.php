<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\ContinueToken;
use Angeo\UcpCheckout\Model\Session;
use Angeo\UcpCheckout\Model\SessionRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * The table keeps the hash of the continue token, never the token itself.
 */
class SessionRepositoryTest extends TestCase
{
    private const ID = 'chk_0123456789abcdef0123456789abcdef';

    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var list<array{0: string, 1: mixed}> */
    private array $wheres = [];

    private SessionRepository $repository;

    private ContinueToken $token;

    protected function setUp(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(
            static fn (string $data): string => hash_hmac('sha256', $data, 'crypt-key')
        );
        $this->token = new ContinueToken($encryptor);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(function (string $cond, mixed $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->stored = $data + ['entity_id' => 7];
            return 1;
        });
        $connection->method('fetchOne')->willReturn('7');
        $connection->method('fetchRow')->willReturnCallback(fn () => $this->stored ?: false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->repository = new SessionRepository($resource, $this->token);
    }

    private function session(): Session
    {
        return new Session(
            null,
            self::ID,
            5,
            1,
            Session::STATUS_INCOMPLETE,
            '',
            $this->repository->newContinueToken(self::ID),
            [],
            [],
            null,
            null,
            null,
            null,
            time() + 3600,
            time()
        );
    }

    public function testSaveStoresOnlyTheHash(): void
    {
        $session = $this->session();
        $this->repository->save($session);

        self::assertSame(ContinueToken::storageHash($session->continueToken), $this->stored['continue_token']);
        self::assertNotContains($session->continueToken, $this->stored);
    }

    public function testLookupUsesTheHashAndRestoresTheToken(): void
    {
        $session = $this->session();
        $this->repository->save($session);

        $found = $this->repository->getByContinueToken($session->continueToken);

        self::assertNotNull($found);
        self::assertSame(['continue_token = ?', ContinueToken::storageHash($session->continueToken)], end($this->wheres));
        self::assertSame($session->continueToken, $found->continueToken);
    }

    public function testMalformedTokenIsNotLookedUp(): void
    {
        self::assertNull($this->repository->getByContinueToken('not-a-token'));
        self::assertSame([], $this->wheres);
    }
}
