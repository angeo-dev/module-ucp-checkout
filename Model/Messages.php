<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Spec-shaped messages (common/types/message_*.json) and the error envelope
 * (common/types/error_response.json).
 */
class Messages
{
    public const SEVERITY_RECOVERABLE   = 'recoverable';
    public const SEVERITY_BUYER_INPUT   = 'requires_buyer_input';
    public const SEVERITY_BUYER_REVIEW  = 'requires_buyer_review';
    public const SEVERITY_UNRECOVERABLE = 'unrecoverable';

    /**
     * @return array<string, string>
     */
    public function error(string $code, string $content, string $severity, ?string $path = null): array
    {
        $message = ['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => $severity];
        if ($path !== null) {
            $message['path'] = $path;
        }
        return $message;
    }

    /**
     * @return array<string, string>
     */
    public function warning(string $code, string $content, ?string $path = null): array
    {
        $message = ['type' => 'warning', 'code' => $code, 'content' => $content];
        if ($path !== null) {
            $message['path'] = $path;
        }
        return $message;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public static function hasSeverity(array $messages, string $severity): bool
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? '') === 'error' && ($message['severity'] ?? '') === $severity) {
                return true;
            }
        }
        return false;
    }

    /**
     * Body for a request that produced no checkout resource.
     *
     * @param array<int, array<string, mixed>> $messages minItems 1
     * @return array<string, mixed>
     */
    public function errorBody(string $version, array $messages, ?string $continueUrl = null): array
    {
        $body = ['ucp' => ['version' => $version, 'status' => 'error'], 'messages' => $messages];
        if ($continueUrl !== null) {
            $body['continue_url'] = $continueUrl;
        }
        return $body;
    }
}
