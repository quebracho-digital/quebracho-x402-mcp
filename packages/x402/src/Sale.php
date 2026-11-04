<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One paid request, the steps both middlewares share: admit (offline verify,
 * screen the payer, online verify, claim), then settle or abandon.
 *
 * @internal
 */
final class Sale
{
    public const UNAVAILABLE = 'facilitator_unavailable';
    public const UNEXPECTED_VERIFY = 'unexpected_verify_error';
    /** How long a claim or a state lives, at most: seconds. */
    public const TTL = 600;
    /** The largest response body kept in the ledger, in bytes. */
    public const MAX_BODY = 1048576;

    private ?AddressCheck $check = null;
    private bool $claimed = false;

    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $claim,
        private readonly \Closure $settle,
        private readonly ?EventDispatcherInterface $events,
        private readonly ?\Closure $verify,
        private readonly ?\Closure $release,
        public readonly array $payment,
        public readonly array $req,
        private readonly ?\Closure $payer = null,
        private readonly int $duration = 0,
    ) {
    }

    /**
     * Settles before the handler runs (extra.paymentFlow = upfront), for
     * handlers that are expensive or cannot be undone.
     */
    public function upfront(): bool
    {
        return 'upfront' === ($this->req['extra']['paymentFlow'] ?? null);
    }

    /**
     * Why the payment cannot buy this, or NULL once it is claimed.
     */
    public function admit(): ?string
    {
        $scheme = $this->scheme();
        // Offline first: a signature too short for this tool costs no facilitator round trip.
        $reason = $scheme->verify($this->payment, $this->req) ?? $this->expiresTooSoon($scheme);
        if (null === $reason && null !== $this->payer) {
            // Who pays is proven now; a buyer this call is not for takes no claim.
            $from = $scheme->payer($this->payment);
            $reason = null === $from ? 'invalid_payload' : ($this->payer)($from);
        }
        // Screened once the signature proved who pays, before anything is spent;
        // a slow lookup started here is read before settling.
        $this->check = null === $reason ? AddressCheck::start($this->events, AddressCheck::PAYER, $scheme->payer($this->payment), $this->req) : null;
        $reason ??= $this->check?->reason();
        $error = null;
        if (null === $reason && null !== $this->verify) {
            // Online (balance, nonce unused): a bad payment runs nothing and takes no claim.
            try {
                $verdict = ($this->verify)($this->payment, $this->req);
                // NULL is good; a non-empty string is the reason. Anything else refuses, so an adapter that forgets NULL cannot approve by accident.
                $reason = null === $verdict || (\is_string($verdict) && '' !== $verdict) ? $verdict : self::UNEXPECTED_VERIFY;
            } catch (FacilitatorException $e) {
                $reason = self::UNAVAILABLE;
                $error = $e;
            } catch (\Throwable $e) {
                $reason = self::UNEXPECTED_VERIFY;
                $error = $e;
            }
        }
        // Again after the online verify, which may have taken seconds of that validity.
        $reason ??= $this->expiresTooSoon($scheme);
        // Spent before it runs: N concurrent copies get one delivery.
        if (null === $reason && !($this->claimed = ($this->claim)(Settlement::key($this->payment)))) {
            $reason = 'duplicate_settlement';
        }
        if (null !== $reason) {
            $this->outcome('rejected', reason: $reason, error: $error);
        }

        return $reason;
    }

    /**
     * What this payment already bought, read once its signature proves who paid (offline: a forged
     * payment costs nothing). ['state' => ...] to replay, ['refuse' => reason] to answer with, or
     * NULL to admit it as new.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    public function stored(PaymentLedger $ledger, ?\Closure $lookup): ?array
    {
        if (null !== $this->scheme()->verify($this->payment, $this->req)) {
            return null;
        }
        $key = Settlement::key($this->payment);
        $state = $ledger->get($key);
        if (null === $state) {
            return null;
        }

        return match ($state['status'] ?? null) {
            'delivered', 'settled' => $this->answer($state),
            'pending' => $this->confirm($ledger, $key, $state, $lookup),
            'settling' => $this->settling($ledger, $key, $state, $lookup),
            // Upfront, the handler started and never closed: it may have had effects, so it never runs again.
            'handling' => isset($state['response']) ? ['state' => $state] : ['refuse' => 'needs_review'],
            'claimed' => ['refuse' => 'in_progress'],
            // Unknown: never admit it again.
            default => ['refuse' => 'already_settled'],
        };
    }

    /**
     * A stored settled state's answer: its response replays; without one, 'run' when the handler never
     * ran (upfront: charged, nothing delivered yet), else refused (the default flow ran it, and kept no copy).
     *
     * @return array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function answer(array $state): array
    {
        if (isset($state['response'])) {
            return ['state' => $state];
        }

        return false === ($state['handler_ran'] ?? true) ? ['run' => $state] : ['refuse' => 'already_settled'];
    }

    /**
     * Write-ahead: records an intention before the money (or the handler) can move. FALSE when the store
     * refused it, and then the caller must not go on.
     *
     * @param array<string, mixed> $state
     */
    public function intend(?PaymentLedger $ledger, array $state): bool
    {
        $this->intent($state);
        if (null === $ledger) {
            return true;
        }
        try {
            $this->remember($ledger, $state);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * PaymentIntent, before the write it announces (and so before the settle or the handler): a crash after
     * the send then still leaves a log line. A broken listener must not block the payment, as in outcome().
     *
     * @param array<string, mixed> $state
     */
    private function intent(array $state): void
    {
        try {
            $receipt = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
            $ref = static fn (mixed $v): ?string => is_string($v) && '' !== $v ? $v : null;
            $this->events?->dispatch(new PaymentIntent(
                $state['status'],
                Settlement::key($this->payment),
                ['network' => $this->req['network'], 'from' => $this->scheme()->payer($this->payment)],
                ['network' => $this->req['network'], 'payTo' => $this->req['payTo'], 'amount' => $this->req['amount'], 'asset' => $this->req['asset']],
                $ref($receipt['paymentId'] ?? null),
                $ref($receipt['transaction'] ?? null),
            ));
        } catch (\Throwable) {
            // ponytail: a broken log listener must not block a sale; the intent is then only in the ledger.
        }
    }

    /**
     * Records this payment's outcome. A settled state becomes 'delivered' when $response is kept.
     *
     * @param array<string, mixed> $state status ('settled' or 'pending'), receipt, and any extra field
     */
    public function remember(PaymentLedger $ledger, array $state, ?ResponseInterface $response = null): void
    {
        $kept = self::capture($response);
        if (null !== $kept) {
            $state['response'] = $kept;
            $state['status'] = 'settled' === $state['status'] ? 'delivered' : $state['status'];
        }
        $ledger->put(Settlement::key($this->payment), $state, self::ttl($this->payment));
    }

    /**
     * Whether this sale still holds its claim (not freed): the money may have moved.
     */
    public function held(): bool
    {
        return $this->claimed;
    }

    /**
     * How long a claim or state lives: until the signature expires, at most TTL seconds.
     */
    public static function ttl(array $payment): int
    {
        $until = $payment['payload']['authorization']['validBefore'] ?? null;
        $left = is_numeric($until) ? (int) $until - time() : self::TTL;

        return min(self::TTL, max(1, $left));
    }

    /**
     * The response a stored state answers with (its receipt is added by the caller).
     */
    public static function restore(array $stored, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        $response = $responses->createResponse($stored['status']);
        foreach ($stored['headers'] as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }

        return $response->withBody($streams->createStream((string) base64_decode($stored['body'], true)));
    }

    private function scheme(): Scheme
    {
        return current(array_filter($this->schemes, fn (Scheme $s) => $s->supports($this->req['network'])))
          ?: throw new \LogicException("No scheme verifies {$this->req['network']}.");
    }

    /**
     * A settle that was sent but whose outcome was not stored (a crash between the two). Only a reference the
     * facilitator gave (transaction or paymentId) can be asked about; without one it is unknown until the TTL
     * frees the claim, and then the online verify refuses a spent nonce.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function settling(PaymentLedger $ledger, string $key, array $state, ?\Closure $lookup): ?array
    {
        $receipt = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
        $refs = array_filter([$receipt['transaction'] ?? null, $receipt['paymentId'] ?? null], static fn (mixed $v) => is_string($v) && '' !== $v);

        return [] === $refs ? ['refuse' => 'settlement_unknown'] : $this->confirm($ledger, $key, $state, $lookup, 'settlement_unknown');
    }

    /**
     * A pending payment: asks the lookup what the chain did. Confirmed, it becomes settled; refuted, it is
     * freed; unknown (or the lookup failed) it stays pending, or stays unknown ($unknown) for a settling state.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function confirm(PaymentLedger $ledger, string $key, array $state, ?\Closure $lookup, string $unknown = 'settlement_pending'): ?array
    {
        try {
            $receipt = null === $lookup ? null : $lookup($state);
        } catch (\Throwable) {
            // The lookup goes to the facilitator: unreachable means still pending, not refused.
            $receipt = null;
        }
        if (false === ($receipt['success'] ?? null)) {
            $ledger->release($key);

            return null;
        }
        if (true !== ($receipt['success'] ?? null)) {
            return ['refuse' => $unknown];
        }
        $state['receipt'] = $receipt;
        if ('settling' === ($state['status'] ?? null) && $this->upfront()) {
            // Upfront, a settling state is written before the handler: confirmed, the handler has not run yet.
            $state['handler_ran'] = false;
        }
        $state['status'] = isset($state['response']) ? 'delivered' : 'settled';
        $ledger->put($key, $state, self::ttl($this->payment));

        return $this->answer($state);
    }

    /**
     * The body is kept as base64 (JSON-safe) and only when it is a 2xx of at most MAX_BODY bytes.
     *
     * @return ?array{status: int, headers: array<string, string>, body: string}
     */
    private static function capture(?ResponseInterface $response): ?array
    {
        if (null === $response || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }
        $body = $response->getBody();
        if (!$body->isSeekable() || (null !== $body->getSize() && $body->getSize() > self::MAX_BODY)) {
            return null;
        }
        $body->rewind();
        $text = (string) $body;
        $body->rewind();
        if (strlen($text) > self::MAX_BODY) {
            return null;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => array_map(static fn (array $values) => implode(', ', $values), $response->getHeaders()),
            'body' => base64_encode($text),
        ];
    }

    /**
     * The receipt; a failure that moved nothing frees the claim.
     */
    public function settle(?int $handlerStatus = null): array
    {
        $late = $this->check?->verdict();
        $receipt = null === $late
          ? Settlement::run($this->settle, $this->payment, $this->req)
          // Ran but not paid for: withheld, as when settlement fails.
          : ['success' => false, 'errorReason' => $late, 'transaction' => '', 'network' => $this->req['network']];
        if (true === $receipt['success']) {
            $this->outcome('settled', $handlerStatus, $receipt);
        } else {
            // A transaction may still land (pending, or a facilitator that went
            // silent after we sent it, or one whose success did not add up): keep the claim. Only a clear refusal frees it.
            if ('' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'], ['settlement_pending', self::UNAVAILABLE, 'unexpected_settle_error', 'invalid_settle_response'], true)) {
                $this->free();
            }
            $this->outcome('not_settled', $handlerStatus, $receipt, $receipt['errorReason']);
        }

        return $receipt;
    }

    /**
     * The handler delivered nothing: nothing charged, the payment is good again.
     */
    public function abandon(?int $handlerStatus): void
    {
        $this->free();
        $this->outcome('failed', $handlerStatus);
    }

    /**
     * Upfront: money moved before the handler; record a handler that then failed,
     * so the seller can refund by hand.
     */
    public function ran(int $handlerStatus): void
    {
        if ($handlerStatus < 200 || $handlerStatus >= 300) {
            $this->outcome('failed_after_settle', $handlerStatus);
        }
    }

    /**
     * A slow handler must not outlive the signature: settled after it, an expired
     * authorization would deliver unpaid. Checked right after the offline verify
     * and again last before the claim, once the online verify spent its time.
     * Only a call that declares a duration.
     */
    private function expiresTooSoon(Scheme $scheme): ?string
    {
        if ($this->duration <= 0) {
            return null;
        }
        // ponytail: EVM only (EIP-3009 validBefore). Reachable: a Solana or NEAR
        // payment for a tool that declares a duration has no expiry we can read.
        if (!$scheme instanceof EvmExact) {
            return 'unsupported_scheme';
        }

        // verify() passed, so validBefore is a canonical decimal; same clock as verify().
        return (int) $this->payment['payload']['authorization']['validBefore'] - $scheme->now() < $this->duration + EvmExact::SLACK
          ? 'invalid_exact_evm_payload_authorization_valid_before' : null;
    }

    private function free(): void
    {
        if ($this->claimed && null !== $this->release) {
            try {
                ($this->release)(Settlement::key($this->payment));
            } catch (LedgerUnavailableException) {
                // ponytail: the claim stays until its TTL, so the buyer waits (409 in_progress) but cannot pay twice; the answer is not changed by a failed release.
                return;
            }
            $this->claimed = false;
        }
    }

    private function outcome(string $status, ?int $handlerStatus = null, ?array $receipt = null, ?string $reason = null, ?\Throwable $error = null): void
    {
        try {
            $this->events?->dispatch(new PaymentOutcome($status, $this->payment, $this->req, $handlerStatus, $receipt, $reason, $error));
        } catch (\Throwable) {
            // ponytail: a broken log listener must not undo a sale (money may have moved); errors here are dropped.
        }
    }
}
