<?php

declare(strict_types=1);

namespace App\X402;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * X402McpMiddleware's $sensitive: a tools/call whose answer the payment ledger must not keep (only its state).
 * Sensitive when X402_SENSITIVE_TOOLS (comma separated) names the tool, or when the caller passes
 * arguments.sensitive true (analyze_terms takes it, and skips its memo too).
 *
 * ponytail: a typo in X402_SENSITIVE_TOOLS fails open (that tool's answers are kept); Pricing's name check would catch it if it matters.
 */
final class SensitiveTools
{
    /** @param list<string> $tools */
    public function __construct(#[Autowire(env: 'csv:X402_SENSITIVE_TOOLS')] private readonly array $tools)
    {
    }

    public function __invoke(array $params): bool
    {
        return \in_array($params['name'] ?? null, $this->tools, true) || true === ($params['arguments']['sensitive'] ?? null);
    }
}
