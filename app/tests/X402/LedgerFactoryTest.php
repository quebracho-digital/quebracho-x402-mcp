<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\LedgerFactory;
use App\X402\RedisLedger;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\FileLedger;
use Quebracho\X402\LedgerUnavailableException;

final class LedgerFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/x402-ledger-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testWithoutRedisUrlTheLedgerIsThisHostsFiles(): void
    {
        self::assertInstanceOf(FileLedger::class, LedgerFactory::create(null, $this->dir));
        self::assertInstanceOf(FileLedger::class, LedgerFactory::create('  ', $this->dir));
    }

    public function testWithRedisUrlTheLedgerIsRedis(): void
    {
        self::assertInstanceOf(RedisLedger::class, LedgerFactory::create('redis://redis:6379', $this->dir));
    }

    public function testAnUnreachableRedisFailsTheRequestInsteadOfFallingBackToFiles(): void
    {
        $ledger = LedgerFactory::create('redis://127.0.0.1:1', $this->dir);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not answer');
        $ledger->claim('k', 60);
    }

    public function testCheckOnAHealthyRedisPasses(): void
    {
        $url = $_ENV['X402_REDIS_URL'] ?? $_SERVER['X402_REDIS_URL'] ?? 'redis://redis:6379';
        try {
            LedgerFactory::create($url, $this->dir)->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped("Redis does not answer at $url: {$e->getMessage()}");
        }

        LedgerFactory::check($url, $this->dir);
        $this->addToAssertionCount(1);
    }

    public function testCheckFailsOnARedisThatRejectsWrites(): void
    {
        // A missing database: PING still answers, but every claim is refused. The check must claim, not only ping.
        $this->expectException(LedgerUnavailableException::class);
        LedgerFactory::check('redis://redis:6379/99', $this->dir);
    }

    public function testCheckProvesTheFileDirectoryTakesAClaim(): void
    {
        LedgerFactory::check(null, $this->dir);
        self::assertDirectoryExists($this->dir);

        $notADir = $this->dir.'-file';
        file_put_contents($notADir, '');
        try {
            $this->expectException(\RuntimeException::class);
            LedgerFactory::check(null, $notADir.'/sub');
        } finally {
            unlink($notADir);
        }
    }
}
