<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Terms\TermsAnalyzer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Quebracho\X402\SensitiveRules;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Paid (X402_PAID_TOOLS, price in X402_PRICES): the Hugging Face call has a cost.
 * A refusal or a failed model call throws, so nothing is charged for it.
 */
final class AnalyzeTermsTool
{
    public const NAME = 'analyze_terms';
    public const NOTICE = 'SUPER BETA: an example for the hackathon demo, not legal advice. A model can miss clauses or flag the wrong ones. Read the quotes in the document before you rely on them.';

    public function __construct(
        private readonly TermsAnalyzer $analyzer,
        // For the whole server per day, here: only a payment already verified and claimed spends it, so forged
        // payments cannot use up everybody's day. The per-client hour is McpRateLimit's, before the payment is read.
        #[Target('terms_day.limiter')] private readonly RateLimiterFactoryInterface $perDay,
        // The same rules that keep the ledger's copy out (config/x402_sensitive.yaml, X402_SENSITIVE_TOOLS): a call they mark
        // skips the memo too, not only the ledger. Without this a tool rule kept the answer out of the ledger but not out of the memo.
        private readonly SensitiveRules $sensitiveRules,
    ) {
    }

    #[McpTool(name: self::NAME, description: 'SUPER BETA, an example for the hackathon demo, not legal advice. Flags the clauses of a terms of service or privacy policy (up to 1200 words) in 19 categories, each with a quote checked against your text. Answers are memoised for 7 days by a hash of the text, so a repeated text answers faster; set sensitive to true to skip the memo (not read, not written) and the payment ledger\'s copy of the answer: the model is always called, and a retry of a settled payment gets already_settled instead of the answer. Paid.')]
    public function __invoke(string $text, bool $sensitive = false): string
    {
        // A bad text costs nothing, so it must not use up anybody's limit.
        try {
            $text = TermsAnalyzer::check($text);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
        $sensitive = $sensitive || ($this->sensitiveRules)(['name' => self::NAME, 'arguments' => ['sensitive' => $sensitive]]);
        // Before the model call: a refused call throws, so nothing is charged and the model is not called.
        if (!$this->perDay->create('all')->consume()->isAccepted()) {
            throw new ToolCallException('Rate limit reached for analyze_terms (a super beta example with a daily budget). Nothing was charged; try later.');
        }
        try {
            $result = $this->analyzer->analyze($text, $sensitive);
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        return json_encode(['notice' => self::NOTICE] + $result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
