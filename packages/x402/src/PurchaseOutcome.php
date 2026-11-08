<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What became of one offer the buyer was asked to pay, dispatched (PSR-14) by PayingHttpClient and
 * PayingMcpClient. The buyer's PaymentOutcome.
 *
 * status:
 *   paid        the receipt says success: the budget keeps it spent.
 *   refused     not signed (no_scheme, not_allowed, the screening's reason): nothing spent.
 *   released    the signature failed, it never left this process: the budget gives it back.
 *   unsettled   sent, and no receipt says success (the seller failed, or the send threw): it stays spent
 *               until Budget::reclaim() finds it expired and unused.
 *
 * req: the offer. reason: why it was refused or not settled. receipt: the seller's, when there was one.
 * error: the exception behind released or unsettled, for logs. tool: the MCP tool called (PayingMcpClient).
 */
final class PurchaseOutcome
{
    public const string PAID = 'paid';
    public const string REFUSED = 'refused';
    public const string RELEASED = 'released';
    public const string UNSETTLED = 'unsettled';

    public function __construct(
        public readonly string $status,
        public readonly array $req,
        public readonly ?string $reason = null,
        public readonly ?array $receipt = null,
        public readonly ?\Throwable $error = null,
        public readonly ?string $tool = null,
    ) {
    }
}
