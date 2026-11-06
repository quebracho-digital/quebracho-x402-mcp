<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\PaymentOutcome;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Logs the x402 steps: the intent before each settle or handler (so a crash after the send still leaves a line),
 * and each payment's outcome. The context never holds the signature, the response body, the facilitator URL or a
 * bearer token.
 *
 * Where it goes: the 'x402' Monolog channel when symfony/monolog-bundle is installed (the $channels locator in
 * services.yaml holds it only then), else the PSR-3 logger, with the messages prefixed 'x402 ' as Screening's are.
 * Decided at the first log line, not at construction, so the container compiles with or without the bundle.
 */
final class PaymentLog
{
    /** @var array{LoggerInterface, string}|null [logger, prefix] */
    private ?array $target = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $channels,
    ) {
    }

    /** @return array{LoggerInterface, string} */
    private function target(): array
    {
        return $this->target ??= $this->channels->has('x402')
            ? [$this->channels->get('x402'), '']
            : [$this->logger, 'x402 '];
    }

    #[AsEventListener(event: PaymentIntent::class)]
    public function intent(PaymentIntent $event): void
    {
        [$logger, $prefix] = $this->target();
        $logger->info($prefix.'intent', [
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

        [$logger, $prefix] = $this->target();
        $logger->log($level, $prefix.'outcome', [
            'status' => $event->status,
            'network' => $event->req['network'] ?? null,
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason ?? ($event->receipt['errorReason'] ?? null),
            'handlerStatus' => $event->handlerStatus,
        ]);
    }
}
