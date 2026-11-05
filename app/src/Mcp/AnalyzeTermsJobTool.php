<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Job\IllegalTransition;
use App\Job\JobStatus;
use App\Job\JobStore;
use App\X402\JobDesk;
use App\X402\PaidJob;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use Quebracho\X402\X402McpMiddleware;

/**
 * analyze_terms in job mode (video/debate-r2-plan.md): three tools around one job.
 *
 *   analyze_terms_start   pays a deposit (settled BEFORE it runs, non-refundable) and answers with a job id
 *   analyze_terms_status  free: where the job is, for its payer
 *   analyze_terms_result  pays the rest and answers with the analysis; free of charge if the job failed by
 *                         our fault (it is refused before any payment is claimed)
 *
 * The tools are thin: who may pay, when the money moves and the job's state are JobDesk's, the work is
 * App\Terms\AnalyzeTermsWork, run after the response by App\Job\JobRunner. Prices are the #[PaidJob]
 * ones; Pricing charges every #[PaidJob] tool, listed in X402_PAID_TOOLS or not.
 */
final class AnalyzeTermsJobTool
{
    public const START = 'analyze_terms_start';
    public const RESULT = 'analyze_terms_result';
    public const STATUS = 'analyze_terms_status';

    public function __construct(
        private readonly JobDesk $desk,
        private readonly JobStore $jobs,
    ) {
    }

    /**
     * @param string $text      the terms of service or privacy policy, up to 1200 words
     * @param bool   $sensitive skip the 7-day memo, and keep the analysis out of the payment ledger
     */
    #[McpTool(name: self::START, description: 'SUPER BETA, not legal advice. Starts the analyze_terms analysis as a job and answers with its jobId. Paid upfront: a deposit settled before the job is queued, NOT refundable. If the job fails by our fault (provider error, unreadable model reply, our timeout, our daily cap), analyze_terms_result refuses without charging. Follow it with analyze_terms_status, then analyze_terms_result. A retry with the same payment gets the same job. Your text is kept on this server only until the analysis ran.', meta: [PaidJob::META => ['role' => 'start', 'pairedWith' => self::RESULT]])]
    #[PaidJob('start', pairedWith: self::RESULT, price: '0.01')]
    public function start(string $text, RequestContext $context, bool $sensitive = false): string
    {
        $payment = $context->getRequest()->getMeta()[X402McpMiddleware::PAYMENT] ?? null;
        try {
            // The deposit settled already (upfront): a failure here is after the money moved, and is logged as
            // failed_after_settle by the middleware; the `settling` row stays for the reconciler.
            $job = $this->desk->queue(self::START, ['text' => $text, 'sensitive' => $sensitive], \is_array($payment) ? $payment : null);
        } catch (\Throwable $e) {
            throw new ToolCallException('The job could not be queued; your deposit is on record for a refund by hand.', 0, $e);
        }

        return self::json(['notice' => AnalyzeTermsTool::NOTICE, 'jobId' => $job->id, 'status' => self::STATUS, 'result' => self::RESULT] + JobStatus::of($job->id, $job, $job->payer));
    }

    /**
     * @param string $jobId what analyze_terms_start answered
     * @param string $payer the address that paid the deposit
     */
    #[McpTool(name: self::STATUS, description: 'Free. Where an analyze_terms job is: state (settling, queued, running, ready, delivering, delivered, failed_ours or unknown), reason, retryable and retryAfter in seconds. Give the jobId and the address that paid the deposit; any other address gets unknown, as for a job that does not exist.')]
    public function status(string $jobId, string $payer): string
    {
        // ponytail: the payer is a public address, so this check only keeps a leaked jobId from being read with
        // a wrong one; the jobId (128 random bits) is the real secret. A signed challenge would prove the payer.
        return self::json(JobStatus::of($jobId, $this->jobs->get($jobId), $payer));
    }

    /**
     * @param string $jobId what analyze_terms_start answered
     */
    #[McpTool(name: self::RESULT, description: 'SUPER BETA, not legal advice. The analysis of a ready analyze_terms job, paid after it is delivered. Only the payer of the deposit can buy it, once; a retry with the same payment gets the same answer (unless sensitive). Refused without charge when the job is not ready, is being paid for, or failed by our fault.', meta: [PaidJob::META => ['role' => 'result', 'pairedWith' => self::START]])]
    #[PaidJob('result', pairedWith: self::START, price: '0.01')]
    public function result(string $jobId): string
    {
        try {
            // ready > delivering, atomically: of two payments racing for one job, one gets it; the other's
            // isError is not charged (the default flow settles only a delivered answer).
            $job = $this->desk->deliver($jobId);
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
