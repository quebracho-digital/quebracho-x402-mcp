<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\LedgerUnavailableException;
use Quebracho\X402\PaymentLedger;

/**
 * The PaymentLedger for several hosts: Redis (ext-redis), one JSON value per payment under 'x402:'.
 *
 * Claim is SET NX EX, so of N concurrent claims exactly one wins on any host. A Redis that does not answer
 * throws LedgerUnavailableException, which the middlewares answer as ledger_unavailable: LedgerFactory never
 * falls back to per-host files, which would let one payment be delivered once per host.
 */
final class RedisLedger implements PaymentLedger
{
    private const PREFIX = 'x402:';

    private ?\Redis $redis = null;

    /**
     * @param string $url redis://[user][:password@]host[:port][/db], as in X402_REDIS_URL
     */
    public function __construct(private readonly string $url)
    {
    }

    public function claim(string $key, int $ttl): bool
    {
        return $this->call(fn (\Redis $r) => true === $r->set(self::PREFIX.$key, self::encode(['status' => 'claimed']), ['nx', 'ex' => $ttl]));
    }

    public function get(string $key): ?array
    {
        $raw = $this->call(fn (\Redis $r) => $r->get(self::PREFIX.$key));
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $state, int $ttl): void
    {
        $this->call(fn (\Redis $r) => $r->set(self::PREFIX.$key, self::encode($state), ['ex' => $ttl]));
    }

    public function release(string $key): void
    {
        $this->call(fn (\Redis $r) => $r->del(self::PREFIX.$key));
    }

    /**
     * Throws unless Redis answers PING (app:x402:check).
     */
    public function ping(): void
    {
        $answer = $this->call(fn (\Redis $r) => $r->ping());
        if (!in_array($answer, [true, '+PONG', 'PONG'], true)) {
            throw new LedgerUnavailableException('X402_REDIS_URL is set but Redis did not answer PING.');
        }
    }

    /**
     * Runs one Redis operation. A failed one drops the connection, so the next call connects again.
     */
    private function call(\Closure $operation): mixed
    {
        try {
            return $operation($this->connection());
        } catch (\RedisException $e) {
            $this->redis = null;

            throw new LedgerUnavailableException('X402_REDIS_URL is set but Redis does not answer: '.$e->getMessage(), 0, $e);
        }
    }

    private function connection(): \Redis
    {
        if (null !== $this->redis) {
            return $this->redis;
        }
        $parts = parse_url($this->url);
        if (!is_array($parts) || !isset($parts['host']) || 'redis' !== ($parts['scheme'] ?? null)) {
            // The URL itself is not echoed: it may carry a password.
            throw new \InvalidArgumentException('X402_REDIS_URL must look like redis://host:port.');
        }
        $redis = new \Redis();
        try {
            $redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
            if (isset($parts['pass'])) {
                $redis->auth(isset($parts['user']) ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])] : rawurldecode($parts['pass']));
            }
            $db = (int) trim($parts['path'] ?? '', '/');
            if ($db > 0) {
                $redis->select($db);
            }
        } catch (\RedisException $e) {
            throw new LedgerUnavailableException('X402_REDIS_URL is set but Redis does not answer: '.$e->getMessage(), 0, $e);
        }

        return $this->redis = $redis;
    }

    private static function encode(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
