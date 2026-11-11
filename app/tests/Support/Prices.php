<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\X402\PriceListFactory;
use Quebracho\X402\PriceList;

/**
 * The library's PriceList built as config/services.yaml builds it, from the app's catalog of served tools
 * (PaidJob::catalog, app.mcp_tools): its #[PaidJob] flags are the job tools, its names the served ones.
 */
final class Prices
{
    /**
     * @param list<string>                        $paid
     * @param array<string, string>               $prices
     * @param array<string, mixed>                $timeouts
     * @param array<string, mixed>                $durations
     * @param array<string, array<string, mixed>> $tools
     */
    public static function of(array $paid, string $network, string $price, string $payTo, array $prices = [], array $timeouts = [], array $durations = [], array $tools = []): PriceList
    {
        return PriceListFactory::create($network, $payTo, $price, $paid, $prices, $timeouts, $durations, self::jobs($tools), array_keys($tools));
    }

    /**
     * @param array<string, array<string, mixed>> $tools
     *
     * @return array<string, array<string, mixed>> app.job_tools (App\Kernel)
     */
    public static function jobs(array $tools): array
    {
        return array_filter(array_map(static fn (array $t): ?array => $t['job'] ?? null, $tools));
    }
}
