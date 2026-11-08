<?php

declare(strict_types=1);

namespace App\Mcp;

use App\X402\JobDelivery;
use App\X402\JobDeposit;
use App\X402\PaidJob;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use Quebracho\X402\Job\IllegalTransition;
use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobStatus;
use Quebracho\X402\X402McpMiddleware;

/**
 * analyze_terms in job mode (video/debate-r2-plan.md): three tools around one job.
 *
 *   analyze_terms_start   pays a deposit (settled BEFORE it runs, non-refundable) and answers with a job id
 *   analyze_terms_status  free: where the job is, for its payer
 *   analyze_terms_result  pays the rest and answers with the analysis; free of charge if the job failed by
 *                         our fault (it is refused before any payment is claimed)
 *
 * The tools are thin: who may pay, when the money moves and the job's state are JobDeposit's (the deposit) and JobDelivery's (the result), the work is
 * App\Terms\AnalyzeTermsWork, run after the response by Quebracho\X402\Job\JobRunner. Prices are the #[PaidJob]
 * ones; Pricing charges every #[PaidJob] tool, listed in X402_PAID_TOOLS or not.
 */
final class AnalyzeTermsJobTool
{
    public const START = 'analyze_terms_start';
    public const RESULT = 'analyze_terms_result';
    public const STATUS = 'analyze_terms_status';

    public function __construct(
        private readonly JobDeposit $deposit,
        private readonly JobDelivery $delivery,
    ) {
    }

    /**
     * @param string $text      the terms of service or privacy policy, up to 1200 words
     * @param bool   $sensitive skip the 7-day memo (terms.cache, which keeps the analysis and its quotes), and
     *                          keep the analysis out of the payment ledger
     */
    #[McpTool(name: self::START, description: 'SUPER BETA, not legal advice. Starts the analyze_terms analysis as a job and answers with its jobId. Paid upfront: a deposit settled before the job is queued, NOT refundable. If the job fails by our fault (provider error, unreadable model reply, our timeout, our daily cap), analyze_terms_result refuses without charging. Follow it with analyze_terms_status, then analyze_terms_result. A retry with the same payment gets the same job, and any refusal that comes after the job exists (settlement_pending, needs_review, facilitator_unavailable, an expired signature) carries its jobId in structuredContent.jobId: poll analyze_terms_status with it instead of paying again. Your text is sent to an external model provider (Hugging Face Inference Providers). Unless sensitive is true, the analysis, which quotes your text, is cached on this server for 7 days so the same text is not sent to the model twice. The job keeps your text until the analysis ran, and its result until you buy it (5 minutes more for a free re-read) or for a day if you never do; a deposit that cannot be confirmed keeps your text for at most a day. Those deletions are done by the server\'s cron job, so they can come later if it does not run.', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => self::RESULT]])]
    #[PaidJob('start', pairedWith: self::RESULT, price: '0.01')]
    public function start(string $text, RequestContext $context, bool $sensitive = false): string
    {
        $payment = $context->getRequest()->getMeta()[X402McpMiddleware::PAYMENT] ?? null;
        try {
            // The deposit settled already (upfront): a failure here is after the money moved, and is logged as
            // failed_after_settle by the middleware; the `settling` row stays for the reconciler.
            $job = $this->deposit->queue(self::START, ['text' => $text, 'sensitive' => $sensitive], \is_array($payment) ? $payment : null);
        } catch (\Throwable $e) {
            throw new ToolCallException('The job could not be queued; your deposit is on record for a refund by hand.', 0, $e);
        }

        return self::json(['notice' => AnalyzeTermsTool::NOTICE, 'jobId' => $job->id, 'status' => self::STATUS, 'result' => self::RESULT] + JobStatus::of($job->id, $job, $job->payer));
    }

    /**
     * @param string $jobId what analyze_terms_start answered
     * @param string $payer the address that paid the deposit
     */
    #[McpTool(name: self::STATUS, description: 'Free. Where an analyze_terms job is: state (settling, queued, running, ready, delivering, delivered, failed_ours, expired when a ready result was never bought within a day, or unknown), reason, retryable and retryAfter in seconds. Give the jobId and the address that paid the deposit; any other address gets unknown, as for a job that does not exist.')]
    public function status(string $jobId, string $payer): string
    {
        // ponytail: the payer is a public address, so this check only keeps a leaked jobId from being read with
        // a wrong one; the jobId (128 random bits) is the real secret. A signed challenge would prove the payer.
        // Through JobDelivery: a job nobody is moving is looked at first, so polling it ends.
        return self::json($this->delivery->status($jobId, $payer));
    }

    /**
     * @param string $jobId what analyze_terms_start answered
     */
    #[McpTool(name: self::RESULT, description: 'SUPER BETA, not legal advice. The analysis of a ready analyze_terms job, paid after it is delivered. Only the payer of the deposit can buy it, once; a retry with the same payment gets the same answer (unless sensitive), and for '.Job::RESULT_TTL.' seconds after it was paid the same payer may sign again to read it once more, with nothing charged. Refused without charge when the job is not ready, is being paid for, failed by our fault, or expired (a ready result not bought within a day is deleted).', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => self::START]])]
    #[PaidJob('result', pairedWith: self::START, price: '0.01')]
    public function result(string $jobId): string
    {
        try {
            // ready > delivering, atomically: of two payments racing for one job, one gets it; the other's
            // isError is not charged (the default flow settles only a delivered answer).
            $job = $this->delivery->deliver($jobId);
        } catch (IllegalTransition|\OutOfBoundsException $e) {
            throw new ToolCallException('This job is not ready for this payment. Nothing was charged; see analyze_terms_status.', 0, $e);
        }

        return self::json(['notice' => AnalyzeTermsTool::NOTICE, 'jobId' => $job->id] + ($job->result ?? []));
    }

    private static function json(array $answer): string
    {
        return json_encode($answer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
