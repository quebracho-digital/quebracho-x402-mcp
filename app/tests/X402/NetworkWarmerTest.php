<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\NetworkWarmer;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\EvmExact;

final class NetworkWarmerTest extends TestCase
{
    private const SOLANA = 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1';

    public function testNoPaidToolsSkipsTheCheck(): void
    {
        // A network no scheme supports would fail, but without paid tools nothing is sold.
        $warmer = new NetworkWarmer(self::SOLANA, '', [new EvmExact()]);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testAGoodNetworkWarmsUp(): void
    {
        $warmer = new NetworkWarmer('eip155:84532', 'wisdom', [new EvmExact()]);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testABadNetworkFailsTheWarmup(): void
    {
        $warmer = new NetworkWarmer(self::SOLANA, 'wisdom', [new EvmExact()]);
        $this->expectException(\InvalidArgumentException::class);
        $warmer->warmUp(sys_get_temp_dir());
    }

    public function testItIsNotOptional(): void
    {
        self::assertFalse((new NetworkWarmer(null, null, []))->isOptional());
    }
}
