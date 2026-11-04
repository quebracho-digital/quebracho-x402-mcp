<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Charges for a request with x402: 402 → verify → run → settle.
 *
 * Which requests cost what is the caller's call ($price), so one endpoint
 * can be free for some calls and paid for others (an MCP tools/call).
 */
final class X402Middleware implements MiddlewareInterface
{
    /**
     * @param Scheme[]                  $schemes
     *                                            The schemes this seller verifies, one per chain family
     * @param \Closure                  $price
     *                                            fn(ServerRequestInterface): ?array — the `accepts` list (payment
     *                                            requirements) for a request, or NULL when it is free
     * @param \Closure                  $claim
     *                                            fn(string $key): bool — TRUE the first time a payment is seen, FALSE
     *                                            after; atomic and durable (FileClaims, or a DB unique key). Runs before
     *                                            the handler, so a payment buys one delivery even before it settles.
     * @param \Closure                  $settle
     *                                            fn(array $payment, array $req): array — a facilitator's settle result
     *                                            (success, transaction, network, payer, errorReason). Settlement::mock()
     *                                            is mock mode, never implicit: nothing moves on chain.
     *                                            A FacilitatorException answers 502: not the buyer's fault.
     * @param ?EventDispatcherInterface $events
     *                                            Dispatches an AddressCheck on the payer and a PaymentOutcome; NULL: neither
     * @param ?\Closure                 $verify
     *                                            fn(array $payment, array $req): ?string — an online check (facilitator
     *                                            /verify, eth_call) after the offline one, before the claim. Returns NULL
     *                                            when good, or a non-empty reason string. Any other return (an empty
     *                                            string, FALSE, an array) or any exception refuses as
     *                                            `unexpected_verify_error` (402), except a FacilitatorException, which
     *                                            answers 502. Nothing is claimed and the handler does not run on a refusal.
     *                                            NULL skips the check: the handler may run for a payment
     *                                            settle then refuses (its output withheld).
     * @param ?\Closure                 $release
     *                                            fn(string $key): void — frees a claim when nothing was charged (the
     *                                            handler failed, or settle failed with no transaction), so the buyer's
     *                                            signature is good again. NULL keeps every claim.
     * @param ?\Closure                 $resource
     *                                            fn(ServerRequestInterface): array — the 402's resource (url,
     *                                            description, mimeType); default: the request URL
     * @param ?PaymentLedger            $ledger
     *                                            Keeps each payment's outcome, so a retry gets the stored response
     *                                            (replayed, not run again), 409 while it is in progress, or 409 when
     *                                            it settled without a response to keep. Replaces $claim and $release.
     * @param ?\Closure                 $lookup
     *                                            fn(array $state): ?array — asks the chain about a 'pending' payment:
     *                                            a receipt with success true settles it, success false frees it, NULL
     *                                            (or no lookup) answers 409 settlement_pending.
     */
    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $price,
        private readonly \Closure $claim,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly \Closure $settle,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $verify = null,
        private readonly ?\Closure $release = null,
        private readonly ?\Closure $resource = null,
        private readonly ?PaymentLedger $ledger = null,
        private readonly ?\Closure $lookup = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $accepts = ($this->price)($request);
        if (null === $accepts) {
            return $handler->handle($request);
        }
        $resource = null !== $this->resource ? ($this->resource)($request) : ['url' => (string) $request->getUri()];

        $header = $request->getHeaderLine(PaymentHeaders::SIGNATURE);
        if ('' === $header) {
            return $this->required($accepts, $resource, 'PAYMENT-SIGNATURE header is required');
        }
        try {
            $payment = PaymentHeaders::decode($header);
        } catch (InvalidPaymentException $e) {
            return $this->json(400, ['error' => $e->getMessage()]);
        }

