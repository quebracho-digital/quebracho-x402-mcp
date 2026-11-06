<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A risky step is about to run, dispatched (PSR-14) just before its intention is written to the ledger:
 * a settle about to be sent ('settling') or a handler about to run ('handling'). A process that dies
 * between the send and the outcome leaves this line in the log, where PaymentOutcome would leave nothing.
 *
 * key: the ledger's key (sha256 of network, payer and nonce), never the signature. payment: a copy with
 * network and from only; the signed payload is not in the event. req: network, payTo, amount, asset.
 * paymentId and transaction: only when a reference already exists (a 'handling' after a settle).
 */
final class PaymentIntent
{
    public function __construct(
        public readonly string $phase,
        public readonly string $key,
        public readonly array $payment,
        public readonly array $req,
        public readonly ?string $paymentId = null,
        public readonly ?string $transaction = null,
    ) {
    }
}
