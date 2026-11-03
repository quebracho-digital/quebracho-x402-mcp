<?php

declare(strict_types=1);

namespace App\Mcp;

use App\EventListener\McpRateLimit;
use App\Terms\TermsAnalyzer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Paid (X402_PAID_TOOLS, price in X402_PRICES): the Hugging Face call has a cost.
 * A refusal or a failed model call throws, so nothing is charged for it.
 */
final class AnalyzeTermsTool
{
    public const NOTICE = 'SUPER BETA: an example for the hackathon demo, not legal advice. A model can miss clauses or flag the wrong ones. Read the quotes in the document before you rely on them.';

    public function __construct(
        private readonly TermsAnalyzer $analyzer,
        // Per client per hour (on top of McpRateLimit's per minute for all of /mcp): one IP must not spend the whole day.
        #[Target('terms_ip.limiter')] private readonly RateLimiterFactoryInterface $perClient,
        #[Target('terms_day.limiter')] private readonly RateLimiterFactoryInterface $perDay,
        private readonly RequestStack $requests,
    ) {
    }

    #[McpTool(name: 'analyze_terms', description: 'SUPER BETA, an example for the hackathon demo, not legal advice. Flags the clauses of a terms of service or privacy policy (up to 1200 words) in 19 categories, each with a quote checked against your text. Paid.')]
    public function __invoke(string $text): string
    {
        // A bad text costs nothing, so it must not use up anybody's limit.
        try {
            $text = $this->analyzer->check($text);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
        // Before the model call: a refused call throws, so nothing is charged and the model is not called.
        $client = McpRateLimit::client($this->requests->getCurrentRequest()?->getClientIp());
        if (!$this->perClient->create($client)->consume()->isAccepted() || !$this->perDay->create('all')->consume()->isAccepted()) {
            throw new ToolCallException('Rate limit reached for analyze_terms (a super beta example with a daily budget). Nothing was charged; try later.');
        }
        try {
            $result = $this->analyzer->analyze($text);
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        return json_encode(['notice' => self::NOTICE] + $result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
