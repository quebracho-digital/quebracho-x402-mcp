<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\LedgerUnavailableException;
use Quebracho\X402\RedisLedger;

/**
 * A Redis that does not answer: the ledger says so with LedgerUnavailableException (the middlewares answer 503), never a 500.
 */
final class RedisLedgerDownTest extends TestCase
{
    public function testAnUnreachableRedisIsLedgerUnavailable(): void
    {
        $ledger = new RedisLedger('redis://127.0.0.1:1');

        $this->expectException(LedgerUnavailableException::class);
        $ledger->claim('down-'.bin2hex(random_bytes(4)), 60);
    }

    public function testEveryOperationReportsTheSameWay(): void
    {
        $ledger = new RedisLedger('redis://127.0.0.1:1');
        $thrown = 0;
        foreach ([static fn () => $ledger->get('k'), static fn () => $ledger->put('k', ['status' => 'settled'], 60), static fn () => $ledger->release('k'), static fn () => $ledger->ping()] as $op) {
            try {
                $op();
            } catch (LedgerUnavailableException) {
                ++$thrown;
            }
        }
        self::assertSame(4, $thrown, 'every operation must report an unreachable Redis as LedgerUnavailableException');
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'no host' => ['redis://'];
        yield 'not redis' => ['http://x'];
        yield 'not a URL' => ['redis://:secreto@host:notaport'];
        yield 'unresolvable host with a password' => ['redis://:secreto@host'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badUrls')]
    public function testAnUnusableUrlIsLedgerUnavailableWithoutItsPassword(string $url): void
    {
        try {
            (new RedisLedger($url))->claim('k', 60);
            self::fail('expected LedgerUnavailableException');
        } catch (LedgerUnavailableException $e) {
            self::assertStringNotContainsString('secreto', $e->getMessage());
            self::assertStringNotContainsString('@host', $e->getMessage());
        }
    }
}
