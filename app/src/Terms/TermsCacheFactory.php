<?php

declare(strict_types=1);

namespace App\Terms;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The analyze_terms memo ('terms.cache', services.yaml): Redis when X402_REDIS_URL is set, else this host's files.
 *
 * Same rule as LedgerFactory: several hosts must share one Redis, and a Redis that does not answer fails the
 * request. It never falls back to files, which would make the memo per host and quietly split it.
 * framework.yaml cannot pick the backend from an optional env var (a pool needs a DSN), hence this factory.
 */
final class TermsCacheFactory
{
    public static function create(?string $redisUrl, string $dir): AdapterInterface
    {
        $redisUrl = trim((string) $redisUrl);
        if ('' === $redisUrl) {
            return new FilesystemAdapter('terms', 0, $dir);
        }

        return new RedisAdapter(RedisAdapter::createConnection($redisUrl), 'terms');
    }
}
