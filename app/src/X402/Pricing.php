<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Networks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What an MCP tool call costs: paid tools have a price (X402_PRICE, or their own in
 * X402_PRICES), the rest are free.
 */
final class Pricing
{
    /**
     * @param list<string> $paidTools
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_PAID_TOOLS')] private readonly array $paidTools,
        #[Autowire(env: 'X402_NETWORK')] private readonly string $network,
        #[Autowire(env: 'X402_PRICE')] private readonly string $price,
        #[Autowire(env: 'X402_PAY_TO')] private readonly string $payTo,
        #[Autowire(env: 'json:X402_PRICES')] private readonly array $prices = [],
    ) {
    }

    /**
     * @param array{name: string, arguments?: array<string, mixed>} $params the tools/call params
     *
     * @return list<array>|null the x402 `accepts` list, or null when free
     */
    public function __invoke(array $params): ?array
    {
        return \in_array($params['name'], $this->paidTools, true)
            ? [Networks::requirements($this->network, (string) ($this->prices[$params['name']] ?? $this->price), $this->payTo)]
            : null;
    }
}
