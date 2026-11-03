<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Budget;
use Quebracho\X402\Networks;

final class BudgetTest extends TestCase
{
    private static function offer(): array
    {
        return Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
    }

    public function testSignsAnHonestOffer(): void
    {
        self::assertTrue(Budget::usd('eip155:84532', '0.05')->allows(self::offer()));
    }

    public function testCapsPerNetworkAndPerPaymentFromUsd(): void
    {
        $budget = Budget::fromUsd(['eip155:84532' => '0.05'], ['eip155:84532' => '0.005']);
        self::assertFalse($budget->allows(self::offer()), 'over the per-payment ceiling');
        self::assertTrue(Budget::fromUsd(['eip155:84532' => '0.05'], ['eip155:84532' => '0.01'])->allows(self::offer()));
        self::assertFalse(Budget::fromUsd(['eip155:84532' => '0.05'])->allows(Networks::requirements('eip155:5042', '0.01', '0x000000000000000000000000000000000000dEaD')), 'no cap for Arc');
        self::assertTrue(Budget::fromUsd(['eip155:5042' => '0.05'])->allows(Networks::requirements('eip155:5042', '0.01', '0x000000000000000000000000000000000000dEaD')));
    }

    public function testABuyerSignsNoLongerThanItWaits(): void
    {
        $budget = Budget::fromUsd(['eip155:84532' => '0.05'], maxTimeout: 170);
        self::assertTrue($budget->allows(['maxTimeoutSeconds' => 170] + self::offer()));
        self::assertFalse($budget->allows(['maxTimeoutSeconds' => 171] + self::offer()));
        // Never above MAX_TIMEOUT, whatever it is given.
        self::assertFalse(Budget::fromUsd(['eip155:84532' => '0.05'], maxTimeout: 9999)->allows(['maxTimeoutSeconds' => Budget::MAX_TIMEOUT + 1] + self::offer()));
        self::assertTrue(Budget::fromUsd(['eip155:84532' => '0.05'])->allows(['maxTimeoutSeconds' => Budget::MAX_TIMEOUT] + self::offer()));
        self::assertTrue($budget->allows(['maxTimeoutSeconds' => 1] + self::offer()), 'one second is the shortest validity');
    }

    /** What a hostile seller can put in its PaymentRequired. */
    public static function hostile(): iterable
    {
        yield 'other token' => [['asset' => '0x29f2D40B0605204364af54EC677bD022dA425d03']];
        yield 'other EIP-712 domain' => [['extra' => ['name' => 'Fake', 'version' => '2']]];
        yield 'unknown network' => [['network' => 'eip155:7']];
        yield 'ten-year validity' => [['maxTimeoutSeconds' => 315360000]];
        yield 'validity as string' => [['maxTimeoutSeconds' => '60']];
        yield 'not a number' => [['amount' => 'abc']];
        yield 'exponent' => [['amount' => '1e3']];
        yield 'negative' => [['amount' => '-10000']];
        yield 'zero' => [['amount' => '0']];
        yield 'above the cap' => [['amount' => '50001']];
        yield 'payTo without 0x' => [['payTo' => '000000000000000000000000000000000000dEaD']];
        yield 'payTo zero-padded' => [['payTo' => '0x00000000000000000000000000000000000000dEaD']];
        yield 'payTo with a prefix' => [['payTo' => 'x0x000000000000000000000000000000000000dEaD']];
        yield 'payTo as array' => [['payTo' => ['0x000000000000000000000000000000000000dEaD']]];
        yield 'beyond uint256' => [['amount' => str_repeat('9', 79)]];
    }

    #[DataProvider('hostile')]
    public function testRefusesAHostileOffer(array $patch): void
    {
        self::assertFalse(Budget::usd('eip155:84532', '0.05')->allows(array_replace(self::offer(), $patch)));
    }

    /** A budget that spent one signed, unsettled payment valid until t=1000. */
    private static function withUnsettled(?\Closure $used, int &$now): Budget
    {
        $budget = new Budget(['eip155:84532' => '50000'], $used, function () use (&$now) { return $now; });
        $budget->spend(self::offer());
        $budget->unsettled(self::offer(), ['payload' => ['authorization' => ['from' => '0xabc', 'nonce' => '0x01', 'validBefore' => '1000']]]);

        return $budget;
    }

    public function testFreesAnExpiredSignatureTheChainNeverUsed(): void
    {
        $now = 1000;
        $budget = self::withUnsettled(fn (string $network, string $from, string $nonce) => false, $now);
        $budget->reclaim();
        self::assertSame('10000', $budget->spent('eip155:84532'), 'still valid: the seller may settle it yet');

        $now = 1060;
        $budget->reclaim();
        self::assertSame('10000', $budget->spent('eip155:84532'), 'within the clock-skew margin');

        $now = 1061;
        $budget->reclaim();
        self::assertSame('0', $budget->spent('eip155:84532'));
        $budget->reclaim();
        self::assertSame('0', $budget->spent('eip155:84532'), 'freed once');
    }

    public function testKeepsWhatTheChainUsedOrCannotTell(): void
    {
        $now = 2000;
        $used = self::withUnsettled(fn () => true, $now);
        $used->reclaim();
        self::assertSame('10000', $used->spent('eip155:84532'));

        $down = self::withUnsettled(fn () => throw new \RuntimeException('RPC down'), $now);
        $down->reclaim();
        self::assertSame('10000', $down->spent('eip155:84532'));

        $none = self::withUnsettled(null, $now);
        $none->reclaim();
        self::assertSame('10000', $none->spent('eip155:84532'));
    }

    public function testReclaimLooksAtEveryEntry(): void
    {
        $now = 2000;
        $budget = new Budget(['eip155:84532' => '50000'], fn (string $n, string $from) => '0xaaa' === $from, function () use (&$now) { return $now; });
        foreach (['0xaaa' => '1000', '0xbbb' => '1000', '0xccc' => '5000'] as $from => $validBefore) {
            $budget->spend(self::offer());
            $budget->unsettled(self::offer(), ['payload' => ['authorization' => ['from' => $from, 'nonce' => '0x01', 'validBefore' => $validBefore]]]);
        }
        $budget->reclaim();
        self::assertSame('20000', $budget->spent('eip155:84532'), 'used, freed, still valid');
    }

    public function testChooseSaysWhyAnOfferIsNotPaid(): void
    {
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $evm = new \Quebracho\X402\EvmExact();
        $this->assertSame([$evm, null], Budget::usd('eip155:84532', '1')->choose([$evm], $req, null));
        $this->assertSame([null, 'no_scheme'], Budget::usd('eip155:84532', '1')->choose([new \Quebracho\X402\SolanaExact()], $req, null));
        $this->assertSame([null, 'not_allowed'], Budget::usd('eip155:84532', '0.001')->choose([$evm], $req, null));
    }
}
