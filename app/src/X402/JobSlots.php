<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Job\JobSlot;
use Quebracho\X402\Job\JobSlots as JobSlotsInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * The library's JobSlots on Symfony Lock. At most JOB_SLOTS jobs work at once (decision 3): one lock per slot, `job-slot-0` .. `job-slot-N-1`. A `_start` takes a slot
 * BEFORE its deposit settles, so a busy server refuses the sale instead of
 * selling work it cannot start. The slot is held by the PHP process that will
 * run the job (the kernel.terminate runner of the same request); with
 * LOCK_DSN=flock a process that dies frees it.
 */
final class JobSlots implements JobSlotsInterface
{
    /** Longer than any work (the model call stops at 40 s): a lock store with expiry must not free a busy slot. */
    private const TTL = 300.0;

    public function __construct(
        private readonly LockFactory $locks,
        #[Autowire(env: 'int:JOB_SLOTS')] private readonly int $slots,
    ) {
        if ($this->slots < 1) {
            throw new \InvalidArgumentException('JOB_SLOTS must be at least 1.');
        }
    }

    /**
     * A free slot, held until released (or until the lock object is gone), or NULL when all are busy.
     */
    public function take(): ?JobSlot
    {
        for ($i = 0; $i < $this->slots; ++$i) {
            $lock = $this->locks->createLock("job-slot-$i", self::TTL);
            if ($lock->acquire(false)) {
                return new class($lock) implements JobSlot {
                    public function __construct(private readonly LockInterface $lock)
                    {
                    }

                    public function release(): void
                    {
                        $this->lock->release();
                    }
                };
            }
        }

        return null;
    }
}
