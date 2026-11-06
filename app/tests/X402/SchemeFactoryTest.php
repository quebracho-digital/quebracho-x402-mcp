<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\SchemeFactory;
use PHPUnit\Framework\TestCase;

final class SchemeFactoryTest extends TestCase
{
    private const REQUIRED = [
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'amount' => '10000',
        'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        'payTo' => '0xcb2d000000000000000000000000000000000000',
        'maxTimeoutSeconds' => 60,
        'extra' => ['name' => 'USDC', 'version' => '2'],
    ];

    public function testWithoutKeyItVerifiesButRefusesToPay(): void
    {
        $scheme = SchemeFactory::evmExact('');

        self::assertTrue($scheme->supports('eip155:84532'));
        $this->expectException(\LogicException::class);
        $scheme->pay(['x402Version' => 2, 'accepts' => [self::REQUIRED]], self::REQUIRED);
    }

    public function testAMalformedKeyFailsAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemeFactory::evmExact('0x1234');
    }
}
