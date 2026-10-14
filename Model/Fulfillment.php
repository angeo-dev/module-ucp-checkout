<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Fulfillment extension (dev.ucp.shopping.fulfillment, spec 2026-08-25),
 * shipping only.
 *
 * Shape (spec "Business Response Behavior", supports_multi_group: false):
 * one `shipping` method covering every shippable line, one destination
 * (the Platform writes the address), one group whose `options[]` are the
 * store's live Magento shipping rates for that address, cheapest first.
 * The agent picks one with `groups[0].selected_option_id`; its price then
 * shows up as the `fulfillment` total.
 *
 * Pickup and multi-destination are not offered; the business profile does
 * not claim them.
 */
class Fulfillment
{
    public const METHOD_ID = 'shipping';
    public const GROUP_ID  = 'package_1';
    public const DEFAULT_DESTINATION_ID = 'dest_1';

    private const MAX_FIELD = 255;

    public function __construct(
        private readonly MinorUnits $minorUnits,
        private readonly AllowedCountries $allowedCountries,
        private readonly RegionFactory $regionFactory
    ) {
    }

    /**
     * Read the shipping method out of a request.
     *
     * @param array<string, mixed> $request
     * @return array{destination: ?array<string, mixed>, destination_id: ?string, option_id: ?string, index: int}|null
     *         null when the request has no `fulfillment` at all
     */
    public function parse(array $request): ?array
    {
        if (!array_key_exists('fulfillment', $request)) {
            return null;
        }
        $methods = is_array($request['fulfillment']) ? ($request['fulfillment']['methods'] ?? []) : [];
        $methods = is_array($methods) && array_is_list($methods) ? $methods : [];

        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }
            $type = $method['type'] ?? self::METHOD_ID;
            if ($type !== self::METHOD_ID) {
                continue;
            }

            $destination = null;
            $destinations = $method['destinations'] ?? null;
            if (is_array($destinations) && isset($destinations[0]) && is_array($destinations[0])) {
                $destination = $destinations[0];
            }

            $groups = is_array($method['groups'] ?? null) ? $method['groups'] : [];
            $option = isset($groups[0]) && is_array($groups[0]) ? ($groups[0]['selected_option_id'] ?? null) : null;
            $selected = $method['selected_destination_id'] ?? null;

