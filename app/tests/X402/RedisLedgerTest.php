<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\RedisLedger;
use PHPUnit\Framework\TestCase;

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
}
