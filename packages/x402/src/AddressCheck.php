<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A PSR-14 event: may this address take part in this payment?
 *
 * The seller asks it about the payer once the signature proved who that is,
 * before anything is claimed or run. The buyer asks it about `payTo` before
 * signing. Any listener (a sanctions list, an allowlist, an API) may reject;
 * the first rejection stops the others.
 *
 * OFAC's SDN list (OfacSdn) is always asked first, with or without a
 * dispatcher: a listed address is rejected before any listener runs. Other
 * lists subscribe to this event.
 *
 * A slow listener (an API) defers instead: it starts its lookup and hands
 * over a closure with the answer. The seller reads it just before settling,
 * so the lookup runs while the tool does; the buyer reads it before signing.
 */
final class AddressCheck implements StoppableEventInterface
{
    public const string PAYER = 'payer';
    public const string PAY_TO = 'payTo';

    private ?string $reason = null;
    /** The exception behind address_check_failed, for AddressRejected. */
    private ?\Throwable $error = null;
    /** Where AddressRejected and ScreeningSkipped go: the dispatcher start() was given. */
    private ?EventDispatcherInterface $events = null;
    private bool $announced = false;

    /**
     * @var \Closure[]
     */
    private array $later = [];

    /**
     * @param string $role
     *                        self::PAYER (seller side) or self::PAY_TO (buyer side)
     * @param string $address
     *                        In the chain's own form: 0x… on EVM, an account ID on NEAR
     * @param array  $req
     *                        The payment requirement this address is part of
     */
    public function __construct(
        public readonly string $role,
        public readonly string $network,
        public readonly string $address,
        public readonly array $req,
    ) {
    }

    public function reject(string $reason): void
    {
        $this->reason = $reason;
    }

    /**
     * A list that fails open could not answer and lets the address pass: reported as ScreeningSkipped, so
     * the pass leaves a trace. $by is the list's class.
     */
    public function skip(string $by, \Throwable $error): void
    {
        Events::report($this->events, new ScreeningSkipped($this->role, $this->network, $this->address, $by, $error));
    }

    /**
     * @param \Closure $later
     *                        fn(): ?string — the reason to reject, or NULL; it may block until the
     *                        lookup answers. Throwing is a rejection.
     */
    public function defer(\Closure $later): void
    {
        $this->later[] = $later;
    }

    /**
     * The rejection known now, without waiting for deferred lookups.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * The final rejection, or NULL: waits for every deferred lookup.
     */
    public function verdict(): ?string
    {
        foreach ($this->later as $later) {
            if (null !== $this->reason) {
                break;
            }
            try {
                $this->reason = $later();
            } catch (\Throwable $e) {
                $this->reason = 'address_check_failed';
                $this->error = $e;
            }
        }
        $this->later = [];
        $this->announce();

        return $this->reason;
    }

    /**
     * AddressRejected, once, when the check has a reason.
     */
    private function announce(): void
    {
        if (null === $this->reason || $this->announced) {
            return;
        }
        $this->announced = true;
        Events::report($this->events, new AddressRejected($this->role, $this->network, $this->address, $this->reason, $this->error));
    }

    public function isPropagationStopped(): bool
    {
        return null !== $this->reason;
    }

    /**
     * Asks OFAC's SDN list ($ofac, or the one shipped with the library), then
     * dispatches the check to the listeners, if there is a dispatcher.
     *
     * Fails closed: an address it cannot read, a list that cannot be read, or
     * a listener that throws, is a rejection, not a pass.
     */
    public static function start(?EventDispatcherInterface $events, string $role, ?string $address, array $req, ?OfacSdn $ofac = null): self
    {
        $check = new self($role, (string) $req['network'], (string) $address, $req);
        $check->events = $events;
        if (null === $address || '' === $address) {
            $check->reject('invalid_payload');
        } else {
            try {
                ($ofac ?? OfacSdn::bundled())($check);
                if (null === $check->reason) {
                    $events?->dispatch($check);
                }
            } catch (\Throwable $e) {
                $check->reject('address_check_failed');
                $check->error = $e;
            }
        }
        // A deferred lookup's rejection is announced by verdict().
        $check->announce();

        return $check;
    }

    /**
     * Why this address may not take part, or NULL when it may; waits for
     * deferred lookups.
     */
    public static function run(?EventDispatcherInterface $events, string $role, ?string $address, array $req, ?OfacSdn $ofac = null): ?string
    {
        return self::start($events, $role, $address, $req, $ofac)->verdict();
    }
}
