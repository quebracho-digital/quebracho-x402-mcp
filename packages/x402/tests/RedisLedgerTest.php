<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\LedgerUnavailableException;
use Quebracho\X402\RedisLedger;

/**
 * Against the Redis of DDEV (the ddev-redis add-on). Skipped, with the reason, when it does not answer.
 */
final class RedisLedgerTest extends TestCase
{
    private RedisLedger $ledger;
    private string $key;

    protected function setUp(): void
    {
        $url = $_ENV['X402_REDIS_URL'] ?? $_SERVER['X402_REDIS_URL'] ?? 'redis://redis:6379';
        $this->ledger = new RedisLedger($url);
        try {
            $this->ledger->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped("Redis does not answer at $url (DDEV: \`ddev get ddev/ddev-redis\` then \`ddev restart\`): {$e->getMessage()}");
        }
        $this->key = 'test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->ledger, $this->key)) {
            $this->ledger->release($this->key);
        }
        parent::tearDown();
    }

    public function testOnlyTheFirstClaimWins(): void
    {
        self::assertTrue($this->ledger->claim($this->key, 60));
        self::assertFalse($this->ledger->claim($this->key, 60));
        self::assertSame(['status' => 'claimed'], $this->ledger->get($this->key));
    }

    public function testPutReplacesTheStateAndReleaseDeletesIt(): void
    {
        $this->ledger->claim($this->key, 60);
        $this->ledger->put($this->key, ['status' => 'pending', 'transaction' => '0xabc', 'network' => 'eip155:84532'], 60);
        self::assertSame(['status' => 'pending', 'transaction' => '0xabc', 'network' => 'eip155:84532'], $this->ledger->get($this->key));

        $this->ledger->release($this->key);
        self::assertNull($this->ledger->get($this->key));
        self::assertTrue($this->ledger->claim($this->key, 60));
    }

    public function testAStateExpires(): void
    {
        $this->ledger->put($this->key, ['status' => 'settled'], 1);
        sleep(2);
        self::assertNull($this->ledger->get($this->key));
    }

    public function testOpenListsTheKeyWithItsRefUntilClosed(): void
    {
        $this->ledger->open($this->key, ['status' => 'settling', 'opened' => 1], 60);
        $this->ledger->open($this->key, ['status' => 'pending'], 60);
        self::assertSame(['status' => 'pending', 'opened' => 1], $this->ledger->openKeys(1000)[$this->key] ?? null, 'the first opened time is kept');

        $this->ledger->close($this->key);
        self::assertArrayNotHasKey($this->key, $this->ledger->openKeys(1000));
    }

    public function testReleaseClosesTheEntryAndAnExpiredRefLeavesTheSet(): void
    {
        $this->ledger->put($this->key, ['status' => 'pending'], 60);
        $this->ledger->open($this->key, [], 60);
        $this->ledger->release($this->key);
        self::assertArrayNotHasKey($this->key, $this->ledger->openKeys(1000));

        $this->ledger->open($this->key, [], 1);
        sleep(2);
        self::assertArrayNotHasKey($this->key, $this->ledger->openKeys(1000));
        $raw = new \Redis();
        $raw->connect(...$this->hostAndPort());
        self::assertFalse($raw->sIsMember('x402:open', $this->key), 'removed from the set');
    }

    public function testOpenKeysHonoursTheLimit(): void
    {
        $other = $this->key.'-b';
        try {
            $this->ledger->open($this->key, [], 60);
            $this->ledger->open($other, [], 60);
            self::assertCount(1, $this->ledger->openKeys(1));
        } finally {
            $this->ledger->close($other);
        }
    }

    public function testAMissingDatabaseIsLedgerUnavailableNotADuplicate(): void
    {
        // Redis answers "ERR DB index is out of range" to SELECT: the claim must not run on db 0 instead.
        $ledger = new RedisLedger(preg_replace('#/?$#', '/99', $this->url()));

        $this->expectException(LedgerUnavailableException::class);
        $ledger->claim($this->key, 60);
    }

    public function testAnErrorReplyIsUnavailableButAnExistingKeyIsStillADuplicate(): void
    {
        $raw = new \Redis();
        $raw->connect(...$this->hostAndPort());
        $raw->rPush('x402:'.$this->key, 'not a state'); // a list: GET on it is WRONGTYPE, SET NX on it is "exists"
        try {
            self::assertFalse($this->ledger->claim($this->key, 60), 'an existing key is a duplicate, not a failure');
            try {
                $this->ledger->get($this->key);
                self::fail('a WRONGTYPE reply must throw LedgerUnavailableException');
            } catch (LedgerUnavailableException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $raw->del('x402:'.$this->key);
        }
    }

    public function testTheConnectionHasATwoSecondReadTimeout(): void
    {
        $connection = new \ReflectionMethod($this->ledger, 'connection');

        self::assertSame(2.0, $connection->invoke($this->ledger)->getOption(\Redis::OPT_READ_TIMEOUT));
    }

    private function url(): string
    {
        return $_ENV['X402_REDIS_URL'] ?? $_SERVER['X402_REDIS_URL'] ?? 'redis://redis:6379';
    }

    /** @return array{0: string, 1: int} */
    private function hostAndPort(): array
    {
        $parts = parse_url($this->url());

        return [$parts['host'], $parts['port'] ?? 6379];
    }
}
