<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller\Checkout;

use Angeo\UcpCheckout\Controller\Router;
use Angeo\UcpCheckout\Model\CheckoutResult;
use Angeo\UcpCheckout\Model\Messages;
use Angeo\UcpCheckout\Model\OrderMapper;
use Angeo\UcpCheckout\Model\SessionRepository;
use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\CheckoutService;
use Angeo\UcpCheckout\Model\IdempotencyStore;
use Angeo\UcpCheckout\Model\RequestHash;
use Angeo\UcpCheckout\Model\VersionNegotiator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Psr\Log\LoggerInterface;

/**
 * GET {endpoint}/orders/{id} — Get Order (dev.ucp.shopping.order).
 *
 * {id} is the order number. Access (spec "platform credentials"): only the
 * platform whose UCP-Agent profile created the checkout gets the order.
 * Anything else answers "not found", so order numbers cannot be probed.
 * Turn on Inbound Signature Mode = required so the profile is proven, not
 * just claimed.
 */
class Order extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(
        ResultFactory $resultFactory,
        RequestInterface $request,
        UcpConfig $ucpConfig,
        SignatureVerifierInterface $signatureVerifier,
        VersionNegotiator $versionNegotiator,
        IdempotencyStore $idempotency,
        RequestHash $requestHash,
        Messages $messages,
        CheckoutService $checkout,
        LoggerInterface $logger,
        private readonly SessionRepository $sessions,
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderMapper $mapper
    ) {
        parent::__construct(
            $resultFactory,
            $request,
            $ucpConfig,
            $signatureVerifier,
            $versionNegotiator,
            $idempotency,
            $requestHash,
            $messages,
            $checkout,
            $logger
        );
    }

    protected function operation(): ?string
    {
        return null;
    }

    protected function capabilityDeclared(): bool
    {
        return $this->ucpConfig->isOrderDeclared();
    }

    protected function process(array $body, string $agentProfile): CheckoutResult
    {
        $notFound = new CheckoutResult(404, [
            'ucp'      => OrderMapper::envelope('error'),
            'messages' => [$this->messages->error('not_found', 'Order not found.', Messages::SEVERITY_UNRECOVERABLE)],
        ]);

        $session = $this->sessions->getByOrderIncrementId((string) $this->request->getParam(Router::PARAM_ORDER, ''));
        if ($session === null || $session->orderId === null
            || $agentProfile === '' || !hash_equals($session->agentProfile, $agentProfile)
        ) {
            return $notFound;
        }

        try {
            $order = $this->orders->get($session->orderId);
        } catch (NoSuchEntityException) {
            return $notFound;
        }
        if (!$order instanceof SalesOrder) {
            return $notFound;
        }
        return new CheckoutResult(200, $this->mapper->toEntity($order, $session));
    }
}
