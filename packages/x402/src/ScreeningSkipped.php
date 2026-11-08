<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A screening list that fails open could not answer, so the address passed it unchecked: dispatched (PSR-14)
 * by TokenBlacklist, ChainalysisApi and ChainalysisOracle built with $failOpen. Without it the pass would leave
 * no trace. A list that fails closed rejects instead (AddressRejected, address_check_failed).
 *
 * by: the list's class. error: why it could not answer, for logs; it may hold internals.
 */
final class ScreeningSkipped
{
    public function __construct(
        public readonly string $role,
        public readonly string $network,
        public readonly string $address,
        public readonly string $by,
        public readonly \Throwable $error,
    ) {
    }
}
