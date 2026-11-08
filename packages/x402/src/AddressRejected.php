<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * An address was refused by the screening (AddressCheck), dispatched (PSR-14) once per check, on both sides:
 * the payer a seller would not sell to (role payer), the payTo a buyer would not pay (role payTo).
 *
 * reason: what the check answered (address_sanctioned, address_check_failed, a listener's own). error: the
 * exception behind address_check_failed (a listener or a list that threw), for logs; it may hold internals.
 */
final class AddressRejected
{
    public function __construct(
        public readonly string $role,
        public readonly string $network,
        public readonly string $address,
        public readonly string $reason,
        public readonly ?\Throwable $error = null,
    ) {
    }
}
