<?php

declare(strict_types=1);

namespace App\Tests;

use App\X402\PayeeAllowlist;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\AddressCheck;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PayeeAllowlistTest extends TestCase
{
    private const OURS = '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794';

    private static function verdict(string $allow, string $address): ?string
    {
        $events = new EventDispatcher();
        $events->addListener(AddressCheck::class, new PayeeAllowlist($allow));

        return AddressCheck::run($events, AddressCheck::PAY_TO, $address, ['network' => 'eip155:84532']);
    }

    public function testPaysOnlyListedWalletsAndDeniesByDefault(): void
    {
        self::assertNull(self::verdict(self::OURS, strtolower(self::OURS)));
        self::assertNull(self::verdict(strtolower(self::OURS), self::OURS), 'sellers send checksummed addresses');
        self::assertSame('payto_not_allowed', self::verdict(self::OURS, '0x000000000000000000000000000000000000dEaD'));
        self::assertSame('payto_not_allowed', self::verdict('', self::OURS), 'an empty list pays nobody');
    }
}
