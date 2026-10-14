<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Magento order → UCP order entity (shopping/order.json, spec 2026-08-25).
 *
 * Always the full current state, as the spec asks for both Get Order and
 * webhooks. Line ids are the checkout's (`li_{quote item id}`), so an agent
 * can match order lines to the checkout it built.
 *
 *  - line status: processing → partial → fulfilled, or removed when every
 *    unit was canceled or refunded;
 *  - fulfillment.expectations: one shipping expectation to the order's
 *    shipping address;
 *  - fulfillment.events: one `shipped` event per shipment, with tracking;
 *  - adjustments: one `refund` per credit memo, `cancellation` when the
 *    order is canceled.
 */
class OrderMapper
{
    public function __construct(
        private readonly TotalsMapper $totalsMapper,
        private readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toEntity(Order $order, Session $session): array
    {
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        [$lines, $lineIdByItem] = $this->lines($order, $session, $currency);

        $entity = [
            'ucp'           => self::envelope(),
            'id'            => (string) $order->getIncrementId(),
            'label'         => 'Order #' . $order->getIncrementId(),
            'checkout_id'   => $session->sessionId,
            'permalink_url' => $session->orderUrl !== null && $session->orderUrl !== ''
                ? $session->orderUrl
                : (string) $order->getStore()->getBaseUrl(),
            'currency'      => $currency,
            'line_items'    => $lines,
            'fulfillment'   => [
                'expectations' => $this->expectations($order, $lines),
                'events'       => $this->shipmentEvents($order, $lineIdByItem),
            ],
            'totals'        => $this->totalsMapper->checkoutTotals(
                $currency,
                (float) $order->getSubtotal(),
                (float) $order->getDiscountAmount(),
                (float) $order->getShippingAmount(),
                (float) $order->getTaxAmount(),
                (float) $order->getGrandTotal(),
                !$order->getIsVirtual()
            ),
        ];

        $adjustments = $this->adjustments($order, $lineIdByItem, $currency);
        if ($adjustments !== []) {
            $entity['adjustments'] = $adjustments;
        }
        return $entity;
    }

    /**
     * `ucp` object of an order response (ucp.json#/$defs/response_order_schema).
     *
     * @return array<string, mixed>
     */
    public static function envelope(string $status = 'success'): array
    {
        return [
            'version'      => UcpConfig::PROTOCOL_VERSION,
            'status'       => $status,
            'capabilities' => ['dev.ucp.shopping.order' => [['version' => UcpConfig::PROTOCOL_VERSION]]],
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     *         [line items, order item id => line id]
     */
    private function lines(Order $order, Session $session, string $currency): array
    {
        $lines = [];
        $lineIdByItem = [];

        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }
            $quoteItemId = (string) $item->getQuoteItemId();
            $lineId = 'li_' . ($quoteItemId !== '' ? $quoteItemId : 'o' . $item->getId());
            $lineIdByItem[(int) $item->getId()] = $lineId;
            foreach ((array) $item->getChildrenItems() as $child) {
                if ($child instanceof OrderItem) {
                    $lineIdByItem[(int) $child->getId()] = $lineId;
                }
            }

            $ordered = (int) $item->getQtyOrdered();
            $total = max(0, $ordered - (int) $item->getQtyCanceled() - (int) $item->getQtyRefunded());
            $shipped = min($total, (int) $item->getQtyShipped());
            $status = match (true) {
                $total === 0                  => 'removed',
                $order->getIsVirtual() || $shipped >= $total => 'fulfilled',
                $shipped > 0                  => 'partial',
                default                       => 'processing',
            };
            if ($order->getIsVirtual() && $total > 0) {
                $shipped = $total;
            }

            $lines[] = [
                'id'       => $lineId,
                'item'     => [
                    'id'    => $session->itemRefs[$quoteItemId] ?? ItemReference::variantGid($this->variantId($item)),
                    'title' => (string) $item->getName(),
                    'price' => max(0, $this->minorUnits->toMinor((float) $item->getPrice(), $currency)),
                ],
                'quantity' => ['original' => $ordered, 'total' => $total, 'fulfilled' => $shipped],
                'totals'   => $this->totalsMapper->lineTotals(
                    $currency,
                    (float) $item->getRowTotal(),
                    (float) $item->getDiscountAmount(),
                    (float) $item->getTaxAmount()
                ),
                'status'   => $status,
            ];
        }
        return [$lines, $lineIdByItem];
    }

    private function variantId(OrderItem $item): int
    {
        foreach ((array) $item->getChildrenItems() as $child) {
            if ($child instanceof OrderItem) {
                return (int) $child->getProductId();
            }
        }
        return (int) $item->getProductId();
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function expectations(Order $order, array $lines): array
    {
        $address = $order->getShippingAddress();
        if ($order->getIsVirtual() || $address === null) {
            return [];
        }
        $open = array_values(array_filter(
            array_map(static fn (array $l): array => ['id' => $l['id'], 'quantity' => $l['quantity']['total']], $lines),
            static fn (array $l): bool => $l['quantity'] > 0
        ));
        if ($open === []) {
            return [];
        }
        $street = array_values(array_filter((array) $address->getStreet(), static fn ($s): bool => (string) $s !== ''));

        return [[
            'id'             => 'exp_1',
            'line_items'     => $open,
            'method_type'    => 'shipping',
            'destination'    => array_filter([
                'street_address'   => (string) ($street[0] ?? ''),
                'extended_address' => (string) ($street[1] ?? ''),
                'address_locality' => (string) $address->getCity(),
                'address_region'   => (string) ($address->getRegionCode() ?: $address->getRegion()),
                'postal_code'      => (string) $address->getPostcode(),
                'address_country'  => (string) $address->getCountryId(),
            ], static fn (string $v): bool => $v !== ''),
        ] + ((string) $order->getShippingDescription() !== ''
            ? ['description' => (string) $order->getShippingDescription()]
            : [])];
    }

    /**
     * @param array<int, string> $lineIdByItem
     * @return array<int, array<string, mixed>>
     */
    private function shipmentEvents(Order $order, array $lineIdByItem): array
    {
        $events = [];
        foreach ($order->getShipmentsCollection() ?: [] as $shipment) {
            $items = [];
            foreach ($shipment->getAllItems() as $shipItem) {
                $lineId = $lineIdByItem[(int) $shipItem->getOrderItemId()] ?? null;
                $qty = (int) $shipItem->getQty();
                if ($lineId !== null && $qty > 0) {
                    $items[$lineId] = ($items[$lineId] ?? 0) + $qty;
                }
            }
            if ($items === []) {
                continue;
            }
            $event = [
                'id'          => 'ship_' . $shipment->getIncrementId(),
                'occurred_at' => self::rfc3339((string) $shipment->getCreatedAt()),
                'type'        => 'shipped',
                'line_items'  => array_map(
                    static fn (string $id, int $qty): array => ['id' => $id, 'quantity' => $qty],
                    array_keys($items),
                    array_values($items)
                ),
            ];
            foreach ($shipment->getAllTracks() as $track) {
                if ((string) $track->getTrackNumber() !== '') {
                    $event['tracking_number'] = (string) $track->getTrackNumber();
                    $event['carrier'] = (string) ($track->getTitle() ?: $track->getCarrierCode());
                    break;
                }
            }
            $events[] = $event;
        }
        return $events;
    }

    /**
     * @param array<int, string> $lineIdByItem
     * @return array<int, array<string, mixed>>
     */
    private function adjustments(Order $order, array $lineIdByItem, string $currency): array
    {
        $adjustments = [];
        foreach ($order->getCreditmemosCollection() ?: [] as $memo) {
            $items = [];
            foreach ($memo->getAllItems() as $memoItem) {
                $lineId = $lineIdByItem[(int) $memoItem->getOrderItemId()] ?? null;
                $qty = (int) $memoItem->getQty();
                if ($lineId !== null && $qty > 0) {
                    $items[$lineId] = ($items[$lineId] ?? 0) - $qty;
                }
            }
            $adjustment = [
                'id'          => 'refund_' . $memo->getIncrementId(),
                'type'        => 'refund',
                'occurred_at' => self::rfc3339((string) $memo->getCreatedAt()),
                'status'      => 'completed',
                'totals'      => [[
                    'type'   => 'total',
                    'amount' => -abs($this->minorUnits->toMinor((float) $memo->getGrandTotal(), $currency)),
                ]],
            ];
            if ($items !== []) {
                $adjustment['line_items'] = array_map(
                    static fn (string $id, int $qty): array => ['id' => $id, 'quantity' => $qty],
                    array_keys($items),
                    array_values($items)
                );
            }
            $adjustments[] = $adjustment;
        }

        if ($order->getState() === Order::STATE_CANCELED) {
            $adjustments[] = [
                'id'          => 'cancel_' . $order->getIncrementId(),
                'type'        => 'cancellation',
                'occurred_at' => self::rfc3339((string) $order->getUpdatedAt()),
                'status'      => 'completed',
            ];
        }
        return $adjustments;
    }

    private static function rfc3339(string $mysqlUtc): string
    {
        $time = $mysqlUtc !== '' ? strtotime($mysqlUtc . ' UTC') : false;
        return gmdate('Y-m-d\TH:i:s\Z', $time !== false ? $time : time());
    }
}
