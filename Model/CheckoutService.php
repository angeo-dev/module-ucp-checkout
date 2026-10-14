<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Angeo\Ucp\Model\Config as UcpConfig;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Group;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The five UCP checkout operations on top of a Magento guest quote.
 *
 * Scope (1.0.0): the agent builds the order — items, quantities, buyer,
 * discount code — and the buyer pays on the store's own checkout page.
 * Complete Checkout therefore never charges anything; it answers
 * `requires_escalation` with a `continue_url`, which the spec allows and the
 * reference agents expect. When the buyer places the order, the
 * OrderPlaced observer marks the session `completed` and a later Get
 * Checkout tells the agent.
 *
 * No PHP session is started here: agent calls are machine-to-machine.
 */
class CheckoutService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly CheckoutMapper $mapper,
        private readonly ItemResolver $itemResolver,
        private readonly Messages $messages,
        private readonly Config $config,
        private readonly UcpConfig $ucpConfig,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly Fulfillment $fulfillment
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function create(array $request, string $agentProfile): CheckoutResult
    {
        $invalid = $this->validateLines($request);
        if ($invalid !== null) {
            return $invalid;
        }

        $store = $this->storeManager->getStore();
        $quote = $this->newQuote($store);

        $messages = [];
        $added = $this->addLines($quote, $request['line_items'], $store, $messages);
        if ($added === []) {
            return new CheckoutResult(200, $this->messages->errorBody(
                UcpConfig::PROTOCOL_VERSION,
                $messages !== [] ? $messages : [$this->messages->error(
                    'item_unavailable',
                    'None of the requested items can be bought.',
                    Messages::SEVERITY_UNRECOVERABLE
                )],
                (string) $store->getBaseUrl()
            ));
        }

        $this->applyBuyer($quote, $request['buyer'] ?? null, $messages);
        $this->applyDiscounts($quote, $request, $messages, false);
        $destinationId = $this->applyFulfillment($quote, $request, $messages, null, false);

        $now = time();
        $session = new Session(
            null,
            $this->sessions->newSessionId(),
            (int) $quote->getId(),
            (int) $store->getId(),
            Session::STATUS_INCOMPLETE,
            $agentProfile,
            $this->sessions->newContinueToken(),
            $this->itemRefs($added),
            $messages,
            null,
            null,
            null,
            null,
            $now + $this->config->getSessionTtlSeconds(),
            $now,
            $destinationId
        );

        [$status, $resource] = $this->render($session, $quote, false);
        $session->status = $status;
        $this->sessions->save($session);

        return new CheckoutResult(201, $resource);
    }

    public function get(string $sessionId): CheckoutResult
    {
        $session = $this->sessions->getBySessionId($sessionId);
        if ($session === null) {
            return $this->notFound();
        }
        return new CheckoutResult(200, $this->current($session));
    }

    /**
     * Full replacement (spec): line_items, buyer and discounts in the request
     * become the checkout. A line left out is removed.
     *
     * @param array<string, mixed> $request
     */
    public function update(string $sessionId, array $request): CheckoutResult
    {
        $session = $this->sessions->getBySessionId($sessionId);
        if ($session === null) {
            return $this->notFound();
        }
        $this->expireIfDue($session);
        if ($session->isTerminal()) {
            return $this->notModifiable($session);
        }

        $invalid = $this->validateLines($request);
        if ($invalid !== null) {
            return $invalid;
        }

        $quote = $this->loadQuote($session);
        if ($quote === null) {
            return $this->notFound();
        }
        $store = $this->storeManager->getStore();

        $messages = [];
        [$kept, $added] = $this->replaceLines($quote, $request['line_items'], $store, $session, $messages);
        $this->applyBuyer($quote, $request['buyer'] ?? null, $messages);
        $this->applyDiscounts($quote, $request, $messages, true);
        $session->destinationId = $this->applyFulfillment($quote, $request, $messages, $session->destinationId, true);
        // New quote items only have ids after the save.
        $session->itemRefs = $kept + $this->itemRefs($added);

        $session->pendingMessages = $messages;
        [$status, $resource] = $this->render($session, $quote, false);
        $session->status = $status;
        $this->sessions->save($session);

        return new CheckoutResult(200, $resource);
    }

    /**
     * Never charges. Answers requires_escalation + continue_url once the
     * checkout has everything the API can collect; otherwise incomplete with
     * the recoverable messages to fix first.
     */
    public function complete(string $sessionId): CheckoutResult
    {
        $session = $this->sessions->getBySessionId($sessionId);
        if ($session === null) {
            return $this->notFound();
        }
        $this->expireIfDue($session);
        if ($session->status === Session::STATUS_COMPLETED) {
            return new CheckoutResult(200, $this->current($session));
        }
        if ($session->status === Session::STATUS_CANCELED) {
            return $this->notModifiable($session);
        }

        $quote = $this->loadQuote($session);
        if ($quote === null) {
            return $this->notFound();
        }

        [$status, $resource] = $this->render($session, $quote, true);
        $session->status = $status;
        $this->sessions->save($session);

        return new CheckoutResult(200, $resource);
    }

    public function cancel(string $sessionId): CheckoutResult
    {
        $session = $this->sessions->getBySessionId($sessionId);
        if ($session === null) {
            return $this->notFound();
        }
        $this->expireIfDue($session);

        if ($session->status === Session::STATUS_COMPLETED) {
            return $this->notModifiable($session);
        }
        if ($session->status === Session::STATUS_CANCELED) {
            // Repeated cancel: the same canceled resource, not an error.
            return new CheckoutResult(200, $this->current($session));
        }

        $quote = $this->loadQuote($session);
        $session->status = Session::STATUS_CANCELED;
        if ($quote !== null) {
            $quote->setIsActive(false);
            $this->cartRepository->save($quote);
            $session->pendingMessages = [];
            [, $resource] = $this->render($session, $quote, false);
            $session->snapshot = $resource;
        }
        $this->sessions->save($session);

        return new CheckoutResult(200, $this->current($session));
    }

    // ── state ────────────────────────────────────────────────────────────

    /**
     * Current resource for any session, terminal or not.
     *
     * @return array<string, mixed>
     */
    private function current(Session $session): array
    {
        $this->expireIfDue($session);

        if ($session->isTerminal() && $session->snapshot !== null
            && ($session->snapshot['status'] ?? '') === $session->status
        ) {
            return $session->snapshot;
        }

        $quote = $this->loadQuote($session);
        if ($quote === null) {
            return $this->messages->errorBody(UcpConfig::PROTOCOL_VERSION, [$this->messages->error(
                'not_found',
                'This checkout no longer exists.',
                Messages::SEVERITY_UNRECOVERABLE
            )]);
        }

        [$status, $resource] = $this->render($session, $quote, false);
        if ($session->isTerminal()) {
            $session->snapshot = $resource;
            $this->sessions->save($session);
        } elseif ($status !== $session->status) {
            $session->status = $status;
            $this->sessions->save($session);
        }
        return $resource;
    }

    /**
     * Decide the status and messages, then map.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function render(Session $session, Quote $quote, bool $completing): array
    {
        if ($session->isTerminal()) {
            return [$session->status, $this->mapper->toResource(
                $session,
                $quote,
                $session->status,
                [],
                $this->continueUrl($session)
            )];
        }

        $messages = $session->pendingMessages;
        $index = 0;
        foreach ($quote->getAllVisibleItems() as $item) {
            foreach ((array) $item->getErrorInfos() as $error) {
                $messages[] = $this->messages->error(
                    'item_unavailable',
                    (string) ($error['message'] ?? 'This item cannot be bought in this quantity.'),
                    Messages::SEVERITY_RECOVERABLE,
                    '$.line_items[' . $index . ']'
                );
            }
            $index++;
        }
        if ($index === 0) {
            $messages[] = $this->messages->error(
                'missing',
                'Add at least one item.',
                Messages::SEVERITY_RECOVERABLE,
                '$.line_items'
            );
        }
        if ((string) $quote->getCustomerEmail() === '') {
            $messages[] = $this->messages->error(
                'missing',
                'Buyer email is required.',
                Messages::SEVERITY_RECOVERABLE,
                '$.buyer.email'
            );
        }

        if (Messages::hasSeverity($messages, Messages::SEVERITY_RECOVERABLE)) {
            $status = Session::STATUS_INCOMPLETE;
        } else {
            $status = Session::STATUS_REQUIRES_ESCALATION;
            $messages[] = $completing
                ? $this->messages->error(
                    'payment_required',
                    'The buyer reviews the order and pays on the store checkout page. Send the buyer to continue_url.',
                    Messages::SEVERITY_BUYER_REVIEW
                )
                : $this->messages->error(
                    'buyer_handoff_required',
                    (string) $quote->getShippingAddress()->getShippingMethod() !== '' || $quote->isVirtual()
                        ? 'Payment is completed on the store checkout page. Send the buyer to continue_url.'
                        : 'Shipping and payment are chosen on the store checkout page. Send the buyer to continue_url.',
                    Messages::SEVERITY_BUYER_INPUT
                );
        }

        return [$status, $this->mapper->toResource($session, $quote, $status, $messages, $this->continueUrl($session))];
    }

    private function expireIfDue(Session $session): void
    {
        if (!$session->isExpired(time())) {
            return;
        }
        $session->status = Session::STATUS_CANCELED;
        $quote = $this->loadQuote($session);
        if ($quote !== null) {
            $quote->setIsActive(false);
            $this->cartRepository->save($quote);
            $session->pendingMessages = [];
            [, $session->snapshot] = $this->render($session, $quote, false);
        }
        $this->sessions->save($session);
    }

    private function continueUrl(Session $session): string
    {
        return $this->urlBuilder->getDirectUrl('ucp/checkout/continue/' . $session->continueToken);
    }

    // ── quote work ───────────────────────────────────────────────────────

    private function newQuote(StoreInterface $store): Quote
    {
        if (!$store instanceof Store) {
            throw new \LogicException('Store manager returned a store that is not a Store model.');
        }
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setIsActive(true);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
        $quote->setCheckoutMethod(Onepage::METHOD_GUEST);
        return $quote;
    }

    private function loadQuote(Session $session): ?Quote
    {
        try {
            $quote = $this->cartRepository->get($session->quoteId);
        } catch (NoSuchEntityException) {
            $this->logger->info(sprintf(
                '[Angeo_UcpCheckout] Quote %d of checkout %s no longer exists.',
                $session->quoteId,
                $session->sessionId
            ));
            return null;
        }
        return $quote instanceof Quote ? $quote : null;
    }

    private function saveQuote(Quote $quote): void
    {
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
    }

    /**
     * Add requested lines. Returns [quote item, requested id] pairs.
     *
     * @param array<int, mixed> $lines
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array{0: Quote\Item, 1: string}>
     */
    private function addLines(Quote $quote, array $lines, StoreInterface $store, array &$messages): array
    {
        $added = [];
        foreach ($lines as $line) {
            $item = $this->addOne($quote, $line, $store, $messages);
            if ($item !== null) {
                $added[] = [$item, (string) $line['item']['id']];
            }
        }
        return $added;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $messages
     */
    private function addOne(Quote $quote, array $line, StoreInterface $store, array &$messages): ?Quote\Item
    {
        $requestedId = (string) $line['item']['id'];
        try {
            $resolved = $this->itemResolver->resolve(
                $requestedId,
                (int) $line['quantity'],
                (int) $store->getId(),
                (int) $store->getWebsiteId()
            );
            $result = $quote->addProduct($resolved['product'], new DataObject($resolved['buy_request']));
            if (is_string($result)) {
                throw new ItemException('item_unavailable', $result);
            }
            return $result;
        } catch (ItemException $e) {
            $messages[] = $this->messages->error(
                $e->errorCode,
                sprintf('Item "%s": %s', $requestedId, $e->getMessage()),
                Messages::SEVERITY_RECOVERABLE
            );
        } catch (LocalizedException $e) {
            $messages[] = $this->messages->error(
                stripos($e->getMessage(), 'stock') !== false ? 'out_of_stock' : 'item_unavailable',
                sprintf('Item "%s": %s', $requestedId, $e->getMessage()),
                Messages::SEVERITY_RECOVERABLE
            );
        }
        return null;
    }

    /**
     * Full replacement of the quote's lines. Lines that keep their `id`
     * and item keep their quote item (quantity updated); everything else is
     * removed or added.
     *
     * @param array<int, mixed> $lines
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: array<string, string>, 1: array<int, array{0: Quote\Item, 1: string}>}
     *         [refs of kept items, newly added pairs]
     */
    private function replaceLines(Quote $quote, array $lines, StoreInterface $store, Session $session, array &$messages): array
    {
        $existing = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $existing[(string) $item->getId()] = $item;
        }

        $refs = [];
        $keep = [];
        $toAdd = [];
        foreach ($lines as $line) {
            $lineId = is_string($line['id'] ?? null) ? $line['id'] : '';
            $itemId = $lineId !== '' && str_starts_with($lineId, 'li_') ? substr($lineId, 3) : '';
            $requested = (string) $line['item']['id'];

            if ($itemId !== '' && isset($existing[$itemId]) && !isset($keep[$itemId])
                && ($session->itemRefs[$itemId] ?? '') === $requested
            ) {
                $keep[$itemId] = true;
                $existing[$itemId]->setQty((int) $line['quantity']);
                $refs[$itemId] = $requested;
                continue;
            }
            $toAdd[] = $line;
        }

        foreach ($existing as $itemId => $item) {
            if (!isset($keep[$itemId])) {
                $quote->removeItem((int) $itemId);
            }
        }

        return [$refs, $this->addLines($quote, $toAdd, $store, $messages)];
    }

    /**
     * @param array<int, array{0: Quote\Item, 1: string}> $added
     * @return array<string, string>
     */
    private function itemRefs(array $added): array
    {
        $refs = [];
        foreach ($added as [$item, $requested]) {
            $refs[(string) $item->getId()] = $requested;
        }
        return $refs;
    }

    /**
     * @param mixed $buyer
     * @param array<int, array<string, mixed>> $messages
     */
    private function applyBuyer(Quote $quote, mixed $buyer, array &$messages): void
    {
        $buyer = is_array($buyer) ? $buyer : [];
        $billing = $quote->getBillingAddress();

        $email = trim((string) ($buyer['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $messages[] = $this->messages->error(
                'invalid',
                'Buyer email is not valid.',
                Messages::SEVERITY_RECOVERABLE,
                '$.buyer.email'
            );
            $email = '';
        }
        $first = mb_substr(trim((string) ($buyer['first_name'] ?? '')), 0, 255);
        $last = mb_substr(trim((string) ($buyer['last_name'] ?? '')), 0, 255);
        $phone = mb_substr(trim((string) ($buyer['phone_number'] ?? '')), 0, 64);

        $quote->setCustomerEmail($email !== '' ? $email : null);
        $quote->setCustomerFirstname($first !== '' ? $first : null);
        $quote->setCustomerLastname($last !== '' ? $last : null);
        $billing->setEmail($email !== '' ? $email : null);
        $billing->setFirstname($first !== '' ? $first : null);
        $billing->setLastname($last !== '' ? $last : null);
        $billing->setTelephone($phone !== '' ? $phone : null);
        if (!$quote->isVirtual()) {
            $shipping = $quote->getShippingAddress();
            $shipping->setEmail($email !== '' ? $email : null);
            $shipping->setFirstname($first !== '' ? $first : null);
            $shipping->setLastname($last !== '' ? $last : null);
            $shipping->setTelephone($phone !== '' ? $phone : null);
        }
    }

    /**
     * Fulfillment extension (shipping). Writes the destination, saves the
     * quote so Magento collects live rates, then applies the chosen rate.
     * Always leaves the quote saved. Returns the destination id to keep.
     *
     * Full replacement on Update: no `fulfillment` in the request clears the
     * destination and the chosen rate.
     *
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $messages
     */
    private function applyFulfillment(Quote $quote, array $request, array &$messages, ?string $destinationId, bool $replace): ?string
    {
        if (!$this->ucpConfig->isFulfillmentDeclared() || $quote->isVirtual()) {
            $this->saveQuote($quote);
            return $destinationId;
        }

        $parsed = $this->fulfillment->parse($request);
        if ($parsed === null) {
            if ($replace) {
                $this->fulfillment->clear($quote);
                $destinationId = null;
            }
            $this->saveQuote($quote);
            return $destinationId;
        }

        $base = '$.fulfillment.methods[' . $parsed['index'] . ']';
        if ($parsed['destination'] !== null) {
            $store = $this->storeManager->getStore();
            if ($this->fulfillment->applyDestination($quote, $parsed['destination'], (int) $store->getId(), $base . '.destinations[0]', $messages, $this->messages)) {
                $sent = $parsed['destination']['id'] ?? null;
                $destinationId = is_string($sent) && $sent !== '' ? mb_substr($sent, 0, 64) : Fulfillment::DEFAULT_DESTINATION_ID;
            }
        } elseif ($parsed['destination_id'] !== null && $parsed['destination_id'] !== ($destinationId ?? Fulfillment::DEFAULT_DESTINATION_ID)) {
            $messages[] = $this->messages->error(
                'invalid',
                'Unknown destination. Send the shipping address in destinations[].',
                Messages::SEVERITY_RECOVERABLE,
                $base . '.selected_destination_id'
            );
        } elseif ($replace && $parsed['destination_id'] === null) {
            $this->fulfillment->clear($quote);
            $destinationId = null;
        }

        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true);
        $this->saveQuote($quote);

        $option = $parsed['option_id'];
        $current = (string) $address->getShippingMethod();
        if ($option !== null) {
            if ($this->fulfillment->hasRate($quote, $option)) {
                $address->setShippingMethod($option);
            } else {
                $messages[] = $this->messages->error(
                    'invalid',
                    'This shipping option is not available for the address.',
                    Messages::SEVERITY_RECOVERABLE,
                    $base . '.groups[0].selected_option_id'
                );
                $address->setShippingMethod(null);
            }
        } elseif ($replace || ($current !== '' && !$this->fulfillment->hasRate($quote, $current))) {
            $address->setShippingMethod(null);
        }
        if ((string) $address->getShippingMethod() !== $current || $option !== null) {
            $this->saveQuote($quote);
        }

        return $destinationId;
    }

    /**
     * Discount extension: one coupon code (Magento Open Source applies one).
     *
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $messages
     */
    private function applyDiscounts(Quote $quote, array $request, array &$messages, bool $replace): void
    {
        if (!$this->ucpConfig->isDiscountDeclared()) {
            return;
        }
        $codes = $request['discounts']['codes'] ?? null;
        if (!is_array($codes)) {
            if ($replace) {
                $quote->setCouponCode('');
            }
            return;
        }

        $codes = array_values(array_filter(
            array_map(static fn ($c): string => is_string($c) ? trim($c) : '', $codes),
            static fn (string $c): bool => $c !== ''
        ));
        if ($codes === []) {
            $quote->setCouponCode('');
            return;
        }

        $code = mb_substr($codes[0], 0, 255);
        $quote->setCouponCode($code);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        if ((string) $quote->getCouponCode() !== $code) {
            $messages[] = $this->messages->warning(
                'discount_code_invalid',
                sprintf('Discount code "%s" is not valid for this checkout.', $code),
                '$.discounts.codes[0]'
            );
        }
        for ($i = 1, $n = count($codes); $i < $n; $i++) {
            $messages[] = $this->messages->warning(
                'discount_code_combination_disallowed',
                'Only one discount code can be used per order.',
                '$.discounts.codes[' . $i . ']'
            );
        }
    }

    // ── validation and errors ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $request
     */
    private function validateLines(array $request): ?CheckoutResult
    {
        $lines = $request['line_items'] ?? null;
        $problem = null;
        if (!is_array($lines) || $lines === [] || !array_is_list($lines)) {
            $problem = ['$.line_items', 'line_items must be a non-empty array.'];
        } elseif (count($lines) > $this->config->getMaxLineItems()) {
            $problem = ['$.line_items', sprintf('At most %d line items per checkout.', $this->config->getMaxLineItems())];
        } else {
            foreach ($lines as $i => $line) {
                $qty = is_array($line) ? ($line['quantity'] ?? null) : null;
                if (!is_array($line) || !is_array($line['item'] ?? null) || !isset($line['item']['id'])) {
                    $problem = ['$.line_items[' . $i . '].item.id', 'Each line item needs item.id.'];
                    break;
                }
                if (!is_int($qty) || $qty < 1 || $qty > $this->config->getMaxQuantity()) {
                    $problem = [
                        '$.line_items[' . $i . '].quantity',
                        sprintf('quantity must be an integer from 1 to %d.', $this->config->getMaxQuantity()),
                    ];
                    break;
                }
            }
        }
        if ($problem === null) {
            return null;
        }
        return new CheckoutResult(400, $this->messages->errorBody(UcpConfig::PROTOCOL_VERSION, [
            $this->messages->error('invalid', $problem[1], Messages::SEVERITY_RECOVERABLE, $problem[0]),
        ]));
    }

    private function notFound(): CheckoutResult
    {
        return new CheckoutResult(404, $this->messages->errorBody(UcpConfig::PROTOCOL_VERSION, [
            $this->messages->error('not_found', 'Checkout session not found.', Messages::SEVERITY_UNRECOVERABLE),
        ]));
    }

    private function notModifiable(Session $session): CheckoutResult
    {
        return new CheckoutResult(409, $this->messages->errorBody(UcpConfig::PROTOCOL_VERSION, [
            $this->messages->error(
                'checkout_not_modifiable',
                sprintf('This checkout is %s and can no longer be changed.', $session->status),
                Messages::SEVERITY_UNRECOVERABLE
            ),
        ]));
    }
}
