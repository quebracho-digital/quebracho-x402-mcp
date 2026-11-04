<?php

declare(strict_types=1);

namespace App\X402;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Runs on cache:warmup (so cache:clear and the deploy): a name in X402_SENSITIVE_TOOLS that no served
 * tool has (a typo) fails the deploy here. Otherwise that tool's answers would be kept in the ledger, silently.
 */
final class SensitiveToolsWarmer implements CacheWarmerInterface
{
    /**
     * @param list<string>                                      $sensitive X402_SENSITIVE_TOOLS
     * @param array<string, array{job: ?array, schema: string}> $served    app.mcp_tools (the Kernel)
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_SENSITIVE_TOOLS')] private readonly array $sensitive,
        #[Autowire('%app.mcp_tools%')] private readonly array $served,
    ) {
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ([] === $this->sensitive) {
            return [];
        }
        $unknown = SensitiveTools::unknown($this->sensitive, $this->served);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('X402_SENSITIVE_TOOLS names no served tool: %s.', implode(', ', $unknown)));
        }

        return [];
    }

    public function isOptional(): bool
    {
        return false;
    }
}
