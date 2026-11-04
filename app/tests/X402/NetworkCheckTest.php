<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\NetworkCheck;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\EvmExact;

final class NetworkCheckTest extends TestCase
{
    public function testAKnownNetworkWithAScheme(): void
    {
        NetworkCheck::assertSupported('eip155:84532', [new EvmExact()]);
        $this->expectNotToPerformAssertions();
    }

    public function testAnUnknownNetworkIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"eip155:999999" is not a known network');
        NetworkCheck::assertSupported('eip155:999999', [new EvmExact()]);
    }

    public function testANetworkNoSchemeSupportsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1" is supported by no registered scheme (registered: Quebracho\X402\EvmExact).');
        NetworkCheck::assertSupported('solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', [new EvmExact()]);
    }
}
