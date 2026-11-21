<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\Ucp\Model\Network\PublicAddressGuard;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers queued order events.
 *
 * Request (spec "Order Event Webhook"): POST the full order entity to the
 * platform's webhook_url with Webhook-Id, Webhook-Timestamp, UCP-Agent (the
 * store's profile URL) and RFC 9421 Signature/Signature-Input/Content-Digest.
 *
 * Safety: HTTPS only, no redirects, 5 s timeout, and the host is resolved
 * once, checked to be a public address, then pinned (CURLOPT_RESOLVE) so a
 * DNS change cannot point the request at an internal service.
 */
class WebhookSender
{
    private const TIMEOUT = 5;

    public function __construct(
        private readonly WebhookQueue $queue,
        private readonly SessionRepository $sessions,
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderMapper $orderMapper,
        private readonly WebhookTarget $target,
        private readonly WebhookSigner $signer,
        private readonly SigningKey $signingKey,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly PublicAddressGuard $addressGuard
    ) {
    }

    public function sendDue(): void
    {
        foreach ($this->queue->due() as $row) {
            $id = (int) $row['entity_id'];
            $attempts = (int) $row['attempts'] + 1;
            try {
                [$status, $error] = $this->deliver($row);
            } catch (\Throwable $e) {
                [$status, $error] = [0, $e->getMessage()];
            }
            if ($status >= 200 && $status < 300) {
                $this->queue->markSent($id, $status);
            } else {
                $this->queue->markFailed($id, $attempts, $status, $error);
                $this->logger->info(sprintf('[Angeo_UcpCheckout] Webhook %s attempt %d failed: %s', $row['event_id'], $attempts, $error));
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: int, 1: string} [http status, error]
     */
    private function deliver(array $row): array
    {
        $session = $this->sessions->getBySessionId((string) $row['session_id']);
        if ($session === null) {
            return [0, 'checkout session gone'];
        }
        try {
            $order = $this->orders->get((int) $row['order_id']);
        } catch (NoSuchEntityException) {
            return [0, 'order gone'];
        }
        if (!$order instanceof Order) {
            return [0, 'order gone'];
        }

        $url = $this->target->forProfile($session->agentProfile);
        if ($url === null) {
            return [0, 'platform profile declares no order webhook_url'];
        }
        $key = $this->signingKey->get();
        if ($key === null) {
            return [0, 'no private signing key in env.php (ucp/signing_keys) for a published kid'];
        }

        $body = (string) json_encode($this->orderMapper->toEntity($order, $session), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $profileUrl = rtrim((string) $this->storeManager->getStore((int) $order->getStoreId())->getBaseUrl(), '/') . '/.well-known/ucp';
        // The UCP-Agent header is signed too (1.2.0): the spec requires a
        // present ucp-agent to be covered, so a strict verifier skips a
        // signature that leaves it out.
        $ucpAgent = 'profile="' . $profileUrl . '"; version="' . UcpConfig::PROTOCOL_VERSION . '"';
        $headers = $this->signer->sign('POST', $url, $body, $key['kid'], $key['pem'], null, $ucpAgent) + [
            'Webhook-Id'        => (string) $row['event_id'],
            'Webhook-Timestamp' => (string) time(),
        ];

        return $this->post($url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private function post(string $url, string $body, array $headers): array
    {
        // Same guard as the profile fetcher (angeo/module-ucp 2.2.2): https,
        // port 443, every resolved address global, connect to the checked one.
        $target = $this->addressGuard->inspect($url, $reason);
        if ($target === null) {
            return [0, 'webhook URL refused: ' . (string) $reason];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return [0, 'curl init failed'];
        }
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_RESOLVE        => [PublicAddressGuard::resolveEntry($target)],
            CURLOPT_USERAGENT      => 'Angeo-UCP-Webhooks/' . Config::MODULE_VERSION,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($curl) : ($status >= 300 ? 'HTTP ' . $status : '');
        return [$status, $error];
    }
}
