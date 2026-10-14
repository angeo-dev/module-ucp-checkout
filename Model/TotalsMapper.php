<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Builds UCP totals (common/types/totals.json) from Magento amounts.
 *
 * Invariants the schema and the spec enforce, and this class guarantees:
 *  - exactly one `subtotal` and exactly one `total`;
 *  - `discount` is negative, `subtotal`/`fulfillment`/`tax`/`fee` are >= 0;
 *  - the parts add up to `total` exactly, in minor units.
 *
 * Magento's grand total can differ from subtotal + discount + shipping + tax
 * (discount tax compensation, weee, gift wrapping, rounding per row). The
 * difference is never hidden: a positive gap becomes a `fee` line, a
 * negative gap a `discount` adjustment, so `total` always equals what the
 * buyer will pay on the store checkout.
 */
class TotalsMapper
{
    public function __construct(
        private readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * @param float $subtotal  excl. tax, before discounts (major units)
     * @param float $discount  discount amount; sign ignored (Magento stores it negative)
     * @param float $shipping  shipping amount excl. tax
     * @param float $tax       tax amount
     * @param float $grandTotal what the buyer pays
     * @param bool  $hasShipping include a fulfillment line even when it is 0
     * @return array<int, array<string, mixed>>
     */
    public function checkoutTotals(
        string $currency,
        float $subtotal,
        float $discount,
        float $shipping,
        float $tax,
        float $grandTotal,
        bool $hasShipping = false
    ): array {
        $m = fn (float $v): int => $this->minorUnits->toMinor($v, $currency);

        $parts = [
            'subtotal'    => max(0, $m($subtotal)),
            'discount'    => -abs($m($discount)),
            'fulfillment' => max(0, $m($shipping)),
            'tax'         => max(0, $m($tax)),
        ];
        $total = max(0, $m($grandTotal));

        $gap = $total - array_sum($parts);
        $fee = 0;
        if ($gap > 0) {
            $fee = $gap;
        } elseif ($gap < 0) {
            $parts['discount'] += $gap;
        }

        $totals = [['type' => 'subtotal', 'amount' => $parts['subtotal']]];
        if ($parts['discount'] < 0) {
            $totals[] = ['type' => 'discount', 'display_text' => 'Discount', 'amount' => $parts['discount']];
        }
        if ($hasShipping || $parts['fulfillment'] > 0) {
            $totals[] = ['type' => 'fulfillment', 'display_text' => 'Shipping', 'amount' => $parts['fulfillment']];
        }
        if ($parts['tax'] > 0) {
            $totals[] = ['type' => 'tax', 'display_text' => 'Tax', 'amount' => $parts['tax']];
        }
        if ($fee > 0) {
            $totals[] = ['type' => 'fee', 'display_text' => 'Other charges', 'amount' => $fee];
        }
        $totals[] = ['type' => 'total', 'amount' => $total];

        return $totals;
    }

    /**
     * Totals for one line item: subtotal, optional discount and tax, total.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lineTotals(string $currency, float $rowTotal, float $discount, float $tax): array
    {
        $subtotal = max(0, $this->minorUnits->toMinor($rowTotal, $currency));
        $disc = -abs($this->minorUnits->toMinor($discount, $currency));
        $taxM = max(0, $this->minorUnits->toMinor($tax, $currency));

        $lines = [['type' => 'subtotal', 'amount' => $subtotal]];
        if ($disc < 0) {
            $lines[] = ['type' => 'discount', 'display_text' => 'Discount', 'amount' => $disc];
        }
        if ($taxM > 0) {
            $lines[] = ['type' => 'tax', 'display_text' => 'Tax', 'amount' => $taxM];
        }
        $lines[] = ['type' => 'total', 'amount' => max(0, $subtotal + $disc + $taxM)];

        return $lines;
    }
}
