<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Scheme;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Runs on cache:warmup (so cache:clear and the deploy): a bad X402_NETWORK fails the deploy
 * here, not on the first paid call. Like app:x402:check, it only checks when paid tools exist:
 * X402_PAID_TOOLS or a #[PaidJob] tool (app.mcp_tools, from the Kernel).
 * Networks has no change events and nothing calls Networks::add at runtime, so this covers config changes only.
 */
final class NetworkWarmer implements CacheWarmerInterface
{
    /**
     * @param array<string, array{job: ?array, schema: string}> $tools   app.mcp_tools
     * @param Scheme[]                                          $schemes
     */
    public function __construct(
        #[Autowire('%env(default::X402_NETWORK)%')] private readonly ?string $network,
        #[Autowire('%env(default::X402_PAID_TOOLS)%')] private readonly ?string $paidTools,
        #[Autowire('%app.mcp_tools%')] private readonly array $tools,
        private readonly array $schemes,
    ) {
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $paidJob = array_filter($this->tools, static fn (array $tool) => null !== ($tool['job'] ?? null));
        if ('' !== trim((string) $this->paidTools) || [] !== $paidJob) {
            NetworkCheck::assertSupported((string) $this->network, $this->schemes);
        }

        return [];
    }

    public function isOptional(): bool
    {
        return false;
    }
}
