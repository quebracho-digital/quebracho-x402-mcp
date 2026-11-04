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
 * bearer token.
 *
 * Where it goes: the 'x402' Monolog channel when symfony/monolog-bundle is installed (services.yaml passes it as
 * $channel), else the PSR-3 logger, with the messages prefixed 'x402 ' as Screening's are.
 */
final class PaymentLog
{
    private readonly LoggerInterface $logger;
    private readonly string $prefix;

    public function __construct(LoggerInterface $logger, ?LoggerInterface $channel = null)
    {
        $this->logger = $channel ?? $logger;
        $this->prefix = null === $channel ? 'x402 ' : '';
    }

    #[AsEventListener(event: PaymentIntent::class)]
    public function intent(PaymentIntent $event): void
    {
        $this->logger->info($this->prefix.'intent', [
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

        $this->logger->log($level, $this->prefix.'outcome', [
            'status' => $event->status,
            'network' => $event->req['network'] ?? null,
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason ?? ($event->receipt['errorReason'] ?? null),
            'handlerStatus' => $event->handlerStatus,
        ]);
    }
}
