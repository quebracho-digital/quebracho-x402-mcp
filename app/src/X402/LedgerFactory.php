<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\FileLedger;
use Quebracho\X402\PaymentLedger;

/**
 * The seller's one PaymentLedger (services.yaml): Redis when X402_REDIS_URL is set, else this host's files.
 * Several hosts must set X402_REDIS_URL: the file ledger is per host.
 */
final class LedgerFactory
{
    public static function create(?string $redisUrl, string $dir): PaymentLedger
    {
        $redisUrl = trim((string) $redisUrl);

        return '' !== $redisUrl ? new RedisLedger($redisUrl) : new FileLedger($dir);
    }

    /**
     * app:x402:check: pings Redis when X402_REDIS_URL is set, else proves the directory takes a claim.
     */
    public static function check(?string $redisUrl, string $dir): void
    {
        $ledger = self::create($redisUrl, $dir);
        if ($ledger instanceof RedisLedger) {
            $ledger->ping();

            return;
        }
        $key = 'x402-check-'.bin2hex(random_bytes(4));
        if (!$ledger->claim($key, 1)) {
            throw new \RuntimeException("The x402 ledger directory $dir does not take a claim.");
        }
        $ledger->release($key);
    }
}
