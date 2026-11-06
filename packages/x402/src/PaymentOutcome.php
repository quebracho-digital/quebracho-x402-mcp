<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What became of a payment, dispatched (PSR-14) once per paid request that
 * carried one: for logs, metrics and the seller's own records.
 *
 * status: settled (money moved, delivered), not_settled (ran, settle failed:
 * withheld), failed (the handler delivered nothing: nothing charged),
 * rejected (refused before running: bad, screened, replayed, unavailable),
 * failed_after_settle (upfront: charged, then the handler failed; refund by hand).
 *
 * error: the exception behind a reason, when there was one (a verify that threw, a facilitator
 * that was down). For logs: it may hold internals, so keep it out of responses.
 */
final class PaymentOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly array $payment,
        public readonly array $req,
        public readonly ?int $handlerStatus = null,
        public readonly ?array $receipt = null,
        public readonly ?string $reason = null,
        public readonly ?\Throwable $error = null,
    ) {
    }
}
