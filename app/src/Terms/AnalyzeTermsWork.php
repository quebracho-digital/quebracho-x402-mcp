<?php

declare(strict_types=1);

namespace App\Terms;

use App\Mcp\AnalyzeTermsJobTool;
use Quebracho\X402\Job\JobFailed;
use Quebracho\X402\Job\JobWork;
use Quebracho\X402\SensitiveRules;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * analyze_terms as a job: the same analysis as the one-shot tool (AnalyzeTermsTool), run after the
 * response by the job runner. The differences come from the deposit being taken before the work runs:
 * a bad text must be refused before (check), and so must a day already spent (refuse).
 */
final class AnalyzeTermsWork implements JobWork
{
    public function __construct(
        private readonly TermsAnalyzer $analyzer,
        // The same daily budget as analyze_terms: one Hugging Face credit, whichever tool spends it.
        #[Target('terms_day.limiter')] private readonly RateLimiterFactoryInterface $perDay,
        private readonly SensitiveRules $sensitiveRules,
    ) {
    }

    public static function start(): string
    {
        return AnalyzeTermsJobTool::START;
    }

    public function check(array $args): ?string
    {
        try {
            TermsAnalyzer::check(\is_string($args['text'] ?? null) ? $args['text'] : '');

            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function refuse(): ?string
    {
        // Peeks (0 tokens): only the work spends a call, so payments that never settle cannot use up the day.
        return 0 === $this->perDay->create('all')->consume(0)->getRemainingTokens() ? 'our_daily_cap' : null;
    }

    public function run(array $args): array
    {
        // Spent now, once the deposit is ours. Refused here (others spent it since refuse()): ours, the result is free.
        if (!$this->perDay->create('all')->consume()->isAccepted()) {
            throw new JobFailed('our_daily_cap');
        }
        $sensitive = true === ($args['sensitive'] ?? false) || ($this->sensitiveRules)(['name' => self::start(), 'arguments' => $args]);
        try {
            return $this->analyzer->analyze((string) $args['text'], $sensitive);
        } catch (\UnexpectedValueException $e) {
            throw new JobFailed('invalid_reply', $e);
        } catch (\Throwable $e) {
            // The text passed check() before the deposit: any other failure is the model's host, not the buyer.
            throw new JobFailed('provider_error', $e);
        }
    }
}
