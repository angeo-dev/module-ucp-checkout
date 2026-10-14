<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\MinorUnits;
use Angeo\UcpCheckout\Model\TotalsMapper;
use PHPUnit\Framework\TestCase;

class TotalsMapperTest extends TestCase
{
    private TotalsMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TotalsMapper(new MinorUnits());
    }

    /**
     * @param array<int, array<string, mixed>> $totals
     */
    private function assertBalanced(array $totals): void
    {
        $types = array_column($totals, 'type');
        self::assertSame(1, count(array_keys($types, 'subtotal', true)), 'exactly one subtotal');
        self::assertSame(1, count(array_keys($types, 'total', true)), 'exactly one total');
        $sum = 0;
        $total = null;
        foreach ($totals as $line) {
            self::assertIsInt($line['amount']);
            if ($line['type'] === 'total') {
                $total = $line['amount'];
                continue;
            }
            if (in_array($line['type'], ['discount', 'items_discount'], true)) {
                self::assertLessThan(0, $line['amount'], 'discounts are negative');
            } else {
                self::assertGreaterThanOrEqual(0, $line['amount']);
            }
            $sum += $line['amount'];
        }
        self::assertSame($total, $sum, 'parts add up to total');
    }

    public function testSimpleCart(): void
    {
        $totals = $this->mapper->checkoutTotals('USD', 59.0, 0.0, 0.0, 0.0, 59.0);
        self::assertSame([['type' => 'subtotal', 'amount' => 5900], ['type' => 'total', 'amount' => 5900]], $totals);
    }

    public function testDiscountShippingTax(): void
    {
        $totals = $this->mapper->checkoutTotals('USD', 100.0, -10.0, 5.0, 7.43, 102.43, true);
        $this->assertBalanced($totals);
        self::assertSame(['subtotal', 'discount', 'fulfillment', 'tax', 'total'], array_column($totals, 'type'));
        self::assertSame(-1000, $totals[1]['amount']);
    }

    public function testPositiveGapBecomesFee(): void
    {
        // e.g. gift wrapping or weee not in the standard parts
        $totals = $this->mapper->checkoutTotals('EUR', 50.0, 0.0, 0.0, 0.0, 52.5);
        $this->assertBalanced($totals);
        self::assertContains('fee', array_column($totals, 'type'));
    }

    public function testNegativeGapFoldsIntoDiscount(): void
    {
        // discount tax compensation: grand total lower than the parts
        $totals = $this->mapper->checkoutTotals('EUR', 100.0, -10.0, 0.0, 21.0, 108.9);
        $this->assertBalanced($totals);
        $discount = array_values(array_filter($totals, fn ($t) => $t['type'] === 'discount'))[0];
        self::assertSame(-1210, $discount['amount']);
    }

    public function testZeroDecimalCurrency(): void
    {
        $totals = $this->mapper->checkoutTotals('JPY', 1200.0, 0.0, 0.0, 120.0, 1320.0);
        $this->assertBalanced($totals);
        self::assertSame(1320, end($totals)['amount']);
    }

    public function testLineTotalsBalance(): void
    {
        $this->assertBalanced($this->mapper->lineTotals('USD', 118.0, 11.8, 8.5));
        self::assertSame(
            [['type' => 'subtotal', 'amount' => 5900], ['type' => 'total', 'amount' => 5900]],
            $this->mapper->lineTotals('USD', 59.0, 0.0, 0.0)
        );
    }
}
