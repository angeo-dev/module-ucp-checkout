<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\Fulfillment;
use Angeo\UcpCheckout\Model\MinorUnits;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\RegionFactory;
use PHPUnit\Framework\TestCase;

class FulfillmentParseTest extends TestCase
{
    private function fulfillment(): Fulfillment
    {
        return new Fulfillment(
            new MinorUnits(),
            $this->createMock(AllowedCountries::class),
            $this->createMock(RegionFactory::class)
        );
    }

    public function testNoFulfillmentKeyIsNull(): void
    {
        self::assertNull($this->fulfillment()->parse(['line_items' => []]));
    }

    public function testReadsShippingMethod(): void
    {
        $parsed = $this->fulfillment()->parse(['fulfillment' => ['methods' => [
            ['type' => 'pickup'],
            [
                'type' => 'shipping',
                'destinations' => [['address_country' => 'US', 'postal_code' => '62704']],
                'selected_destination_id' => 'dest_1',
                'groups' => [['id' => 'package_1', 'selected_option_id' => 'flatrate_flatrate']],
            ],
        ]]]);

        self::assertSame(1, $parsed['index']);
        self::assertSame(['address_country' => 'US', 'postal_code' => '62704'], $parsed['destination']);
        self::assertSame('dest_1', $parsed['destination_id']);
        self::assertSame('flatrate_flatrate', $parsed['option_id']);
    }

    public function testTypeDefaultsToShippingAndJunkIsIgnored(): void
    {
        $parsed = $this->fulfillment()->parse(['fulfillment' => ['methods' => [
            ['groups' => [['selected_option_id' => '']], 'selected_destination_id' => 7],
        ]]]);
        self::assertSame(
            ['destination' => null, 'destination_id' => null, 'option_id' => null, 'index' => 0],
            $parsed
        );
        self::assertSame(
            ['destination' => null, 'destination_id' => null, 'option_id' => null, 'index' => 0],
            $this->fulfillment()->parse(['fulfillment' => 'nonsense'])
        );
    }
}
