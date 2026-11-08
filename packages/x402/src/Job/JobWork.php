<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * The work one `_start` tool sells: what the runner calls once the deposit settled. One per job tool,
 * found by start(), the `_start` tool's name.
 */
interface JobWork
{
    /** The `_start` tool this work belongs to. */
    public static function start(): string;

    /**
     * Why these arguments cannot be sold, or NULL. Runs before any payment is read (the $validate hook):
     * the deposit is settled before the work runs, so a mistake the work would refuse must be caught here,
     * or the buyer pays for nothing.
     */
    public function check(array $args): ?string;

    /**
     * Why we cannot take a deposit now, a reason from Job::FAILURES (our daily cap), or NULL. Runs once the
     * signature proved who pays, before the deposit settles: a failure we already know of is refused, not sold.
     */
    public function refuse(): ?string;

    /**
     * Does the work. Its answer is stored until delivered: keep it to what `_result` sends.
     *
     * @throws JobFailed for a failure from Job::FAILURES
     */
    public function run(array $args): array;
}
