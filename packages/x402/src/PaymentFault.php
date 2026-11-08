<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Something the seller's payment state could not do, dispatched (PSR-14) when the library carries on without
 * it: the answer the buyer gets does not change, so without this event nothing would say it happened.
 *
 * op, and what it means:
 *   record       the state after a settle was not stored. The money may have moved and the ledger does not
 *                say so: retries get in_progress until the claim's TTL. Needs a person (or the reconciler).
 *   intend       the write before a settle or a handler was refused: nothing was charged, the buyer got
 *                ledger_unavailable. The store is down.
 *   release      a claim was not freed after a payment that charged nothing: the buyer waits for its TTL.
 *   release_run  an upfront retry's run claim was not freed: other retries wait for its TTL.
 *   close        an open entry was not closed after a settle: the reconciler closes it.
 *   lookup       the facilitator or the chain could not be asked about a pending payment: still pending.
 *
 * key: the ledger's key (Settlement::key), never the signature. error: for logs, it may hold internals.
 */
final class PaymentFault
{
    public const string RECORD = 'record';
    public const string INTEND = 'intend';
    public const string RELEASE = 'release';
    public const string RELEASE_RUN = 'release_run';
    public const string CLOSE = 'close';
    public const string LOOKUP = 'lookup';

    public function __construct(
        public readonly string $op,
        public readonly string $key,
        public readonly string $network,
        public readonly \Throwable $error,
    ) {
    }
}
