<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * Where paid jobs live: durable (never a cache: clearing it would wipe a paid job).
 * Writes are atomic and serialised, so two changes to one job never lose each other.
 */
interface JobStore
{
    /**
     * Saves a new job, unless one already has its settlementKey: then that one is returned, unchanged
     * (`_start` idempotent on the deposit).
     *
     * @throws \RuntimeException on an I/O failure; nothing saved
     */
    public function create(Job $job): Job;

    /** NULL when no job has that id (a malformed id included). */
    public function get(string $id): ?Job;

    public function bySettlement(string $settlementKey): ?Job;

    /**
     * Reads the job, applies $change to it and writes the result, all under one lock: a transition is
     * checked against the state as it is now, not as some earlier read saw it.
     *
     * @param \Closure(Job): Job $change
     *
     * @throws \OutOfBoundsException when no job has that id
     * @throws IllegalTransition     from $change; nothing written
     * @throws \RuntimeException     on an I/O failure; nothing written
     */
    public function update(string $id, \Closure $change): Job;

    /**
     * Forgets a job whose deposit was clearly refused (nothing moved): only a `settling` one, so a paid
     * job can never be deleted by mistake. Another state, or no such job, is left as it is.
     *
     * @return bool whether it was deleted
     */
    public function discard(string $id): bool;

    /**
     * Every job, for the janitor and the reconciler. A single host has few: no index, no paging.
     *
     * @return iterable<Job>
     */
    public function all(): iterable;
}
