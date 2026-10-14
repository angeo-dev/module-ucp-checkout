<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller\Checkout;

use Angeo\UcpCheckout\Controller\Router;
use Angeo\UcpCheckout\Model\Config;
use Angeo\UcpCheckout\Model\SessionRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * GET /ucp/checkout/continue/{token} — the continue_url.
 *
 * Puts the agent-built quote into the buyer's own browser session and sends
 * them to the store checkout, where they choose shipping and pay. A
 * signed-in customer gets the items merged into their cart instead, so
 * nothing they already had is lost. The token is long and random; the UCP
 * checkout id alone cannot open a cart.
 */
class Resume implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly SessionRepository $sessions,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $session = $this->sessions->getByContinueToken((string) $this->request->getParam(Router::PARAM_TOKEN, ''));
        if ($session === null || $session->isTerminal() || $session->isExpired(time())) {
            $this->messageManager->addNoticeMessage(__('This checkout link has expired. Your cart is shown below.'));
            return $redirect->setPath('checkout/cart');
        }

        try {
            $quote = $this->cartRepository->get($session->quoteId);
        } catch (NoSuchEntityException) {
            $quote = null;
        }
        if (!$quote instanceof Quote || !$quote->getIsActive()) {
            $this->messageManager->addNoticeMessage(__('This checkout link has expired. Your cart is shown below.'));
            return $redirect->setPath('checkout/cart');
        }

        try {
            if ($this->customerSession->isLoggedIn()) {
                $customerQuote = $this->checkoutSession->getQuote();
                if ((int) $customerQuote->getId() !== (int) $quote->getId()) {
                    $customerQuote->merge($quote);
                    $customerQuote->setTotalsCollectedFlag(false)->collectTotals();
                    $this->cartRepository->save($customerQuote);
                    $quote->setIsActive(false);
                    $this->cartRepository->save($quote);
                    // The order will be placed from the customer's quote.
                    $session->quoteId = (int) $customerQuote->getId();
                    $this->sessions->save($session);
                }
            } else {
                $this->checkoutSession->replaceQuote($quote);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_UcpCheckout] continue_url handoff failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('We could not load this checkout. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setPath($this->config->getHandoffPath());
    }
}
