<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Observer;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCheckout\Model\SessionRepository;
use Angeo\UcpCheckout\Model\WebhookQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Shipment, credit memo or cancellation on an order that came from a UCP
 * checkout: queue an "order updated" webhook. The sender builds the full
 * current order when it delivers.
 */
class OrderChanged implements ObserverInterface
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly WebhookQueue $webhooks,
        private readonly UcpConfig $ucpConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->ucpConfig->isOrderDeclared()) {
                return;
            }
            $event = $observer->getEvent();
            $order = $event->getData('order');
            foreach (['shipment', 'creditmemo'] as $key) {
                $source = $event->getData($key);
                if ($order === null && is_object($source) && method_exists($source, 'getOrder')) {
                    $order = $source->getOrder();
                }
            }
            if (!$order instanceof Order) {
                return;
            }
            $session = $this->sessions->getByOrderId((int) $order->getId());
            if ($session !== null) {
                $this->webhooks->enqueue((int) $order->getId(), $session->sessionId, 'order.updated');
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_UcpCheckout] Could not queue order webhook: ' . $e->getMessage());
        }
    }
}
