<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Magento\Framework\App\DeploymentConfig;

/**
 * The store's private signing key for webhooks.
 *
 * Private keys live in app/etc/env.php, where `angeo:ucp:keys:generate`
 * tells you to put them:
 *
 *     'ucp' => ['signing_keys' => ['<kid>' => '<PEM>']]
 *
 * Only a kid that is also published in the profile's keys[] is used, so a
 * platform can always verify the signature.
 */
class SigningKey
{
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly UcpConfig $ucpConfig
    ) {
    }

    /**
     * @return array{kid: string, pem: string}|null
     */
    public function get(): ?array
    {
        $private = $this->deploymentConfig->get('ucp/signing_keys');
        if (!is_array($private) || $private === []) {
            return null;
        }
        $published = [];
        foreach ($this->ucpConfig->getPublicKeys() as $jwk) {
            if (isset($jwk['kid'])) {
                $published[] = (string) $jwk['kid'];
            }
        }
        foreach ($private as $kid => $pem) {
            if (is_string($pem) && $pem !== '' && in_array((string) $kid, $published, true)) {
                return ['kid' => (string) $kid, 'pem' => $pem];
            }
        }
        return null;
    }
}
