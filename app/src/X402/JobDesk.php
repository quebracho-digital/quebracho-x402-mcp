<?php

declare(strict_types=1);

namespace App\X402;

use App\Job\IllegalTransition;
use App\Job\Job;
use App\Job\JobRunner;
use App\Job\JobStore;
use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where job mode meets the payment: the hooks X402McpMiddleware calls for a #[PaidJob] tool, and what the
 * job tools and the runner call. One instance per request; its state is this request's call.
 *
 * A `_start` (the deposit, settled before the tool runs, extra.paymentFlow = upfront):
 *
 *   validate()  the work's own check of the input, before any payment is read: a text it would refuse is
 *               never paid for (the deposit settles before the tool could refuse it)
 *   payer()     once the signature proved who pays: our own known failures (the daily cap) and a free
 *               concurrency slot, both before the money moves
 *   settle()    writes the `settling` job row, THEN settles. A crash between the two leaves a row, keyed by
 *               the deposit's Settlement::key, for the reconciler; a row that cannot be written sends nothing
 *   queue()     the tool, after the settle: settling > queued, the same job for the same payment
 *   afterResponse()  kernel.terminate: runs the queued job in the slot payer() took
 *
 * A `_result` (the rest, the default flow: the tool answers, then the payment settles):
 *
 *   payer()     only the deposit's payer, only a ready job: a second payment while one is settling is
 *               refused before it is claimed (decision 7e), a failed_ours job is refused (it costs nothing)
 *   deliver()   the tool: ready > delivering, atomically (of two concurrent payments one delivers)
 *   settle()    after the facilitator answered: delivered, or back to ready when nothing moved
 */
final class JobDesk implements ResetInterface
{
    /**
     * Settle failures after which the money may still move: Sale::settle keeps the claim for exactly these.
     * Any other failure without a transaction moved nothing.
     */
    private const MAY_HAVE_MOVED = ['settlement_pending', 'facilitator_unavailable', 'unexpected_settle_error', 'invalid_settle_response'];

    /** @var ?array{name: string, role: string, args: array, from: string} this request's job call, from payer() */
    private ?array $call = null;
    private ?LockInterface $slot = null;
    /** The job this request queued: afterResponse() runs it. */
    private ?string $queued = null;
    /** The job this request moved to delivering, until its settle was sent. */
    private ?string $delivering = null;

