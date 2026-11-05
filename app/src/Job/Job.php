<?php

declare(strict_types=1);

namespace App\Job;

/**
 * One paid job (video/debate-r2-plan.md, decisions 1 and 7): the deposit, the work, and the paid
 * delivery of its result. Immutable: a transition returns a new Job; JobStore::update writes it.
 *
 * The life of a job, and why each state exists:
 *
 *     settling ─> queued ─> running ─> ready ─> delivering ─> delivered
 *                   │          │                    │
 *                   └──────────┴─> failed_ours      └─> ready (the `_result` payment did not settle)
 *
 * - settling: written BEFORE the deposit is settled (Leo, 2026-11-05). A crash between the settle and
 *   the next write leaves this row, keyed by the deposit's Settlement::key, for the reconciler to find.
 *   Nothing runs from here: only a confirmed settle moves it on.
 * - queued: the deposit settled. The tool name and its input are kept, so the work can be resumed.
 * - failed_ours: our failure by the fixed list below; `_result` costs nothing.
 */
final class Job
{
    public const SETTLING = 'settling';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const READY = 'ready';
    public const DELIVERING = 'delivering';
    public const DELIVERED = 'delivered';
    public const FAILED_OURS = 'failed_ours';

    /**
     * From each state, where it may go. delivered and failed_ours are final. There is no way back to
     * settling and no way out of it but forward: whether the deposit moved is the reconciler's call.
     */
    public const TRANSITIONS = [
        self::SETTLING => [self::QUEUED],
        self::QUEUED => [self::RUNNING, self::FAILED_OURS],
        self::RUNNING => [self::READY, self::FAILED_OURS],
        self::READY => [self::DELIVERING],
        self::DELIVERING => [self::DELIVERED, self::READY],
        self::DELIVERED => [],
        self::FAILED_OURS => [],
    ];

    /**
     * The failures that are ours (decision 1), and the only reasons failed_ours takes: a closed list, so
     * a buyer's mistake can never be filed as ours (and get a free `_result`) by a typo in a reason.
     * The buyer's own mistakes (a bad text) are refused before the deposit is read, and never get a job.
     */
    public const FAILURES = [
        'provider_error', // the model's host failed or refused us
        'invalid_reply',  // the model answered, but not what we can deliver (no JSON object)
        'our_timeout',    // the work outlived its slot (the janitor found it stale)
        'our_daily_cap',  // our own daily budget ran out after the deposit was taken
    ];

    /**
     * @param string  $payer         who paid the deposit (authorization.from); `_result` requires the same payer
     * @param string  $settlementKey Settlement::key of the deposit: `_start` with the same payment gets this job back
     * @param string  $tool          the `_start` tool that sold it: which work to run
     * @param ?array  $params        that tool's arguments, kept only until the work has run (buyer text: off disk after)
     * @param ?array  $result        the work's output, from ready until it is delivered
     * @param ?string $reason        why it failed (failed_ours), one of FAILURES
     * @param bool    $sensitive     the buyer asked that its answer not be kept: `_result`'s answer stays out of the
     *                               payment ledger too (kept here, as the input that said so is gone by then)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $payer,
        public readonly string $settlementKey,
        public readonly string $tool,
        public readonly string $state,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?array $params = null,
        public readonly ?array $result = null,
        public readonly ?string $reason = null,
        public readonly bool $sensitive = false,
    ) {
        if (!isset(self::TRANSITIONS[$state])) {
            throw new \InvalidArgumentException("Unknown job state $state.");
        }
    }

    /**
     * A new job, before its deposit is settled. The id is 128 random bits: it is what the buyer holds
     * to ask for the job, so it must not be guessable.
     */
    public static function settling(string $payer, string $settlementKey, string $tool, array $params, int $now, bool $sensitive = false): self
    {
        return new self(bin2hex(random_bytes(16)), self::normalize($payer), $settlementKey, $tool, self::SETTLING, $now, $now, $params, sensitive: $sensitive);
    }

    /**
     * Whether $payer is who paid the deposit. EVM addresses are hex: compared without case (a checksummed
     * and a lower-case address are one wallet). Anything else byte for byte (Solana's base58 has case).
     */
    public function paidBy(string $payer): bool
    {
        return $this->payer === self::normalize($payer);
    }

    /**
     * @throws IllegalTransition when the table does not allow it, or ready comes without a result, or
     *                           failed_ours without a reason from FAILURES
     */
    public function to(string $state, int $now, ?array $result = null, ?string $reason = null): self
    {
        if (!\in_array($state, self::TRANSITIONS[$this->state], true)) {
            throw new IllegalTransition("Job {$this->id}: {$this->state} cannot go to $state.");
        }
        $result ??= $this->result;
        if (self::READY === $state && null === $result) {
            throw new IllegalTransition("Job {$this->id}: ready needs a result.");
        }
        if (self::FAILED_OURS === $state && !\in_array($reason, self::FAILURES, true)) {
            throw new IllegalTransition("Job {$this->id}: failed_ours needs a reason from Job::FAILURES.");
        }
        // The buyer's input is needed only to run (or resume) the work: once it ran, it goes. The result
        // goes once delivered: the payment ledger keeps the copy a retry replays (unless sensitive).
        // Done here, in the one place every transition passes, so no caller can forget it.
        $params = \in_array($state, [self::SETTLING, self::QUEUED, self::RUNNING], true) ? $this->params : null;
        $result = self::DELIVERED === $state || self::FAILED_OURS === $state ? null : $result;

        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $state, $this->createdAt, $now, $params, $result, self::FAILED_OURS === $state ? $reason : null, $this->sensitive);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $row): self
    {
        return new self($row['id'], $row['payer'], $row['settlementKey'], $row['tool'], $row['state'], $row['createdAt'], $row['updatedAt'], $row['params'] ?? null, $row['result'] ?? null, $row['reason'] ?? null, $row['sensitive'] ?? false);
    }

    private static function normalize(string $payer): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $payer) ? strtolower($payer) : $payer;
    }
}
