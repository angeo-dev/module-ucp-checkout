<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Controller;

use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\Ucp\Model\Signature\VerificationResult;
use Angeo\UcpCheckout\Controller\Checkout\Create;
use Angeo\UcpCheckout\Controller\Checkout\Session as SessionAction;
use Angeo\UcpCheckout\Controller\Router;
use Angeo\UcpCheckout\Model\CheckoutResult;
use Angeo\UcpCheckout\Model\CheckoutService;
use Angeo\UcpCheckout\Model\IdempotencyStore;
use Angeo\UcpCheckout\Model\Messages;
use Angeo\UcpCheckout\Model\RequestHash;
use Angeo\UcpCheckout\Model\VersionNegotiator;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The request rules every checkout action applies before the operation.
 */
class RequestPolicyTest extends TestCase
{
    private const PROFILE = 'https://platform.example/.well-known/ucp';

    public function testUcpAgentWithoutAProfileIs400(): void
    {
        $result = $this->dispatch(Create::class, ['Idempotency-Key' => 'k'], '{"line_items":[]}');

        self::assertSame(400, $result->httpCode);
        self::assertStringContainsString('UCP-Agent', $result->json()['messages'][0]['content']);
    }

    public function testTheConformanceSuiteHeaderShapeIsAccepted(): void
    {
        $checkout = $this->createMock(CheckoutService::class);
        $checkout->expects(self::once())->method('create')
            ->with(self::anything(), '...')
            ->willReturn(new CheckoutResult(201, ['id' => 'chk_x']));

        $result = $this->dispatch(Create::class, ['UCP-Agent' => 'profile="..."; version="2026-08-25"'], '{}', checkout: $checkout);

        self::assertSame(201, $result->httpCode);
    }

    public function testAFutureVersionIs422(): void
    {
        $result = $this->dispatch(Create::class, ['UCP-Agent' => 'profile="..."; version="2099-01-01"'], '{}');

        self::assertSame(422, $result->httpCode);
    }

    public function testGetPassesTheCallingProfileToTheService(): void
    {
        $checkout = $this->createMock(CheckoutService::class);
        $checkout->expects(self::once())->method('get')
            ->with('chk_1', self::PROFILE)
            ->willReturn(new CheckoutResult(404, ['messages' => [['code' => 'not_found']]]));

        $result = $this->dispatch(
            SessionAction::class,
            ['UCP-Agent' => 'profile="' . self::PROFILE . '"'],
            '',
            'GET',
            $checkout,
            checkoutId: 'chk_1'
        );

        self::assertSame(404, $result->httpCode);
    }

    public function testIdempotencyStoreFailureFailsClosedWith503(): void
    {
        $store = $this->createMock(IdempotencyStore::class);
        $store->method('find')->willThrowException(new \RuntimeException('db down'));
        $checkout = $this->createMock(CheckoutService::class);
        $checkout->expects(self::never())->method('create');

        $result = $this->dispatch(
            Create::class,
            ['UCP-Agent' => 'profile="' . self::PROFILE . '"', 'Idempotency-Key' => 'k1'],
            '{}',
            checkout: $checkout,
            idempotency: $store
        );

        self::assertSame(503, $result->httpCode);
    }

    public function testIdempotencyKeysAreLookedUpPerProfile(): void
    {
        $store = $this->createMock(IdempotencyStore::class);
        $store->expects(self::once())->method('find')
            ->with('create', IdempotencyStore::scopedKey(self::PROFILE, 'k1'), '')
            ->willReturn(null);

        $this->dispatch(Create::class, ['UCP-Agent' => 'profile="' . self::PROFILE . '"', 'Idempotency-Key' => 'k1'], '{}', idempotency: $store);
    }

    public function testSignatureFailuresUseTheSpecCodes(): void
    {
        foreach ([
            [VerificationResult::failed('x', VerificationResult::CODE_KEY_NOT_FOUND), 401, 'key_not_found'],
            [VerificationResult::failed('x', VerificationResult::CODE_DIGEST_MISMATCH), 400, 'digest_mismatch'],
            [VerificationResult::failed('x'), 401, 'signature_invalid'],
        ] as [$verification, $status, $code]) {
            $result = $this->dispatch(
                Create::class,
                ['UCP-Agent' => 'profile="' . self::PROFILE . '"'],
                '{}',
                mode: UcpConfig::SIGNATURE_MODE_OPTIONAL,
                verification: $verification
            );

            self::assertSame($status, $result->httpCode, $code);
            self::assertSame($code, $result->json()['messages'][0]['code'], $code);
        }
    }

    public function testRequiredModeRejectsUnsignedWithSignatureMissing(): void
    {
        $result = $this->dispatch(
            Create::class,
            ['UCP-Agent' => 'profile="' . self::PROFILE . '"'],
            '{}',
            mode: UcpConfig::SIGNATURE_MODE_REQUIRED,
            verification: VerificationResult::unsigned()
        );

        self::assertSame(401, $result->httpCode);
        self::assertSame('signature_missing', $result->json()['messages'][0]['code']);
    }

    public function testAVerifiedSignatureNamesTheOwner(): void
    {
        $checkout = $this->createMock(CheckoutService::class);
        $checkout->expects(self::once())->method('create')
            ->with(self::anything(), 'https://signer.example/.well-known/ucp')
            ->willReturn(new CheckoutResult(201, []));

        $this->dispatch(
            Create::class,
            ['UCP-Agent' => 'profile="' . self::PROFILE . '"'],
            '{}',
            checkout: $checkout,
            mode: UcpConfig::SIGNATURE_MODE_REQUIRED,
            verification: VerificationResult::verified('https://signer.example/.well-known/ucp', 'k')
        );
    }

    /**
     * @param class-string $action
     * @param array<string, string> $headers
     */
    private function dispatch(
        string $action,
        array $headers,
        string $body,
        string $method = 'POST',
        ?CheckoutService $checkout = null,
        ?IdempotencyStore $idempotency = null,
        string $mode = UcpConfig::SIGNATURE_MODE_DISABLED,
        ?VerificationResult $verification = null,
        string $checkoutId = ''
    ): ResultSpy {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(static function (string $name) use ($headers) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
            return false;
        });
        $request->method('getContent')->willReturn($body);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $name === Router::PARAM_ID ? $checkoutId : $default
        );

        $ucpConfig = $this->createMock(UcpConfig::class);
        $ucpConfig->method('isEnabled')->willReturn(true);
        $ucpConfig->method('isCheckoutDeclared')->willReturn(true);
        $ucpConfig->method('getInboundSignatureMode')->willReturn($mode);
        $ucpConfig->method('getSupportedVersions')->willReturn([]);

        $verifier = $this->createMock(SignatureVerifierInterface::class);
        $verifier->method('verify')->willReturn($verification ?? VerificationResult::unsigned());

        $spy = new ResultSpy();
        $factory = $this->createMock(ResultFactory::class);
        $factory->method('create')->willReturn($spy);

        if ($checkout === null) {
            $checkout = $this->createMock(CheckoutService::class);
            $checkout->method('create')->willReturn(new CheckoutResult(201, []));
        }
        if ($idempotency === null) {
            $idempotency = $this->createMock(IdempotencyStore::class);
        }

        $controller = new $action(
            $factory,
            $request,
            $ucpConfig,
            $verifier,
            new VersionNegotiator($ucpConfig),
            $idempotency,
            new RequestHash(),
            new Messages(),
            $checkout,
            $this->createMock(LoggerInterface::class)
        );

        return $controller->execute();
    }
}
