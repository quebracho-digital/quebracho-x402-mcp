<?php

declare(strict_types=1);

namespace App\X402;

use App\Job\IllegalTransition;
use App\Job\Job;
use App\Job\JobJanitor;
use App\Job\JobRunner;
use App\Job\JobStatus;
use App\Job\JobStore;
use Quebracho\X402\Authorization;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
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
 *   payer()     once the signature proved who pays: our own known failures (the daily cap), before the money moves.
 *               No slot here: the online verify and the screening have not run yet
 *   settle()    takes a free concurrency slot, writes the `settling` job row, THEN settles. The slot is taken
 *               here, after the online verify and the address screening, so a signature that fails them never
 *               holds one; still before the money moves, so a busy server refuses the sale instead of selling
 *               work it cannot start. A crash after the settle leaves the row, keyed by the deposit's
 *               Settlement::key, for JobReconciler; a row that cannot be written sends nothing
 *   queue()     the tool, after the settle: settling > queued, the same job for the same payment
 *   afterResponse()  kernel.terminate: runs the queued job in the slot settle() took (a retry that never went
 *               through settle() takes one here)
 *
 * And whatever a `_start` answers once its job row exists, a refusal included (settlement_pending, needs_review,
 * facilitator_unavailable, an expired signature on a late retry), carries the jobId: jobIdFor(), added by
 * App\X402\JobIdOnRefusal. The deposit's signature only lives a minute or two and the reconciler looks at a
 * `settling` row only after JobReconciler::MIN_AGE, so a buyer who had to wait for a retry would find its
 * signature expired and never learn the job; with the jobId it polls `_status` instead.
 *
 * A `_result` (the rest, the default flow: the tool answers, then the payment settles):
 *
 *   payer()     only the deposit's payer, only a ready job: a second payment while one is settling is
 *               refused before it is claimed (decision 7e), a failed_ours job is refused (it costs nothing).
 *               A job stuck where nobody will move it is looked at first: stale work is failed (ours, so the
 *               result is free), a `delivering` one asks the ledger how its payment ended (JobReconciler)
 *   deliver()   the tool: ready > delivering, atomically (of two concurrent payments one delivers), with the
 *               payment's Settlement::key on the job, so a lost settle answer can be looked up later
 *   settle()    after the facilitator answered: delivered, or back to ready when nothing moved; when it may
 *               have moved, left delivering for JobReconciler
 *
 * And the free re-read (decision 7e): for Job::RESULT_TTL after it was paid, the payer may call `_result`
 * again with a fresh signature. The signature proves who asks; settle() then sends nothing to the
 * facilitator and answers a receipt that says nothing was charged.
 *
 * Known limit (an example, on purpose): that proof is a real EIP-3009 authorization for the full price, valid and
 * unsettled. It is never sent anywhere, but it stays spendable (to our payTo) until it expires, and the buyer's
 * budget counts it until then. A stronger, cheaper proof would be an EIP-191 signature over the jobId, which
 * this example leaves out.
 */
