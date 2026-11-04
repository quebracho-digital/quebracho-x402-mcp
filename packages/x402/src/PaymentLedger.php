<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What each payment has become, kept across requests (and processes, when the store is shared).
 *
 * A state is an array: `status` is 'claimed' (in progress), 'settled' (charged, no response kept),
 * 'delivered' (charged, response kept), or 'pending' (may have charged, not confirmed). Settled and
 * pending states carry 'transaction', 'paymentId', 'network' and 'receipt' when known; a response
 * is `['status' => int, 'headers' => array<string,string>, 'body' => string]` (see Sale::capture).
 *
 * Every state expires after its TTL in seconds.
 */
interface PaymentLedger
{
    /**
     * Stores a 'claimed' state unless the key already holds a live one. Atomic: of N concurrent
     * calls exactly one returns TRUE.
     */
    public function claim(string $key, int $ttl): bool;

    /**
     * The stored state, or NULL when there is none (or it expired).
     *
     * @return ?array<string, mixed>
     */
    public function get(string $key): ?array;

    /**
     * Replaces the state and renews the TTL.
     *
     * @param array<string, mixed> $state
     */
    public function put(string $key, array $state, int $ttl): void;

    /**
     * Deletes the key: a payment that charged nothing is good again.
     */
    public function release(string $key): void;
}
