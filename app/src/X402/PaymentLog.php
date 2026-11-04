<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Log\LoggerInterface;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\PaymentOutcome;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Logs the x402 steps: the intent before each settle or handler (so a crash after the send still leaves a line),
 * and each payment's outcome. The context never holds the signature, the response body, the facilitator URL or a
 * bearer token. No Monolog in this app, so no channel: the messages carry the 'x402' prefix, as Screening's do.
 */
final class PaymentLog
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: PaymentIntent::class)]
    public function intent(PaymentIntent $event): void
    {
        $this->logger->info('x402 intent', [
            'phase' => $event->phase,
            'key' => $event->key,
            'network' => $event->req['network'],
            'payTo' => $event->req['payTo'],
            'amount' => $event->req['amount'],
            'asset' => $event->req['asset'],
        ]);
    }

    #[AsEventListener(event: PaymentOutcome::class)]
    public function outcome(PaymentOutcome $event): void
    {
        $level = match ($event->status) {
            'settled', 'delivered' => 'info',
            'failed_after_settle' => 'error',
            // not_settled, rejected, failed (nothing charged): worth a look, not an error.
            default => 'warning',
        };

        $this->logger->log($level, 'x402 outcome', [
            'status' => $event->status,
            'network' => $event->req['network'] ?? null,
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason ?? ($event->receipt['errorReason'] ?? null),
            'handlerStatus' => $event->handlerStatus,
        ]);
    }
}
