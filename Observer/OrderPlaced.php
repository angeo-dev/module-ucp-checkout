<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Observer;

use Angeo\UcpCheckout\Model\Session;
use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\SessionRepository;
use Angeo\UcpCheckout\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * checkout_submit_all_after: the buyer placed the order on the store
 * checkout. Marks the UCP session completed and records the order, so the
 * agent's next Get Checkout returns status "completed" with `order`.
 *
 * Never throws: the order is already placed, and a failure here must not
 * turn the buyer's success page into an error.
 */
class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly WebhookQueue $webhooks,
        private readonly UcpConfig $ucpConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $observer->getEvent()->getData('quote');
            $order = $observer->getEvent()->getData('order');
            if (!$quote instanceof Quote || !$order instanceof Order) {
                return;
            }
            $session = $this->sessions->getOpenByQuoteId((int) $quote->getId());
            if ($session === null) {
                return;
            }

            $store = $this->storeManager->getStore((int) $order->getStoreId());
            $session->status = Session::STATUS_COMPLETED;
            $session->orderId = (int) $order->getId();
            $session->orderIncrementId = (string) $order->getIncrementId();
            $session->orderUrl = $order->getCustomerIsGuest()
                ? $store->getUrl('sales/guest/form')
                : $store->getUrl('sales/order/view', ['order_id' => (int) $order->getId()]);
            $session->snapshot = null;
            $session->pendingMessages = [];
            $this->sessions->save($session);
            if ($this->ucpConfig->isOrderDeclared()) {
                // Spec: MUST send "order created" with the full entity.
                $this->webhooks->enqueue((int) $order->getId(), $session->sessionId, 'order.created');
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_UcpCheckout] Could not mark UCP session completed: ' . $e->getMessage());
        }
    }
}
