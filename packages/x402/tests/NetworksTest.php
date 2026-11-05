<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Networks;

final class NetworksTest extends TestCase
{
    protected function tearDown(): void
    {
        Networks::reset();
    }

    public function testBlacklistIsStoredAndMustBeAKnownFunction(): void
    {
        $network = Networks::add(4202, '0x0000000000000000000000000000000000000001', 'Test USD', '1', blacklist: 'isBlacklisted');
        self::assertSame('isBlacklisted', Networks::find($network)['blacklist']);

        $plain = Networks::add(4203, '0x0000000000000000000000000000000000000001', 'Test USD', '1');
        self::assertNull(Networks::find($plain)['blacklist']);

        try {
            Networks::add(4204, '0x0000000000000000000000000000000000000001', 'Test USD', '1', blacklist: 'isbad');
            self::fail('an unknown function');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('isbad', $e->getMessage());
        }
        self::assertFalse(Networks::has('eip155:4204'), 'a refused add registers nothing');
    }

    public function testAddsAnyEvmChain(): void
    {
        $network = Networks::add(4202, '0x0000000000000000000000000000000000000001', 'Test USD', '1');
        $req = Networks::requirements($network, '1.5', '0x000000000000000000000000000000000000dEaD');

        self::assertSame('eip155:4202', $req['network']);
        self::assertSame('1500000', $req['amount']);
        self::assertSame(['name' => 'Test USD', 'version' => '1'], $req['extra']);
    }

    public function testRejectsABadAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Networks::add(4202, '0x1234', 'Test USD', '1');
    }

    public function testCatalogue(): void
    {
        Networks::reset();
        self::assertTrue(Networks::has('eip155:84532'));
        self::assertNull(Networks::find('eip155:7'));
        $sol = Networks::addSolana('11111111111111111111111111111111', 'EPjFWDvwVrfSoUeVmsKoc8MPa6tdozRmrgHgDt9uhAa7', 'Solana (USDC)');
        self::assertSame('solana:11111111111111111111111111111111', $sol);
        self::assertArrayHasKey($sol, Networks::all());
        Networks::reset();
        self::assertFalse(Networks::has($sol));
    }

    public function testPayToIsCheckedPerFamily(): void
    {
        self::assertSame('0x000000000000000000000000000000000000dEaD', Networks::requirements('eip155:84532', '1', '0x000000000000000000000000000000000000dead')['payTo']);
        self::assertSame('CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5', Networks::requirements('solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', '1', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5')['payTo']);
        foreach ([['eip155:84532', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5'], ['solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', '0x000000000000000000000000000000000000dEaD']] as [$network, $payTo]) {
            try {
                Networks::requirements($network, '1', $payTo);
                self::fail($network);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheMainnetsAreBuiltIn(): void
    {
        Networks::reset();
        self::assertSame('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', Networks::get('eip155:8453')['asset']);
        self::assertSame('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', Networks::get('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d')['asset']);
        // A Base payment is EIP-3009 (EvmExact); a Solana one is SolanaExact; both build requirements.
        self::assertSame('0x000000000000000000000000000000000000dEaD', Networks::requirements('eip155:8453', '1', '0x000000000000000000000000000000000000dead')['payTo']);
        self::assertSame('CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5', Networks::requirements('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d', '1', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5')['payTo']);
    }
}
