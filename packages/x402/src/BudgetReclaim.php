<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Budget::reclaim() asked the chain about an expired signature the seller never settled, dispatched (PSR-14)
 * once per signature asked about.
 *
 * status:
 *   freed     the nonce is unused: it can never settle, its amount is back in the budget.
 *   used      the nonce was used: the seller settled it after all, it stays spent.
 *   unknown   the chain could not be asked (error says why): it stays spent, asked again next time.
 */
final class BudgetReclaim
{
    public const string FREED = 'freed';
    public const string USED = 'used';
    public const string UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly string $network,
        public readonly string $from,
        public readonly string $nonce,
        public readonly string $amount,
        public readonly ?\Throwable $error = null,
    ) {
    }
}
