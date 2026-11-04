<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Networks;
use Quebracho\X402\Scheme;

/**
 * X402_NETWORK must be a known network that a registered Scheme supports: otherwise
 * Sale refuses every paid call with a 500. Shared by app:x402:check and NetworkWarmer.
 */
final class NetworkCheck
{
    /** @param Scheme[] $schemes */
    public static function assertSupported(string $network, array $schemes): void
    {
        if (!Networks::has($network)) {
            throw new \InvalidArgumentException("\"$network\" is not a known network");
        }
        if ([] === array_filter($schemes, fn (Scheme $s) => $s->supports($network))) {
            $registered = implode(', ', array_map(static fn (Scheme $s) => $s::class, $schemes)) ?: 'none';

            throw new \InvalidArgumentException("\"$network\" is supported by no registered scheme (registered: $registered).");
        }
    }
}