            return [
                'destination'    => $destination,
                'destination_id' => is_string($selected) && $selected !== '' ? $selected : null,
                'option_id'      => is_string($option) && $option !== '' ? $option : null,
                'index'          => (int) $index,
            ];
        }

        return ['destination' => null, 'destination_id' => null, 'option_id' => null, 'index' => 0];
    }

    /**
     * Write a Platform shipping destination onto the quote's shipping address.
     *
     * @param array<string, mixed> $destination postal_address.json fields
     * @param array<int, array<string, mixed>> $errors filled with recoverable errors
     * @return bool whether the address was applied
     */
    public function applyDestination(Quote $quote, array $destination, int $storeId, string $path, array &$errors, Messages $messages): bool
    {
        $field = static fn (string $key): string => mb_substr(trim((string) (is_scalar($destination[$key] ?? null) ? $destination[$key] : '')), 0, self::MAX_FIELD);

        $country = strtoupper($field('address_country'));
        $allowed = $this->allowedCountries->getAllowedCountries(ScopeInterface::SCOPE_STORE, (string) $storeId);
        if (!preg_match('/^[A-Z]{2}$/', $country) || !in_array($country, $allowed, true)) {
            $errors[] = $messages->error(
                'invalid',
                'This store does not ship to that country.',
                Messages::SEVERITY_RECOVERABLE,
                $path . '.address_country'
            );
            return false;
        }

        $street = array_values(array_filter(
            [$field('street_address'), $field('extended_address')],
            static fn (string $line): bool => $line !== ''
        ));
        $address = $quote->getShippingAddress();
        $address->setCountryId($country);
        $address->setStreet($street);
        $address->setCity($field('address_locality'));
        $address->setPostcode($field('postal_code'));
        $this->applyRegion($address, $country, $field('address_region'));

        foreach (['first_name' => 'setFirstname', 'last_name' => 'setLastname', 'phone_number' => 'setTelephone'] as $key => $setter) {
            if ($field($key) !== '') {
                $address->$setter($field($key));
            }
        }
        $address->setCollectShippingRates(true);
        return true;
    }

    /**
     * Forget the destination and the chosen rate (full-replacement Update
     * without `fulfillment`).
     */
    public function clear(Quote $quote): void
    {
        $address = $quote->getShippingAddress();
        // The typed setters take strings/ints, so "empty" is '' and the
        // region id is unset rather than set to null.
        $address->setCountryId('');
        $address->setStreet([]);
        $address->setCity('');
        $address->setPostcode('');
        $address->setRegion('');
        $address->unsetData('region_id');
        $address->setShippingMethod(null);
        $address->setCollectShippingRates(true);
    }

    public function hasDestination(Quote $quote): bool
    {
        return (string) $quote->getShippingAddress()->getCountryId() !== '';
    }

    /**
     * Live Magento rates for the shipping address, cheapest first.
     *
     * @return array<int, array{code: string, title: string, carrier: string, price: float}>
     */
    public function rates(Quote $quote): array
    {
        if (!$this->hasDestination($quote)) {
            return [];
        }
        $rates = [];
        foreach ((array) $quote->getShippingAddress()->getAllShippingRates() as $rate) {
            if ((string) $rate->getErrorMessage() !== '' || (string) $rate->getCode() === '') {
                continue;
            }
            $carrier = trim((string) $rate->getCarrierTitle());
            $method = trim((string) $rate->getMethodTitle());
            $rates[] = [
                'code'    => (string) $rate->getCode(),
                'title'   => $carrier !== '' && $method !== '' && $carrier !== $method ? $carrier . ' — ' . $method : ($method ?: $carrier),
                'carrier' => $carrier,
                'price'   => (float) $rate->getPrice(),
            ];
        }
        usort($rates, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        return $rates;
    }

    public function hasRate(Quote $quote, string $code): bool
    {
        foreach ($this->rates($quote) as $rate) {
            if ($rate['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * The `fulfillment` object of a checkout response.
     *
     * @param array<int, string> $lineIds shippable line item ids
     * @return array<string, mixed>
     */
    public function toResponse(Quote $quote, ?string $destinationId, array $lineIds, string $currency): array
    {
        $address = $quote->getShippingAddress();
        $method = [
            'id'                      => self::METHOD_ID,
            'type'                    => self::METHOD_ID,
            'line_item_ids'           => $lineIds,
            'selected_destination_id' => null,
            'destinations'            => [],
        ];

        $group = ['id' => self::GROUP_ID, 'line_item_ids' => $lineIds, 'options' => [], 'selected_option_id' => null];

        if ($this->hasDestination($quote)) {
            $destId = $destinationId ?? self::DEFAULT_DESTINATION_ID;
            $street = array_values(array_filter((array) $address->getStreet(), static fn ($s): bool => (string) $s !== ''));
            $method['destinations'] = [array_filter([
                'type'             => 'shipping_address',
                'id'               => $destId,
                'street_address'   => (string) ($street[0] ?? ''),
                'extended_address' => (string) ($street[1] ?? ''),
                'address_locality' => (string) $address->getCity(),
                'address_region'   => (string) ($address->getRegionCode() ?: $address->getRegion()),
                'postal_code'      => (string) $address->getPostcode(),
                'address_country'  => (string) $address->getCountryId(),
                'first_name'       => (string) $address->getFirstname(),
                'last_name'        => (string) $address->getLastname(),
                'phone_number'     => (string) $address->getTelephone(),
            ], static fn (string $v): bool => $v !== '')];
            $method['selected_destination_id'] = $destId;

            foreach ($this->rates($quote) as $rate) {
                $amount = max(0, $this->minorUnits->toMinor($rate['price'], $currency));
                $option = [
                    'id'     => $rate['code'],
                    'title'  => $rate['title'],
                    'totals' => [['type' => 'total', 'amount' => $amount]],
                ];
                if ($rate['carrier'] !== '') {
                    $option['carrier'] = $rate['carrier'];
                }
                $group['options'][] = $option;
            }

            $selected = (string) $address->getShippingMethod();
            if ($selected !== '' && in_array($selected, array_column($group['options'], 'id'), true)) {
                $group['selected_option_id'] = $selected;
            }
        }

        $method['groups'] = [$group];
        return ['methods' => [$method]];
    }

    private function applyRegion(Quote\Address $address, string $country, string $region): void
    {
        $address->unsetData('region_id');
        $address->setRegion($region);
        if ($region === '') {
            return;
        }
        $model = $this->regionFactory->create()->loadByCode($region, $country);
        if (!$model->getId()) {
            $model = $this->regionFactory->create()->loadByName($region, $country);
        }
        if ($model->getId()) {
            $address->setRegionId((int) $model->getId());
            $address->setRegion((string) $model->getName());
        }
    }
}
