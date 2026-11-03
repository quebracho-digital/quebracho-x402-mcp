<?php

declare(strict_types=1);

namespace App\Tests;

use App\X402\BuyerBudget;
use PHPUnit\Framework\TestCase;

final class BuyerBudgetTest extends TestCase
{
    private const PUBLIC_KEY = '0x4C0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    public function testThePublicKeyNeverGetsACapOnAMainnet(): void
    {
        $used = static fn () => false;
        self::assertNotNull(BuyerBudget::create(['eip155:84532' => '0.05'], [], $used, self::PUBLIC_KEY));
        self::assertNotNull(BuyerBudget::create(['eip155:5042' => '0.05'], [], $used, '0x'.str_repeat('1', 64)));

        $this->expectException(\LogicException::class);
        BuyerBudget::create(['eip155:5042' => '0.05'], [], $used, self::PUBLIC_KEY);
    }
}
