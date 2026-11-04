<?php

declare(strict_types=1);

namespace App\X402;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * X402McpMiddleware's $sensitive: a tools/call whose answer the payment ledger must not keep (only its state).
 * Sensitive when X402_SENSITIVE_TOOLS (comma separated) names the tool, or when the caller passes
 * arguments.sensitive true (analyze_terms takes it, and skips its memo too).
 *
 * A typo in X402_SENSITIVE_TOOLS fails the deploy (SensitiveToolsWarmer, app:x402:check), not this request path.
 */
final class SensitiveTools
{
    /** @param list<string> $tools */
    public function __construct(#[Autowire(env: 'csv:X402_SENSITIVE_TOOLS')] private readonly array $tools)
    {
    }

    /**
     * The names in X402_SENSITIVE_TOOLS that no served tool has.
     *
     * @param list<string>                                      $names
     * @param array<string, array{job: ?array, schema: string}> $served app.mcp_tools
     *
     * @return list<string>
     */
    public static function unknown(array $names, array $served): array
    {
        return array_values(array_filter($names, static fn (string $name) => !\array_key_exists($name, $served)));
    }

    public function __invoke(array $params): bool
    {
        return \in_array($params['name'] ?? null, $this->tools, true) || true === ($params['arguments']['sensitive'] ?? null);
    }
}
