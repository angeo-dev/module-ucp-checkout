<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings under angeo_ucp/checkout/*.
 */
class Config
{
    public const MODULE_VERSION = '1.0.0';

    private const PATH = 'angeo_ucp/checkout/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getSessionTtlSeconds(): int
    {
        $hours = (int) $this->value('session_ttl_hours');
        return 3600 * ($hours >= 1 && $hours <= 72 ? $hours : 6);
    }

    public function getMaxLineItems(): int
    {
        $max = (int) $this->value('max_line_items');
        return $max >= 1 ? min($max, 200) : 50;
    }

    public function getMaxQuantity(): int
    {
        $max = (int) $this->value('max_quantity');
        return $max >= 1 ? min($max, 100000) : 1000;
    }

    public function getHandoffPath(): string
    {
        $path = trim((string) $this->value('handoff_path'), '/ ');
        return in_array($path, ['checkout', 'checkout/cart'], true) ? $path : 'checkout';
    }

    public function getRetentionDays(): int
    {
        $days = (int) $this->scopeConfig->getValue(self::PATH . 'retention_days');
        return $days >= 1 ? $days : 30;
    }

    /**
     * Legal links for checkout.links[]: type => https URL.
     *
     * @return array<string, string>
     */
    public function getLinks(): array
    {
        $links = [];
        foreach (['terms_of_service' => 'terms_url', 'privacy_policy' => 'privacy_url', 'refund_policy' => 'refund_url'] as $type => $field) {
            $url = trim((string) $this->value($field));
            if ($url !== '' && str_starts_with(strtolower($url), 'https://')) {
                $links[$type] = $url;
            }
        }
        return $links;
    }

    private function value(string $field): mixed
    {
        return $this->scopeConfig->getValue(self::PATH . $field, ScopeInterface::SCOPE_STORE);
    }
}
