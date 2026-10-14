<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\Option as QuoteItemOption;

/**
 * Quote + session row → UCP checkout resource (shopping/checkout.json, with
 * the discount extension when it is declared).
 */
class CheckoutMapper
{
    public function __construct(
        private readonly UcpConfig $ucpConfig,
        private readonly Config $config,
        private readonly TotalsMapper $totalsMapper,
        private readonly MinorUnits $minorUnits,
        private readonly Fulfillment $fulfillment
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    public function toResource(
        Session $session,
        Quote $quote,
        string $status,
        array $messages,
        string $continueUrl
    ): array {
        $currency = strtoupper((string) ($quote->getQuoteCurrencyCode() ?: $quote->getStore()->getCurrentCurrencyCode()));
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        $discount = (float) $quote->getSubtotal() - (float) $quote->getSubtotalWithDiscount();

        $resource = [
            'ucp'        => $this->envelope(),
            'id'         => $session->sessionId,
            'status'     => $status,
            'currency'   => $currency,
            'line_items' => $this->lineItems($quote, $session, $currency),
            'totals'     => $this->totalsMapper->checkoutTotals(
                $currency,
                (float) $quote->getSubtotal(),
                $discount,
                (float) $address->getShippingAmount(),
                (float) $address->getTaxAmount(),
                (float) $quote->getGrandTotal(),
                !$quote->isVirtual() && (string) $address->getShippingMethod() !== ''
            ),
            'links'      => $this->links(),
            'payment'    => ['instruments' => []],
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $session->expiresAt),
        ];

        $buyer = $this->buyer($quote);
        if ($buyer !== []) {
            $resource['buyer'] = $buyer;
        }

        if ($this->ucpConfig->isFulfillmentDeclared() && !$quote->isVirtual()) {
            $resource['fulfillment'] = $this->fulfillment->toResponse(
                $quote,
                $session->destinationId,
                array_values(array_map(
                    static fn (array $line): string => (string) $line['id'],
                    $resource['line_items']
                )),
                $currency
            );
        }

        if ($this->ucpConfig->isDiscountDeclared()) {
            $resource['discounts'] = $this->discounts($quote, $currency, $discount);
        }

        if ($messages !== []) {
            $resource['messages'] = array_values($messages);
        }

        if ($status === Session::STATUS_COMPLETED && $session->orderIncrementId !== null) {
            // order_confirmation.json REQUIRES permalink_url.
            $resource['order'] = [
                'id'            => $session->orderIncrementId,
                'label'         => 'Order #' . $session->orderIncrementId,
                'permalink_url' => $session->orderUrl !== null && $session->orderUrl !== ''
                    ? $session->orderUrl
                    : (string) $quote->getStore()->getBaseUrl(),
            ];
        }

        // Spec: MUST for requires_escalation, SHOULD for other non-terminal
        // states, SHOULD be omitted once terminal.
        if ($status !== Session::STATUS_COMPLETED && $status !== Session::STATUS_CANCELED) {
            $resource['continue_url'] = $continueUrl;
        } else {
            unset($resource['expires_at']);
        }

        return $resource;
    }

    /**
     * The `ucp` object of a checkout response (ucp.json#/$defs/response_checkout_schema).
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        $version = UcpConfig::PROTOCOL_VERSION;
        $capabilities = ['dev.ucp.shopping.checkout' => [['version' => $version]]];
        if ($this->ucpConfig->isDiscountDeclared()) {
            $capabilities['dev.ucp.shopping.discount'] = [['version' => $version]];
        }
        if ($this->ucpConfig->isFulfillmentDeclared()) {
            $capabilities['dev.ucp.shopping.fulfillment'] = [['version' => $version]];
        }
        $handlers = $this->ucpConfig->getPaymentHandlers();

        return [
            'version'          => $version,
            'status'           => 'success',
            'capabilities'     => $capabilities,
            'payment_handlers' => $handlers === [] ? new \stdClass() : $handlers,
        ];
    }

    /**
     * Variant id of a quote item: the child for configurables.
     */
    public static function variantId(QuoteItem $item): int
    {
        // getOptionByCode() is declared to return OptionInterface, which has
        // no getProduct(); the quote item really returns a Quote\Item\Option.
        $option = $item->getOptionByCode('simple_product');
        if ($option instanceof QuoteItemOption) {
            $product = $option->getProduct();
            if ($product !== null) {
                return (int) $product->getId();
            }
        }
        return (int) $item->getProductId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineItems(Quote $quote, Session $session, string $currency): array
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $itemId = (string) $item->getId();
            $unit = (float) ($item->getCalculationPrice() ?? $item->getPrice());
            $lines[] = [
                'id'       => 'li_' . $itemId,
                'item'     => [
                    'id'    => $session->itemRefs[$itemId] ?? ItemReference::variantGid(self::variantId($item)),
                    'title' => (string) $item->getName(),
                    'price' => max(0, $this->minorUnits->toMinor($unit, $currency)),
                ],
                'quantity' => max(1, (int) $item->getQty()),
                'totals'   => $this->totalsMapper->lineTotals(
                    $currency,
                    (float) $item->getRowTotal(),
                    (float) $item->getDiscountAmount(),
                    (float) $item->getTaxAmount()
                ),
            ];
        }
        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function buyer(Quote $quote): array
    {
        $billing = $quote->getBillingAddress();
        $buyer = array_filter([
            'email'        => (string) $quote->getCustomerEmail(),
            'first_name'   => (string) ($quote->getCustomerFirstname() ?: $billing->getFirstname()),
            'last_name'    => (string) ($quote->getCustomerLastname() ?: $billing->getLastname()),
            'phone_number' => (string) $billing->getTelephone(),
        ], static fn (string $v): bool => $v !== '');
        return $buyer;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function links(): array
    {
        $links = [];
        foreach ($this->config->getLinks() as $type => $url) {
            $links[] = ['type' => $type, 'url' => $url];
        }
        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function discounts(Quote $quote, string $currency, float $discount): array
    {
        $code = (string) $quote->getCouponCode();
        $amount = abs($this->minorUnits->toMinor($discount, $currency));
        $out = ['codes' => $code !== '' ? [$code] : []];

        if ($amount > 0) {
            $out['applied'] = [$code !== ''
                ? ['code' => $code, 'title' => 'Discount code ' . $code, 'amount' => $amount]
                : ['title' => 'Automatic discount', 'automatic' => true, 'amount' => $amount]];
        } else {
            $out['applied'] = [];
        }
        return $out;
    }
}