final class JobDesk implements ResetInterface
{
    /** @var ?array{name: string, role: string, args: array, from: string, payment: ?array, reread: bool} this request's job call, from payer() */
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
        private readonly JobReconciler $reconciler,
        private readonly JobJanitor $janitor,
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
        $payment = $params['_meta'][X402McpMiddleware::PAYMENT] ?? null;
        $this->call = ['name' => $params['name'], 'role' => $role, 'args' => $args, 'from' => $from, 'payment' => \is_array($payment) ? $payment : null, 'reread' => false];
        try {
            if ('start' === $role) {
                // No slot yet: this signature has not passed the online verify (settle() takes it).
                return $this->runner->work($params['name'])?->refuse();
            }
            $job = \is_string($args['jobId'] ?? null) ? $this->jobs->get($args['jobId']) : null;
            // Another payer's job, or one of another tool, is answered exactly like no job: nothing to learn here.
            if (null === $job || !$job->paidBy($from) || $job->tool !== $this->tools[$params['name']]['job']['pairedWith']) {
                return 'unknown_job';
            }
            $job = $this->unstick($job);
            if ($job->rereadable($this->now())) {
                // Paid already, and this signature proves it is the payer: answered again, nothing is settled.
                $this->call['reread'] = true;

                return null;
            }

            if ($job->expired()) {
                // Nobody bought it within Job::READY_TTL: the result was deleted, nothing is sold.
                return 'job_expired';
            }

            return match ($job->state) {
                Job::READY => null,
                // Ours: the deposit is not refunded (decision 1), and the result is not charged for.
                Job::FAILED_OURS => 'job_failed_ours',
                // Another `_result` payment is being settled: this one is refused before it is claimed.
                Job::DELIVERING => 'result_pending',
                // Paid once, and the re-read window is over. A retry of THAT payment is replayed by the ledger before
                // this hook runs (unless sensitive: then nothing was kept to replay).
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
        if (true === ($call['reread'] ?? false)) {
            // The free re-read: the tool answered a delivered job again. Nothing goes to the facilitator, the
            // authorization stays unused (the buyer's wallet frees it when it expires), and the receipt says so.
            return ['success' => true, 'transaction' => '', 'network' => $req['network'], 'payer' => $call['from'], 'extensions' => [PaidJob::META => ['reread' => true, 'charged' => false]]];
        }
        if ('start' === ($call['role'] ?? null)) {
            // A clear refusal (no transaction, a reason outside MAY_HAVE_MOVED): the library frees the claim and
            // the same payment can be retried. Taken now and held to the end of the request: the runner works in
            // it after the response.
            try {
                $this->slot ??= $this->slots->take();
            } catch (\Throwable) {
                // A lock store that cannot answer: the same clear refusal (Settlement::run would turn the throw into
                // unexpected_settle_error, which keeps the claim, and the buyer could not retry for the signature's life).
                return ['success' => false, 'errorReason' => 'job_slots_unavailable', 'transaction' => '', 'network' => $req['network']];
            }
            if (null === $this->slot) {
                return ['success' => false, 'errorReason' => 'job_slots_busy', 'transaction' => '', 'network' => $req['network']];
            }
            try {
                $job = $this->jobs->create(Job::settling($call['from'], Settlement::key($payment), $call['name'], $call['args'], $this->now(), $this->sensitiveCall($call), JobPayments::ref($payment)));
            } catch (\Throwable) {
                // Nothing was sent: refused the same way.
                return ['success' => false, 'errorReason' => 'jobs_unavailable', 'transaction' => '', 'network' => $req['network']];
            }
        }
        if ('result' === ($call['role'] ?? null)) {
            // From here the money may move: whatever happens, never put it back to ready blindly.
            $this->delivering = null;
        }
        $receipt = null === $reference ? ($this->settle)($payment, $req) : ($this->settle)($payment, $req, $reference);
        $moved = true === ($receipt['success'] ?? null);
        $nothing = !$moved && '' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'] ?? null, Settlement::MAY_HAVE_MOVED, true);
        try {
            if (null !== $job && $nothing) {
                // The deposit was clearly refused: no job, and the buyer's text off disk.
                $this->jobs->discard($job->id);
            }
            if ('result' === ($call['role'] ?? null) && ($moved || $nothing)) {
                // Paid: delivered (the result stays Job::RESULT_TTL for a re-read). Refused: ready again, the buyer may
                // pay once more. Unknown (pending, a silent facilitator): left delivering, so no second payment is
                // taken; the job carries this payment's key, and JobReconciler (or the buyer's next call) looks it up.
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
            $job = $this->jobs->create(Job::settling($from, Settlement::key($payment), $name, $args, $this->now(), $this->sensitiveCall(['name' => $name, 'args' => $args]), JobPayments::ref($payment)));
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
     * For the `_result` tool: ready > delivering, under the store's lock, for the payer this call proved, with
     * this payment's key on the job. A re-read gets the delivered job as it is.
     *
     * @throws IllegalTransition     when the job is not ready any more (another payment got it first)
     * @throws \OutOfBoundsException when there is no such job
     */
    public function deliver(string $jobId): Job
    {
        $call = $this->call;
        if ('result' !== ($call['role'] ?? null) || ($call['args']['jobId'] ?? null) !== $jobId || null === $call['payment']) {
            throw new IllegalTransition("This call did not pay for job $jobId.");
        }
        if ($call['reread']) {
            $job = $this->jobs->get($jobId);

            // Read again: the window may have closed since payer(); then nothing is delivered, nothing settled.
            return null !== $job && $job->paidBy($call['from']) && $job->rereadable($this->now()) ? $job : throw new IllegalTransition("Job $jobId cannot be read again.");
        }
        $ref = JobPayments::ref($call['payment']);
        $job = $this->jobs->update($jobId, fn (Job $j) => $j->paidBy($call['from']) && !$j->expired() ? $j->to(Job::DELIVERING, $this->now(), resultRef: $ref) : throw new IllegalTransition("Job $jobId has another payer, or no result left."));
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
                // A retry that queued the job never went through settle(), so it holds no slot yet. None free: the
                // job stays queued, and the janitor's cron run starts it once a slot is free (JobJanitor::orphans),
                // or gives up on it (failed_ours) if it waited too long, rather than overload the model.
                try {
                    $this->slot ??= $this->slots->take();
                } catch (\Throwable) {
                    // ponytail: a lock store that cannot answer: the job stays queued, for the cron.
                }
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

    /**
     * The jobId of the deposit in these tools/call params, for App\X402\JobIdOnRefusal to add to a `_start`
     * refusal, or NULL. Only for the payer: the deposit's EIP-3009 signature must recover to its `from`, checked
     * against our own offer (not the buyer's copy). Its validity window is NOT checked, on purpose: a late
     * retry with an expired signature is exactly the buyer who lost the jobId. A signature, expired or not,
     * proves the key; the job row is found by the deposit's Settlement::key and must be that payer's.
     *
     * ponytail: a lookup by the store's index, not a deterministic id (an HMAC over payer, network and nonce):
     * the index already maps the deposit to its job, and an HMAC would need an app secret this example leaves
     * empty in .env. EVM only, like the deposit itself.
     */
    public function jobIdFor(array $params): ?string
    {
        if ('start' !== $this->role((string) ($params['name'] ?? ''))) {
            return null;
        }
        $payment = $params['_meta'][X402McpMiddleware::PAYMENT] ?? null;
        $auth = \is_array($payment) ? ($payment['payload']['authorization'] ?? null) : null;
        $signature = \is_array($payment) ? ($payment['payload']['signature'] ?? null) : null;
        if (!\is_array($auth) || !\is_string($signature) || !\is_string($auth['from'] ?? null)) {
            return null;
        }
        try {
            $network = $payment['accepted']['network'] ?? null;
            $req = current(array_filter(($this->pricing)($params) ?? [], static fn (array $a) => $a['network'] === $network));
            if (false === $req || !str_starts_with($req['network'], 'eip155:')
                || 0 !== strcasecmp((string) Signer::recover(Authorization::digest($req, $auth), $signature), $auth['from'])) {
                return null;
            }
            $job = $this->jobs->bySettlement(Settlement::key($payment));
        } catch (\Throwable) {
            // A malformed authorization (the encoder throws) or a store that cannot answer: no jobId, the refusal as it was.
            return null;
        }

        return null !== $job && $job->paidBy($auth['from']) && $job->tool === $params['name'] ? $job->id : null;
    }

    /**
     * For the `<tool>_status` tool: where the job is, for its payer, after the same look payer() takes, so a
     * buyer polling a job nobody will move gets an answer that ends (failed_ours, ready, delivered).
     *
     * @return array{jobId: string, state: string, reason: ?string, retryable: bool, retryAfter: ?int}
     */
    public function status(string $jobId, string $payer): array
    {
        $job = $this->jobs->get($jobId);
        if (null !== $job && $job->paidBy($payer)) {
            try {
                $job = $this->unstick($job);
            } catch (\Throwable) {
                // ponytail: the job as read; the cron moves it.
            }
        }

        return JobStatus::of($jobId, $job, $payer);
    }

    /**
     * A job nobody will move: stale work is given up on (JobJanitor::one), a `delivering` one asks the ledger
     * how its payment ended (JobReconciler::delivering; no RPC call from a request, the cron asks the chain).
     */
    private function unstick(Job $job): Job
    {
        return match ($job->state) {
            Job::QUEUED, Job::RUNNING => $this->janitor->one($job, $this->now()),
            Job::DELIVERING => $this->reconciler->delivering($job, $this->now(), false),
            default => $job,
        };
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
