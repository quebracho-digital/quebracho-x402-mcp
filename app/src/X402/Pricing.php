<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What an MCP tool call costs: paid tools have a price (X402_PRICE, or their own in
 * X402_PRICES), the rest are free. A slow tool gets a longer signature
 * (X402_TIMEOUTS) and declares its worst case (X402_DURATIONS).
 */
final class Pricing
{
    /**
     * @param list<string>          $paidTools
     * @param array<string, string> $prices
     * @param array<string, int>    $timeouts  maxTimeoutSeconds per tool; default Networks::TIMEOUT
     * @param array<string, mixed>  $durations worst-case seconds from the claim to the settle, per tool
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_PAID_TOOLS')] private readonly array $paidTools,
        #[Autowire(env: 'X402_NETWORK')] private readonly string $network,
        #[Autowire(env: 'X402_PRICE')] private readonly string $price,
        #[Autowire(env: 'X402_PAY_TO')] private readonly string $payTo,
        #[Autowire(env: 'json:X402_PRICES')] private readonly array $prices = [],
        #[Autowire(env: 'json:X402_TIMEOUTS')] private readonly array $timeouts = [],
        #[Autowire(env: 'json:X402_DURATIONS')] private readonly array $durations = [],
    ) {
        foreach ($this->durations as $tool => $seconds) {
            // A duration no fresh signature can cover would refuse every paid call of that tool.
            if (!\is_int($seconds) || $seconds < 0 || $seconds + EvmExact::SLACK >= ($this->timeouts[$tool] ?? Networks::TIMEOUT)) {
                throw new \InvalidArgumentException("X402_DURATIONS[$tool] must be an integer under its X402_TIMEOUTS minus ".EvmExact::SLACK.' s.');
            }
        }
    }

    /**
     * @param array{name: string, arguments?: array<string, mixed>} $params the tools/call params
     *
     * @return list<array>|null the x402 `accepts` list, or null when free
     */
    public function __invoke(array $params): ?array
    {
        $name = $params['name'];
        // Fails closed: a job's deposit (_start) and its result (_result) are never free, listed or not.
        $job = preg_match('/_(start|result)$/', $name);
        if (!$job && !\in_array($name, $this->paidTools, true)) {
            return null;
        }
        $req = Networks::requirements($this->network, (string) ($this->prices[$name] ?? $this->price), $this->payTo, (int) ($this->timeouts[$name] ?? Networks::TIMEOUT));
        if (str_ends_with($name, '_start')) {
            // The deposit settles before the job is queued.
            $req['extra']['paymentFlow'] = 'upfront';
        }

        return [$req];
    }

    /**
     * For X402McpMiddleware's $duration: 0 (no pre-flight) unless X402_DURATIONS names the tool.
     */
    public function duration(array $params): int
    {
        return (int) ($this->durations[$params['name']] ?? 0);
    }
}
