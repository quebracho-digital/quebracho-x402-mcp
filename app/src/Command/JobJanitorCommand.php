<?php

declare(strict_types=1);

namespace App\Command;

use App\X402\JobSlots;
use Quebracho\X402\Job\JobJanitor;
use Quebracho\X402\Job\JobReconciler;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobSlots as JobSlotsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * From cron, every minute: `bin/console app:jobs:janitor` (the README has the line). Everything that keeps
 * jobs from waiting on a request that is gone, in this order:
 *
 *   1. reconcile  `settling` and `delivering` jobs whose payment's fate is now known (JobReconciler)
 *   2. run        queued jobs no request ran (no free slot then, or queued by step 1), in free slots
 *   3. sweep      queued or running jobs untouched for --stale seconds: failed_ours / our_timeout, `_result` free
 *   4. forget     delivered results past Job::RESULT_TTL, unbought ones past READY_TTL, buyer text on unresolved
 *                 `settling` rows past PARAMS_TTL
 *
 * Step 2 runs the work in this process: the cron's PHP needs the same env as the web (the model's token).
 */
#[AsCommand(name: 'app:jobs:janitor', description: 'Reconcile, run, fail or clean up the jobs no request is looking after')]
final class JobJanitorCommand
{
    public function __construct(
        private readonly JobJanitor $janitor,
        private readonly JobReconciler $reconciler,
        private readonly JobRunner $runner,
        private readonly JobSlots $slots,
    ) {
    }

    /**
     * @param int $stale seconds without a change: longer than any work (the model call stops at 40 s)
     */
    public function __invoke(SymfonyStyle $io, #[Option('Seconds without a change before a queued or running job counts as abandoned (at least 60)')] int $stale = JobJanitor::STALE_AFTER): int
    {
        $now = time();
        $moved = array_filter($this->reconciler->reconcile($now), static fn (array $r) => 'unknown' !== $r['result']);
        $ran = [];
        foreach ($this->janitor->orphans($now) as $id) {
            // The same slots as the web: the cron never runs more jobs at once than JOB_SLOTS allows.
            $slot = $this->slots->take();
            if (null === $slot) {
                break;
            }
            try {
                $ran[] = $id.' '.$this->runner->run($id)->state;
            } finally {
                $slot->release();
            }
        }
        $failed = $this->janitor->sweep(time(), max(60, $stale));
        $forgotten = $this->janitor->forget(time());
        foreach (['reconciled' => array_map(static fn (array $r) => "{$r['job']} {$r['was']} > {$r['result']}", $moved), 'ran' => $ran, 'stale job(s) failed' => $failed, 'forgotten' => $forgotten] as $what => $ids) {
            $io->writeln(\sprintf('%d %s%s', \count($ids), $what, [] === $ids ? '.' : ': '.implode(', ', $ids)));
        }

        return 0;
    }
}
