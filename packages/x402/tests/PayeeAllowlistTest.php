<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\PayeeAllowlist;

final class PayeeAllowlistTest extends TestCase
{
    private const OURS = '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794';
    private const SOLANA = '9xQeWvG816bUx9EPjHmaT23yvVM2ZWbrrpZb9PusVFin';

    private static function verdict(PayeeAllowlist $allow, string $address, string $role = AddressCheck::PAY_TO, string $network = 'eip155:84532'): ?string
    {
        $events = new class($allow) implements EventDispatcherInterface {
            public function __construct(private readonly PayeeAllowlist $allow)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof AddressCheck) {
                    ($this->allow)($event);
                }

                return $event;
            }
        };

        return AddressCheck::run($events, $role, $address, ['network' => $network]);
    }

    public function testPaysOnlyListedWalletsAndDeniesByDefault(): void
    {
        self::assertNull(self::verdict(PayeeAllowlist::fromCsv(self::OURS), strtolower(self::OURS)));
        self::assertNull(self::verdict(PayeeAllowlist::fromCsv(strtolower(self::OURS)), self::OURS), 'sellers send checksummed addresses');
        self::assertSame(PayeeAllowlist::REASON, self::verdict(PayeeAllowlist::fromCsv(self::OURS), '0x000000000000000000000000000000000000dEaD'));
        self::assertSame(PayeeAllowlist::REASON, self::verdict(PayeeAllowlist::fromCsv(''), self::OURS), 'an empty list pays nobody');
        self::assertSame(PayeeAllowlist::REASON, self::verdict(new PayeeAllowlist(['', '  ', null]), self::OURS), 'blanks allow nobody');
    }

    public function testOtherFormsMatchExactlyAndSellersAreNotNarrowed(): void
    {
        $allow = new PayeeAllowlist([' '.self::SOLANA.' ']);
        self::assertNull(self::verdict($allow, self::SOLANA, network: 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1'));
        self::assertSame(PayeeAllowlist::REASON, self::verdict($allow, strtolower(self::SOLANA), network: 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1'), 'base58 has case');
        self::assertNull(self::verdict($allow, '0x000000000000000000000000000000000000dEaD', AddressCheck::PAYER), 'a seller screens its payers with other lists');
    }
}
