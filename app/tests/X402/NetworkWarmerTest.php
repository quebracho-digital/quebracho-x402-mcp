<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\NetworkWarmer;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\EvmExact;

final class NetworkWarmerTest extends TestCase
{
    private const SOLANA = 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1';

    /** app.mcp_tools (Kernel): a served tool, with its #[PaidJob] job or null. */
    private const FREE_TOOL = ['echo' => ['job' => null, 'schema' => '{}']];
    private const PAID_JOB = ['start_x' => ['job' => ['role' => 'start', 'pairedWith' => 'result_x', 'price' => '0.02'], 'schema' => '{}']];

    public function testNoPaidToolsSkipsTheCheck(): void
    {
        // A network no scheme supports would fail, but without paid tools nothing is sold.
        $warmer = new NetworkWarmer(self::SOLANA, '', self::FREE_TOOL, [new EvmExact()]);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testAGoodNetworkWarmsUp(): void
    {
        $warmer = new NetworkWarmer('eip155:84532', 'wisdom', self::FREE_TOOL, [new EvmExact()]);
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
    }

    public function testABadNetworkFailsTheWarmup(): void
    {
        $warmer = new NetworkWarmer(self::SOLANA, 'wisdom', self::FREE_TOOL, [new EvmExact()]);
        $this->expectException(\InvalidArgumentException::class);
        $warmer->warmUp(sys_get_temp_dir());
    }

    public function testAPaidJobAloneWithABadNetworkFailsTheWarmup(): void
    {
        // X402_PAID_TOOLS is empty: the #[PaidJob] tool still charges, so the network is checked.
        $warmer = new NetworkWarmer(self::SOLANA, '', self::PAID_JOB, [new EvmExact()]);
        $this->expectException(\InvalidArgumentException::class);
        $warmer->warmUp(sys_get_temp_dir());
    }

    public function testItIsNotOptional(): void
    {
        self::assertFalse((new NetworkWarmer(null, null, [], []))->isOptional());
    }
}
