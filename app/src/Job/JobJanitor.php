<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Gives up on work nobody is doing: a `running` job whose runner died (a PHP-FPM worker killed, a deploy)
 * or a `queued` one no runner took, untouched for $staleAfter seconds, becomes failed_ours / our_timeout,
 * so its buyer stops waiting and `_result` costs nothing. Run from cron (app:jobs:janitor).
 *
 * Never touches `settling` (whether the deposit moved is the reconciler's question, step 6) nor
 * `delivering` (the `_result` payment may be on chain).
 */
final class JobJanitor
{
    public function __construct(private readonly JobStore $jobs)
    {
    }

    /**
     * @return list<string> the ids it failed
     */
    public function sweep(int $now, int $staleAfter): array
    {
        $failed = [];
        foreach ($this->jobs->all() as $job) {
            if (!$this->stale($job, $now, $staleAfter)) {
                continue;
            }
            try {
                // Checked again under the lock: the runner may have finished since all() read it.
                $this->jobs->update($job->id, fn (Job $j) => $this->stale($j, $now, $staleAfter) ? $j->to(Job::FAILED_OURS, $now, reason: 'our_timeout') : throw new IllegalTransition('no longer stale'));
                $failed[] = $job->id;
            } catch (IllegalTransition) {
            }
        }

        return $failed;
    }

    private function stale(Job $job, int $now, int $staleAfter): bool
    {
        return \in_array($job->state, [Job::QUEUED, Job::RUNNING], true) && $job->updatedAt <= $now - $staleAfter;
    }
}
