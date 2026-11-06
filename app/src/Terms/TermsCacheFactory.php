<?php

declare(strict_types=1);

namespace App\Terms;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\PruneableInterface;

/**
 * The analyze_terms memo ('terms.cache', services.yaml): Redis when X402_REDIS_URL is set, else this host's files.
 *
 * It is a cache.pool: the pass passes the tag's namespace and default_lifetime as the first two arguments, so
 * cache:pool:list shows it and cache:pool:prune prunes the files (Redis expires its keys itself).
 *
 * Unlike LedgerFactory, a Redis that does not answer here falls back to this host's files. The memo only saves
 * model calls: a miss asks the model again and gets the same answer, so per-host memory cannot change a result,
 * only cost. Failing the request would take down every tool (the free ones too) for a cache. The warning
 * carries the exception class only: its message may echo the URL, password included.
 * framework.yaml cannot pick the backend from an optional env var (a pool needs a DSN), hence this factory.
 */
final class TermsCacheFactory
{
    public static function create(string $namespace, int $defaultLifetime, ?string $redisUrl, string $dir, ?LoggerInterface $logger = null): AdapterInterface
    {
        $redisUrl = trim((string) $redisUrl);
        if ('' === $redisUrl) {
            return new FilesystemAdapter($namespace, $defaultLifetime, $dir);
        }

        try {
            // Pruneable, as the pool tag says: cache:pool:prune calls prune() on every pool it lists. Redis expires its keys itself.
            $redis = new class(RedisAdapter::createConnection($redisUrl), $namespace, $defaultLifetime) extends RedisAdapter implements PruneableInterface {
                public function prune(): bool
                {
                    return true;
                }
            };
            // A round trip: the connection alone can be made without the server answering.
            $redis->getItem('probe');
        } catch (\Throwable $e) {
            $logger?->warning('analyze_terms memo: X402_REDIS_URL is set but Redis does not answer; the memo uses this host\'s files until it does.', ['exception' => $e::class]);

            return new FilesystemAdapter($namespace, $defaultLifetime, $dir);
        }

        return $redis;
    }
}
