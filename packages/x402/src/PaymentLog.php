<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Log\LoggerInterface;

/**
 * Turns the library's events into PSR-3 log lines: subscribe its methods to them (PSR-14), or call them.
 *
 *   intent()           PaymentIntent      info     before each settle or handler: a crash after the send still leaves a line
 *   outcome()          PaymentOutcome     info / warning / error (failed_after_settle: charged, nothing delivered)
 *   fault()            PaymentFault       error for record (money may have moved, the state was lost), else warning
 *   rejected()         AddressRejected    warning
 *   skipped()          ScreeningSkipped   warning: an address passed a list that could not answer
 *   purchaseIntent()   PurchaseIntent     info
 *   purchase()         PurchaseOutcome    info / warning
 *   reclaim()          BudgetReclaim      info, warning when the chain could not answer
 *
 * The context never holds a signature, a response body, a facilitator URL or a bearer token; an exception goes
 * under 'exception', as PSR-3 asks. Every message starts with $prefix ('x402 ' by default), so the lines can
 * be found in a shared log; give '' to a logger of its own (a Monolog channel).
 */
final class PaymentLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $prefix = 'x402 ',
    ) {
    }

    public function intent(PaymentIntent $event): void
    {
        $this->logger->info($this->prefix.'intent', ['phase' => $event->phase, 'key' => $event->key] + self::offer($event->req));
    }

    public function outcome(PaymentOutcome $event): void
    {
        $level = match ($event->status) {
            'settled', 'delivered' => 'info',
            'failed_after_settle' => 'error',
            // not_settled, rejected, failed (nothing charged): worth a look, not an error.
            default => 'warning',
        };
        $this->logger->log($level, $this->prefix.'outcome', self::error([
            'status' => $event->status,
            'network' => $event->req['network'] ?? null,
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason ?? ($event->receipt['errorReason'] ?? null),
            'handlerStatus' => $event->handlerStatus,
        ], $event->error));
    }

    public function fault(PaymentFault $event): void
    {
        $this->logger->log(PaymentFault::RECORD === $event->op ? 'error' : 'warning', $this->prefix.'fault', self::error([
            'op' => $event->op,
            'key' => $event->key,
            'network' => $event->network,
        ], $event->error));
    }

    public function rejected(AddressRejected $event): void
    {
        $this->logger->warning($this->prefix.'address rejected', self::error([
            'role' => $event->role,
            'network' => $event->network,
            'address' => $event->address,
            'reason' => $event->reason,
        ], $event->error));
    }

    public function skipped(ScreeningSkipped $event): void
    {
        $this->logger->warning($this->prefix.'screening skipped', self::error([
            'role' => $event->role,
            'network' => $event->network,
            'address' => $event->address,
            'by' => $event->by,
        ], $event->error));
    }

    public function purchaseIntent(PurchaseIntent $event): void
    {
        $this->logger->info($this->prefix.'purchase intent', ['key' => $event->key, 'from' => $event->payment['from'] ?? null] + self::offer($event->req));
    }

    public function purchase(PurchaseOutcome $event): void
    {
        $this->logger->log(PurchaseOutcome::PAID === $event->status ? 'info' : 'warning', $this->prefix.'purchase', self::error([
            'status' => $event->status,
            'tool' => $event->tool,
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason,
        ] + self::offer($event->req), $event->error));
    }

    public function reclaim(BudgetReclaim $event): void
    {
        $this->logger->log(BudgetReclaim::UNKNOWN === $event->status ? 'warning' : 'info', $this->prefix.'budget reclaim', self::error([
            'status' => $event->status,
            'network' => $event->network,
            'from' => $event->from,
            'nonce' => $event->nonce,
            'amount' => $event->amount,
        ], $event->error));
    }

    /**
     * Each event class and the method that logs it, to subscribe them all at once.
     *
     * @return array<class-string, string>
     */
    public static function events(): array
    {
        return [
            PaymentIntent::class => 'intent',
            PaymentOutcome::class => 'outcome',
            PaymentFault::class => 'fault',
            AddressRejected::class => 'rejected',
            ScreeningSkipped::class => 'skipped',
            PurchaseIntent::class => 'purchaseIntent',
            PurchaseOutcome::class => 'purchase',
            BudgetReclaim::class => 'reclaim',
        ];
    }

    /**
     * @return array{network: mixed, payTo: mixed, amount: mixed, asset: mixed}
     */
    private static function offer(array $req): array
    {
        return ['network' => $req['network'] ?? null, 'payTo' => $req['payTo'] ?? null, 'amount' => $req['amount'] ?? null, 'asset' => $req['asset'] ?? null];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function error(array $context, ?\Throwable $error): array
    {
        return null === $error ? $context : $context + ['exception' => $error];
    }
}
