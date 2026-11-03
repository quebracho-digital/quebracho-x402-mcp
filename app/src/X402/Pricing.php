<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What an MCP tool call costs: paid tools have a price (X402_PRICE, or their own in
 * X402_PRICES), the rest are free. A slow tool gets a longer signature
 * (X402_TIMEOUTS) and declares its worst case (X402_DURATIONS). A #[PaidJob]
 * tool is always paid.
 */
final class Pricing
{
    /**
     * @param list<string>                                                            $paidTools
     * @param array<string, string>                                                   $prices
     * @param array<string, mixed>                                                    $timeouts  maxTimeoutSeconds per tool, 1..Budget::MAX_TIMEOUT; default Networks::TIMEOUT
     * @param array<string, mixed>                                                    $durations worst-case seconds from the claim to the settle, per tool
     * @param array<string, ?array{role: string, pairedWith: string, price: ?string}> $tools     every registered tool by
     *                                                                                           name, with its #[PaidJob]
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_PAID_TOOLS')] private readonly array $paidTools,
        #[Autowire(env: 'X402_NETWORK')] private readonly string $network,
        #[Autowire(env: 'X402_PRICE')] private readonly string $price,
        #[Autowire(env: 'X402_PAY_TO')] private readonly string $payTo,
        #[Autowire(env: 'json:X402_PRICES')] private readonly array $prices = [],
        #[Autowire(env: 'json:X402_TIMEOUTS')] private readonly array $timeouts = [],
        #[Autowire(env: 'json:X402_DURATIONS')] private readonly array $durations = [],
        #[Autowire(param: 'app.mcp_tools')] private readonly array $tools = [],
    ) {
        foreach ($this->timeouts as $tool => $seconds) {
            // A buyer (Budget) never signs longer than MAX_TIMEOUT: a longer offer would sell nothing.
            if (!\is_int($seconds) || $seconds < 1 || $seconds > Budget::MAX_TIMEOUT) {
                throw new \InvalidArgumentException("X402_TIMEOUTS[$tool] must be an integer from 1 to ".Budget::MAX_TIMEOUT.'.');
            }
        }
        foreach ($this->durations as $tool => $seconds) {
            // A duration no fresh signature can cover would refuse every paid call of that tool;
            // one second of headroom for the signing itself.
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
        // Fails closed: a #[PaidJob] tool is never free, listed or not. The flag decides, not the name.
        $job = $this->tools[$name] ?? null;
        if (null === $job && !\in_array($name, $this->paidTools, true)) {
            return null;
        }
        $price = $job['price'] ?? $this->prices[$name] ?? $this->price;
        $req = Networks::requirements($this->network, (string) $price, $this->payTo, (int) ($this->timeouts[$name] ?? Networks::TIMEOUT));
        if ('start' === ($job['role'] ?? null)) {
            // The deposit settles before the job is queued.
            $req['extra']['paymentFlow'] = 'upfront';
        }

        return [$req];
    }

    /**
     * For X402McpMiddleware's $validate: a paid name that is no registered tool
     * (a typo in X402_PAID_TOOLS) is refused before any payment is read.
     */
    public function validate(array $params): ?string
    {
        return \array_key_exists($params['name'], $this->tools) ? null : "Unknown tool: {$params['name']}";
    }

    /**
     * For X402McpMiddleware's $duration: 0 (no pre-flight) unless X402_DURATIONS names the tool.
     */
    public function duration(array $params): int
    {
        return (int) ($this->durations[$params['name']] ?? 0);
    }
}
