<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\Networks;
use Quebracho\X402\UsdcBlacklist;

/**
 * UsdcBlacklist against a scripted node: what it asks, and what each answer does.
 */
final class UsdcBlacklistTest extends TestCase
{
    private const ADDRESS = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    private const BASE = 'eip155:84532';
    private const FROZEN = '0x0000000000000000000000000000000000000000000000000000000000000001';
    private const FREE = '0x0000000000000000000000000000000000000000000000000000000000000000';

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    private function listener(string $body, bool $failOpen = false, int $status = 200): UsdcBlacklist
    {
        $client = new class($body, $status, $this->calls) implements ClientInterface {
            public function __construct(private string $body, private int $status, private array &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls[] = ['url' => (string) $request->getUri()] + json_decode((string) $request->getBody(), true);

                return new Response($this->status, [], $this->body);
            }
        };
        $f = new Psr17Factory();

        return new UsdcBlacklist($client, $f, $f, $failOpen);
    }

    private function check(string $network = self::BASE, string $address = self::ADDRESS, ?string $role = null): AddressCheck
    {
        $asset = Networks::get($network)['asset'] ?? 'x';

        return new AddressCheck($role ?? AddressCheck::PAYER, $network, $address, ['network' => $network, 'asset' => $asset]);
    }

    private function answer(string $hex): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $hex]);
    }

    public function testFrozenAddressIsRejectedAndTheCallAsksTheTokenAboutIt(): void
    {
        $check = $this->check();
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertSame('address_blacklisted', $check->reason());
        $call = $this->calls[0];
        self::assertSame('eth_call', $call['method']);
        self::assertSame(Networks::get(self::BASE)['rpc'], $call['url']);
        self::assertSame(Networks::get(self::BASE)['asset'], $call['params'][0]['to']);
        self::assertSame('0xfe575a87'.str_pad(strtolower(substr(self::ADDRESS, 2)), 64, '0', \STR_PAD_LEFT), $call['params'][0]['data']);
    }

    public function testFreeAddressPassesOnBothSides(): void
    {
        foreach ([AddressCheck::PAYER, AddressCheck::PAY_TO] as $role) {
            $check = $this->check(role: $role);
            ($this->listener($this->answer(self::FREE)))($check);
            self::assertNull($check->reason());
        }
    }

    public function testANodeThatCannotAnswerFailsClosedUnlessToldOtherwise(): void
    {
        // A revert (token without isBlacklisted), an HTML error page, an EOA ("0x"): never a pass.
        foreach (['{"jsonrpc":"2.0","id":1,"error":{"code":3,"message":"execution reverted"}}', 'not json', $this->answer('0x')] as $body) {
            try {
                ($this->listener($body))($this->check());
                self::fail('Should not pass: '.$body);
            } catch (\Throwable) {
                // AddressCheck::start() turns this into address_check_failed.
                self::addToAssertionCount(1);
            }
            $check = $this->check();
            ($this->listener($body, failOpen: true))($check);
            self::assertNull($check->reason());
        }
    }

    public function testNetworksWithoutAnEvmBlacklistPassWithoutAskingAnyone(): void
    {
        foreach (['solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', 'near:testnet'] as $network) {
            $check = new AddressCheck(AddressCheck::PAYER, $network, 'alice.testnet', ['network' => $network, 'asset' => 'x']);
            ($this->listener(self::FROZEN))($check);
            self::assertNull($check->reason(), $network);
        }
        self::assertSame([], $this->calls);
    }

    public function testMalformedAddressIsRejectedWithoutACall(): void
    {
        $check = $this->check(address: '0x1234');
        ($this->listener($this->answer(self::FREE)))($check);

        self::assertSame('invalid_payload', $check->reason());
        self::assertSame([], $this->calls);
    }
}
