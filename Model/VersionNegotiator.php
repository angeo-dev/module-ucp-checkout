<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;

/**
 * Reads the protocol version an agent asks for in the UCP-Agent header
 * (RFC 8941 dictionary: profile="…"; version="YYYY-MM-DD") and decides
 * whether this store can answer it.
 *
 * The store answers in its own version (UcpConfig::PROTOCOL_VERSION). An
 * agent may ask for that version, an older version this store declares, or
 * nothing at all. A version newer than ours, or one we never served, is
 * refused with 422 — the official conformance suite checks exactly that.
 */
class VersionNegotiator
{
    public function __construct(
        private readonly UcpConfig $ucpConfig
    ) {
    }

    /**
     * @return array{profile: string, version: string}
     */
    public function parseAgentHeader(string $header): array
    {
        $out = ['profile' => '', 'version' => ''];
        foreach (preg_split('/[;,]/', $header) ?: [] as $member) {
            if (!str_contains($member, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $member, 2));
            $value = trim($value, '"');
            $key = strtolower($key);
            if (($key === 'profile' || $key === 'version') && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Versions this store answers.
     *
     * @return array<int, string>
     */
    public function supportedVersions(): array
    {
        $versions = [UcpConfig::PROTOCOL_VERSION, UcpConfig::PREVIOUS_PROTOCOL_VERSION];
        foreach (array_keys($this->ucpConfig->getSupportedVersions()) as $version) {
            $versions[] = (string) $version;
        }
        return array_values(array_unique($versions));
    }

    public function isSupported(string $requested): bool
    {
        return $requested === '' || in_array($requested, $this->supportedVersions(), true);
    }
}