        // The offer the buyer says it took, among ours: never its own copy.
        $accepted = $payment['accepted'] ?? [];
        $req = current(array_filter($accepts, fn (array $a) => $a['scheme'] === ($accepted['scheme'] ?? null) && $a['network'] === ($accepted['network'] ?? null)));
        if (false === $req) {
            return $this->required($accepts, $resource, 'The payment matches none of the accepted offers');
        }
        $sale = $this->sale($payment, $req);
        try {
            if (null !== $this->ledger) {
                $stored = $sale->stored($this->ledger, $this->lookup);
                if (null !== $stored) {
                    return isset($stored['refuse']) ? $this->json(409, ['error' => $stored['refuse']]) : $this->replay($stored['state']);
                }
            }
            $reason = $sale->admit();
        } catch (LedgerUnavailableException) {
            // Before the handler: nothing charged, nothing run.
            return $this->json(503, ['error' => 'ledger_unavailable']);
        }
        if (Sale::UNAVAILABLE === $reason) {
            return $this->json(502, ['error' => $reason]);
        }
        if (null !== $reason) {
            return $this->required($accepts, $resource, $reason);
        }

        if ($sale->upfront()) {
            // Money first; once it moved, the receipt goes with whatever the handler answers.
            $receipt = $sale->settle();
            $this->record($sale, $receipt);
            if (true !== $receipt['success']) {
                return $this->failed($accepts, $resource, $receipt);
            }

            try {
                $response = $handler->handle($request);
            } catch (\Throwable $e) {
                // Paid and failed: recorded, so the seller can refund by hand.
                $sale->ran(500);
                throw $e;
            }
            $sale->ran($response->getStatusCode());
            $this->record($sale, $receipt, $response);

            return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $sale->abandon(null);
            throw $e;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // Nothing delivered, nothing charged.
            $sale->abandon($status);

            return $response;
        }
        $receipt = $sale->settle($status);
        $this->record($sale, $receipt, $response);
        if (true !== $receipt['success']) {
            return $this->failed($accepts, $resource, $receipt);
        }

        return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
    }

    /**
     * The claim and release come from the ledger when there is one, else from the closures.
     */
    private function sale(array $payment, array $req): Sale
    {
        if (null === $this->ledger) {
            return new Sale($this->schemes, $this->claim, $this->settle, $this->events, $this->verify, $this->release, $payment, $req);
        }
        $ledger = $this->ledger;
        $ttl = Sale::ttl($payment);

        return new Sale($this->schemes, fn (string $key): bool => $ledger->claim($key, $ttl), $this->settle, $this->events, $this->verify, fn (string $key) => $ledger->release($key), $payment, $req);
    }

    /**
     * Stores what happened, when there is a ledger: a settled payment (with the response, if any), or a
     * pending one whose claim was kept.
     *
     * A store that fails here comes after the money may have moved (or was refused): the answer is given anyway,
     * never a 503 that would tell the buyer nothing was charged. The claim stays until its TTL, so no second
     * payment is taken; the buyer's retry gets 409 in_progress or already_settled instead of a replay.
     */
    private function record(Sale $sale, array $receipt, ?ResponseInterface $response = null): void
    {
        if (null === $this->ledger) {
            return;
        }
        try {
            if (true === $receipt['success']) {
                $sale->remember($this->ledger, ['status' => 'settled', 'receipt' => $receipt], $response);
            } elseif ($sale->held()) {
                $sale->remember($this->ledger, ['status' => 'pending', 'receipt' => $receipt], $response);
            }
        } catch (LedgerUnavailableException) {
            // ponytail: no outcome event for a lost store; the settle's PaymentOutcome already went out.
        }
    }

    private function replay(array $state): ResponseInterface
    {
        return Sale::restore($state['response'], $this->responses, $this->streams)
          ->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($state['receipt']));
    }

    /**
     * Settle failed: a 402 with the failed SettlementResponse, as
     * transports-v2/http.md asks; 502 when the facilitator was unreachable.
     */
    private function failed(array $accepts, array $resource, array $receipt): ResponseInterface
    {
        $response = Sale::UNAVAILABLE === $receipt['errorReason']
          ? $this->json(502, ['error' => Sale::UNAVAILABLE])
          : $this->required($accepts, $resource, $receipt['errorReason']);

        return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
    }

    private function required(array $accepts, array $resource, string $error): ResponseInterface
    {
        $body = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => array_values($accepts)];

        return $this->json(402, $body)->withHeader(PaymentHeaders::REQUIRED, PaymentHeaders::encode($body));
    }

    private function json(int $status, array $body): ResponseInterface
    {
        return $this->responses->createResponse($status)
          ->withHeader('Content-Type', 'application/json')
          ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
