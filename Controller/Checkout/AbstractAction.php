<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller\Checkout;

use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Controller\Router;
use Angeo\UcpCheckout\Model\CheckoutResult;
use Angeo\UcpCheckout\Model\CheckoutService;
use Angeo\UcpCheckout\Model\IdempotencyStore;
use Angeo\UcpCheckout\Model\Messages;
use Angeo\UcpCheckout\Model\RequestHash;
use Angeo\UcpCheckout\Model\VersionNegotiator;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for the checkout REST actions, in this order:
 *
 *  1. 404 unless Angeo_Ucp is on and checkout is declared in the profile —
 *     serving an undeclared capability contradicts the profile;
 *  2. inbound RFC 9421 signature policy (same rules as Angeo_UcpCatalog);
 *  3. UCP-Agent version negotiation: 422 for a version we do not serve;
 *  4. body: JSON object, at most 256 KB;
 *  5. Idempotency-Key: replay on the same body, 409 on a different body;
 *  6. the operation itself; JSON out with Cache-Control: no-store.
 *
 * Form-key CSRF is bypassed on purpose (CsrfAwareActionInterface): callers
 * are agents authenticated by signature, not browser sessions.
 */
abstract class AbstractAction implements CsrfAwareActionInterface
{
    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        protected readonly ResultFactory $resultFactory,
        protected readonly RequestInterface $request,
        protected readonly UcpConfig $ucpConfig,
        protected readonly SignatureVerifierInterface $signatureVerifier,
        protected readonly VersionNegotiator $versionNegotiator,
        protected readonly IdempotencyStore $idempotency,
        protected readonly RequestHash $requestHash,
        protected readonly Messages $messages,
        protected readonly CheckoutService $checkout,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Operation name for idempotency; null for read-only operations.
     */
    abstract protected function operation(): ?string;

    /**
     * @param array<string, mixed> $body
     */
    abstract protected function process(array $body, string $agentProfile): CheckoutResult;

    public function execute(): ResultInterface
    {
        if (!$this->ucpConfig->isEnabled() || !$this->ucpConfig->isCheckoutDeclared()) {
            return $this->error(404, 'not_found', 'This site does not advertise UCP checkout.');
        }
        if (!$this->request instanceof HttpRequest) {
            return $this->error(400, 'invalid', 'Unsupported request.');
        }

        $denied = $this->enforceSignaturePolicy($this->request);
        if ($denied !== null) {
            return $denied;
        }

        $agent = $this->versionNegotiator->parseAgentHeader((string) $this->request->getHeader('UCP-Agent'));
        if (!$this->versionNegotiator->isSupported($agent['version'])) {
            return $this->error(422, 'version_unsupported', sprintf(
                'UCP version %s is not supported. Supported: %s.',
                $agent['version'],
                implode(', ', $this->versionNegotiator->supportedVersions())
            ));
        }

        $raw = (string) $this->request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->error(413, 'invalid', 'Request body is too large.');
        }
        $body = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                return $this->error(400, 'invalid', 'Request body must be a JSON object.');
            }
            $body = $decoded;
        }

        $operation = $this->operation();
        $key = trim((string) $this->request->getHeader('Idempotency-Key'));
        $sessionId = (string) $this->request->getParam(Router::PARAM_ID, '');
        $hash = '';
        if ($operation !== null && $key !== '') {
            if (strlen($key) > IdempotencyStore::MAX_KEY_LENGTH) {
                return $this->error(400, 'invalid', 'Idempotency-Key is too long.');
            }
            $hash = $this->requestHash->hash($this->request->getMethod(), $sessionId, $raw);
            $stored = $this->idempotency->find($operation, $key, $sessionId);
            if ($stored !== null) {
                if (!hash_equals($stored['hash'], $hash)) {
                    return $this->error(409, 'idempotency_key_reused', 'This Idempotency-Key was used with a different request.');
                }
                return $this->raw($stored['status'], $stored['body']);
            }
        }

        try {
            $result = $this->process($body, $agent['profile']);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_UcpCheckout] ' . static::class . ' failed: ' . $e->getMessage());
            return $this->error(500, 'internal_error', 'Internal error while processing the request.');
        }

        $json = $this->encode($result->body);
        if ($operation !== null && $key !== '' && $result->status < 500) {
            $this->idempotency->save($operation, $key, $sessionId, $hash, $result->status, $json);
        }
        return $this->raw($result->status, $json);
    }

    protected function checkoutId(): string
    {
        return (string) $this->request->getParam(Router::PARAM_ID, '');
    }

    private function enforceSignaturePolicy(HttpRequest $request): ?ResultInterface
    {
        $mode = $this->ucpConfig->getInboundSignatureMode();
        if ($mode === UcpConfig::SIGNATURE_MODE_DISABLED) {
            return null;
        }
        $result = $this->signatureVerifier->verify($request);
        if ($result->isVerified()) {
            return null;
        }
        if ($result->isUnsigned() && $mode === UcpConfig::SIGNATURE_MODE_OPTIONAL) {
            return null;
        }
        $this->logger->info(sprintf(
            '[Angeo_UcpCheckout] Rejected a %s request in "%s" mode: %s',
            $result->status,
            $mode,
            $result->reason !== '' ? $result->reason : 'no signature present'
        ));
        return $this->error(
            401,
            'unauthorized',
            $result->isUnsigned()
                ? 'This endpoint requires a signed request (RFC 9421).'
                : 'Request signature could not be verified.'
        );
    }

    private function error(int $status, string $code, string $content): ResultInterface
    {
        return $this->raw($status, $this->encode($this->messages->errorBody(
            UcpConfig::PROTOCOL_VERSION,
            [$this->messages->error($code, $content, Messages::SEVERITY_UNRECOVERABLE)]
        )));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function raw(int $status, string $json): ResultInterface
    {
        return $this->resultFactory
            ->create(ResultFactory::TYPE_RAW)
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('Access-Control-Allow-Origin', '*', true)
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('X-UCP-Version', UcpConfig::PROTOCOL_VERSION, true)
            ->setContents($json);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