    /**
     * @param \Closure                                                                                            $settle the facilitator's settle (App\X402\Facilitator)
     * @param array<string, array{job: ?array{role: string, pairedWith: string, price: ?string}, schema: string}> $tools
     * @param ?\Closure(): int                                                                                    $clock
     */
    public function __construct(
        private readonly JobStore $jobs,
        private readonly JobRunner $runner,
        private readonly JobSlots $slots,
        private readonly Pricing $pricing,
        private readonly SensitiveRules $sensitiveRules,
        private readonly \Closure $settle,
        #[Autowire(param: 'app.mcp_tools')] private readonly array $tools,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * X402McpMiddleware's $validate: the schema (Pricing), then for a `_start` its work's own check.
     */
    public function validate(array $params): ?string
    {
        $invalid = $this->pricing->validate($params);
        if (null !== $invalid || 'start' !== $this->role($params['name'])) {
            return $invalid;
        }
        // Fails closed: a _start tool with no work behind it would take a deposit for nothing.
        $work = $this->runner->work($params['name']);

        return null === $work ? "Tool {$params['name']} has no work to run." : $work->check($params['arguments'] ?? []);
    }

    /**
     * X402McpMiddleware's $payer: before the claim, why $from cannot buy this call, or NULL. Never throws
     * (it runs inside Sale::admit): a store that cannot answer refuses, nothing is charged.
     */
    public function payer(array $params, string $from): ?string
    {
        $role = $this->role($params['name']);
        if (null === $role) {
            return null;
        }
        $args = $params['arguments'] ?? [];
        $this->call = ['name' => $params['name'], 'role' => $role, 'args' => $args, 'from' => $from];
        try {
            if ('start' === $role) {
                $refused = $this->runner->work($params['name'])?->refuse();
                // Taken now and held to the end of the request: the runner works in it after the response.
                $this->slot ??= null === $refused ? $this->slots->take() : null;

                return $refused ?? (null === $this->slot ? 'job_slots_busy' : null);
            }
            $job = \is_string($args['jobId'] ?? null) ? $this->jobs->get($args['jobId']) : null;
            // Another payer's job, or one of another tool, is answered exactly like no job: nothing to learn here.
            if (null === $job || !$job->paidBy($from) || $job->tool !== $this->tools[$params['name']]['job']['pairedWith']) {
                return 'unknown_job';
            }

            return match ($job->state) {
                Job::READY => null,
                // Ours: the deposit is not refunded (decision 1), and the result is not charged for.
                Job::FAILED_OURS => 'job_failed_ours',
                // Another `_result` payment is being settled: this one is refused before it is claimed.
                Job::DELIVERING => 'result_pending',
                // Paid once. A retry of THAT payment is replayed by the ledger before this hook runs.
                Job::DELIVERED => 'already_delivered',
                default => 'job_not_ready',
            };
        } catch (\Throwable) {
            return 'jobs_unavailable';
        }
    }

    /**
     * X402McpMiddleware's $settle, around the facilitator's. Never throws after the facilitator answered:
     * Settlement::run would turn the throw into a failed receipt, and withhold an answer that was paid for.
     */
    public function settle(array $payment, array $req, ?\Closure $reference = null): array
    {
        $call = $this->call;
        $job = null;
        if ('start' === ($call['role'] ?? null)) {
            try {
                $job = $this->jobs->create(Job::settling($call['from'], Settlement::key($payment), $call['name'], $call['args'], $this->now(), $this->sensitiveCall($call)));
            } catch (\Throwable) {
                // Nothing was sent: a clear refusal, so the library frees the claim and the same payment can be retried.
                return ['success' => false, 'errorReason' => 'jobs_unavailable', 'transaction' => '', 'network' => $req['network']];
            }
        }
        if ('result' === ($call['role'] ?? null)) {
            // From here the money may move: whatever happens, never put it back to ready blindly.
            $this->delivering = null;
        }
        $receipt = null === $reference ? ($this->settle)($payment, $req) : ($this->settle)($payment, $req, $reference);
        $moved = true === ($receipt['success'] ?? null);
        $nothing = !$moved && '' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'] ?? null, self::MAY_HAVE_MOVED, true);
        try {
            if (null !== $job && $nothing) {
                // The deposit was clearly refused: no job, and the buyer's text off disk.
                $this->jobs->discard($job->id);
            }
            if ('result' === ($call['role'] ?? null) && ($moved || $nothing)) {
                // Paid: delivered (the result leaves the job). Refused: ready again, the buyer may pay once more.
                // Unknown (pending): left delivering, so no second payment is taken; the reconciler decides.
                $this->jobs->update($call['args']['jobId'], fn (Job $j) => Job::DELIVERING === $j->state ? $j->to($moved ? Job::DELIVERED : Job::READY, $this->now()) : $j);
            }
        } catch (\Throwable) {
            // ponytail: the receipt stands (money may have moved); the row is left for the reconciler.
        }

        return $receipt;
    }

    /**
     * X402McpMiddleware's $sensitive: whether the ledger must not keep this call's answer.
     */
    public function sensitive(array $params): bool
    {
        $call = ['name' => $params['name'], 'args' => $params['arguments'] ?? []];
        try {
            return match ($this->role($params['name'])) {
                // A job id, no buyer text: always kept, so a retry of the same deposit gets the same job (7c)
                // instead of already_settled. A sensitive deposit marks its job instead (see the result).
                'start' => false,
                // The analysis quotes the buyer's text: kept only when neither this call nor the deposit said no.
                'result' => $this->sensitiveCall($call) || false !== $this->jobs->get((string) ($call['args']['jobId'] ?? ''))?->sensitive,
                default => $this->sensitiveCall($call),
            };
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * For the `_start` tool, once the deposit settled (it runs only then): the job, queued. The same payment
     * always gets the same job (decision 7c), whatever state it reached.
     *
     * @param ?array $payment params._meta["x402/payment"] of this call
     *
     * @throws \RuntimeException when the job cannot be read or written
     */
    public function queue(string $name, array $args, ?array $payment): Job
    {
        if (null === $payment) {
            // ponytail: unreachable, the middleware runs a paid tool only with its payment.
            throw new \LogicException("$name ran without a payment.");
        }
        $job = $this->jobs->bySettlement(Settlement::key($payment));
        if (null === $job) {
            // Settled, but no row was written before: only when the settle did not go through settle() above (a
            // wiring mistake). The money moved, so the job is written now rather than refused.
            $from = $this->call['from'] ?? $payment['payload']['authorization']['from'] ?? throw new \LogicException('No payer for the deposit.');
            $job = $this->jobs->create(Job::settling($from, Settlement::key($payment), $name, $args, $this->now(), $this->sensitiveCall(['name' => $name, 'args' => $args])));
        }
        if (Job::SETTLING === $job->state) {
            $job = $this->jobs->update($job->id, fn (Job $j) => Job::SETTLING === $j->state ? $j->to(Job::QUEUED, $this->now()) : $j);
        }
        if (Job::QUEUED === $job->state) {
            $this->queued = $job->id;
        }

        return $job;
    }

    /**
     * For the `_result` tool: ready > delivering, under the store's lock, for the payer this call proved.
     *
     * @throws IllegalTransition     when the job is not ready any more (another payment got it first)
     * @throws \OutOfBoundsException when there is no such job
     */
    public function deliver(string $jobId): Job
    {
        $call = $this->call;
        if ('result' !== ($call['role'] ?? null) || ($call['args']['jobId'] ?? null) !== $jobId) {
            throw new IllegalTransition("This call did not pay for job $jobId.");
        }
        $job = $this->jobs->update($jobId, fn (Job $j) => $j->paidBy($call['from']) ? $j->to(Job::DELIVERING, $this->now()) : throw new IllegalTransition("Job $jobId has another payer."));
        $this->delivering = $jobId;

        return $job;
    }

    /**
     * kernel.terminate, after the response went out (Response::send calls fastcgi_finish_request).
     */
    public function afterResponse(): void
    {
        try {
            if (null !== $this->delivering) {
                // Handed over but never settled (the middleware withheld the answer: its ledger refused the write,
                // a late screening verdict): nothing was charged, so the buyer may pay again.
                $this->jobs->update($this->delivering, fn (Job $j) => Job::DELIVERING === $j->state ? $j->to(Job::READY, $this->now()) : $j);
            }
            if (null !== $this->queued) {
                // A retry that queued the job never went through payer(), so it holds no slot yet. None free: the
                // job stays queued and the janitor gives up on it (failed_ours), rather than overload the model.
                $this->slot ??= $this->slots->take();
                if (null !== $this->slot) {
                    $this->runner->run($this->queued);
                }
            }
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->slot?->release();
        $this->slot = null;
        $this->call = null;
        $this->queued = null;
        $this->delivering = null;
    }

    private function role(string $name): ?string
    {
        return $this->tools[$name]['job']['role'] ?? null;
    }

    /**
     * Whether the sensitive rules (config/x402_sensitive.yaml, X402_SENSITIVE_TOOLS) mark this call. A failing
     * rule counts as sensitive, as in the library: the safe side only drops a copy.
     *
     * @param array{name: string, args: array} $call
     */
    private function sensitiveCall(array $call): bool
    {
        try {
            return false !== ($this->sensitiveRules)(['name' => $call['name'], 'arguments' => $call['args']]);
        } catch (\Throwable) {
            return true;
        }
    }

    private function now(): int
    {
        return null === $this->clock ? time() : ($this->clock)();
    }
}
