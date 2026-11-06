<?php

declare(strict_types=1);

namespace App\X402;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Runs on cache:warmup (so cache:clear and the deploy): a bad rule in config/x402_sensitive.yaml (unknown kind, missing
 * value, duplicate) or a sensitive tool no served tool has (a typo in X402_SENSITIVE_TOOLS) fails the deploy here.
 * Otherwise those answers would be kept in the ledger, silently (SensitiveRules::check).
 */
final class SensitiveToolsWarmer implements CacheWarmerInterface
{
    /**
     * @param list<string>                                      $sensitive X402_SENSITIVE_TOOLS
     * @param array<string, array{job: ?array, schema: string}> $served    app.mcp_tools (the Kernel)
     * @param list<array<string, mixed>>                        $rules     config/x402_sensitive.yaml
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_SENSITIVE_TOOLS')] private readonly array $sensitive,
        #[Autowire('%app.mcp_tools%')] private readonly array $served,
        #[Autowire('%app.x402_sensitive_rules%')] private readonly array $rules = [],
    ) {
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        SensitiveRules::check($this->sensitive, $this->rules, $this->served);

        return [];
    }

    public function isOptional(): bool
    {
        return false;
    }
}
