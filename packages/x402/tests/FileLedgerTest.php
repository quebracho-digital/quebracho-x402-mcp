<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\FileLedger;

final class FileLedgerTest extends TestCase
{
    use TempClaims;

    private function ledger(): FileLedger
    {
        return new FileLedger($this->claimsDir());
    }

    public function testOnlyTheFirstClaimWins(): void
    {
        $ledger = $this->ledger();
        self::assertTrue($ledger->claim('k', 600));
        self::assertFalse($ledger->claim('k', 600));
        self::assertSame(['status' => 'claimed'], $ledger->get('k'));
    }

    public function testAnExpiredClaimCanBeTakenAgain(): void
    {
        $ledger = $this->ledger();
        // A negative TTL is already expired: no sleep needed.
        self::assertTrue($ledger->claim('k', -1));
        self::assertNull($ledger->get('k'));
        self::assertTrue($ledger->claim('k', 600));
    }

    public function testPutReplacesTheStateAndReleaseDeletesIt(): void
    {
        $ledger = $this->ledger();
        $ledger->claim('k', 600);
        $ledger->put('k', ['status' => 'settled', 'transaction' => '0xabc'], 600);
        self::assertSame(['status' => 'settled', 'transaction' => '0xabc'], $ledger->get('k'));

        $ledger->release('k');
        self::assertNull($ledger->get('k'));
        self::assertTrue($ledger->claim('k', 600));
    }

    public function testAnExpiredPutIsNotThere(): void
    {
        $ledger = $this->ledger();
        $ledger->put('k', ['status' => 'delivered'], -1);
        self::assertNull($ledger->get('k'));
        self::assertNull($this->ledger()->get('never-written'));
    }

    public function testPruneRemovesOnlyTheExpired(): void
    {
        $ledger = $this->ledger();
        $ledger->put('live', ['status' => 'settled'], 600);
        $ledger->put('dead', ['status' => 'settled'], -1);
        self::assertSame(1, $ledger->prune());
        self::assertSame(['status' => 'settled'], $ledger->get('live'));
    }
}
