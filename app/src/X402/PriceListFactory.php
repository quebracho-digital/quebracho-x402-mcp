<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\PriceList;
use Quebracho\X402\Scheme;

/**
 * The seller's one PriceList (services.yaml), from env. The prices, the checks and the offer a call gets are
 * the library's; this class only says which env variable a bad value came from, since the library names its
 * own constructor arguments ("paid names wisdon") and an operator reads app/.env ("X402_PAID_TOOLS names
 * wisdon"). Built at deploy by app:x402:check, so a typo fails there and not on a paid call.
 */
final class PriceListFactory
{
    /** The library's name for a setting, as its messages start, and the env variable (or flag) it is here. */
    private const array NAMES = [
        'paid' => 'X402_PAID_TOOLS',
        'prices' => 'X402_PRICES',
        'timeouts' => 'X402_TIMEOUTS',
        'durations' => 'X402_DURATIONS',
        'price' => 'X402_PRICE',
        'jobs' => '#[PaidJob]',
        'the job price of' => '#[PaidJob] price of',
    ];

    /**
     * @param list<string>                        $paid      X402_PAID_TOOLS
     * @param array<string, string>               $prices    X402_PRICES
     * @param array<string, mixed>                $timeouts  X402_TIMEOUTS
     * @param array<string, mixed>                $durations X402_DURATIONS
     * @param array<string, array<string, mixed>> $jobs      app.job_tools: the #[PaidJob] tools, always paid
     * @param list<string>                        $served    app.mcp_tool_names: every tool the server serves
     * @param iterable<Scheme>                    $schemes
     */
    public static function create(string $network, string $payTo, string $price, array $paid, array $prices, array $timeouts, array $durations, array $jobs, array $served, iterable $schemes = []): PriceList
    {
        try {
            return new PriceList($network, $payTo, $price, $paid, $prices, $timeouts, $durations, $jobs, $served, $schemes);
        } catch (\InvalidArgumentException $e) {
            $message = preg_replace_callback('/^('.implode('|', array_map(preg_quote(...), array_keys(self::NAMES))).')(?=[ \[:])/', static fn (array $m): string => self::NAMES[$m[1]], $e->getMessage());

            throw new \InvalidArgumentException((string) $message, 0, $e);
        }
    }
}
