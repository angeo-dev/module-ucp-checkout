<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Signature\ProfileFetcher;

/**
 * Where to send order events: the platform declares `webhook_url` in its
 * own profile (capabilities["dev.ucp.shopping.order"][].config.webhook_url).
 * The profile URL is the one the platform sent in UCP-Agent when it created
 * the checkout. Angeo_Ucp's ProfileFetcher does the SSRF-safe fetch.
 */
class WebhookTarget
{
    public function __construct(
        private readonly ProfileFetcher $profileFetcher
    ) {
    }

    public function forProfile(string $profileUrl): ?string
    {
        if ($profileUrl === '') {
            return null;
        }
        $profile = $this->profileFetcher->fetch($profileUrl);
        if (!is_array($profile)) {
            return null;
        }
        $ucp = is_array($profile['ucp'] ?? null) ? $profile['ucp'] : $profile;
        $entries = $ucp['capabilities']['dev.ucp.shopping.order'] ?? [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $url = is_array($entry) ? ($entry['config']['webhook_url'] ?? null) : null;
            if (is_string($url) && str_starts_with(strtolower($url), 'https://')) {
                return $url;
            }
        }
        return null;
    }
}
