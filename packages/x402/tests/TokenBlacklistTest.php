<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\Networks;
use Quebracho\X402\TokenBlacklist;

/**
 * TokenBlacklist against a scripted node: what it asks, and what each answer does.
 */
final class TokenBlacklistTest extends TestCase
{
    private const ADDRESS = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    private const BASE = 'eip155:84532';
    private const FROZEN = '0x0000000000000000000000000000000000000000000000000000000000000001';
    private const FREE = '0x0000000000000000000000000000000000000000000000000000000000000000';

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function tearDown(): void
    {
        Networks::reset();
    }

    private function listener(string $body, bool $failOpen = false, int $status = 200, array $tokens = []): TokenBlacklist
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

        return new TokenBlacklist($client, $f, $f, $failOpen, $tokens);
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
            $threw = false;
            try {
                ($this->listener($body))($this->check());
            } catch (\Throwable) {
                // AddressCheck::start() turns this into address_check_failed.
                $threw = true;
            }
            self::assertTrue($threw, 'Should not pass: '.$body);
            $check = $this->check();
            ($this->listener($body, failOpen: true))($check);
            self::assertNull($check->reason());
        }
    }

    public function testTheDefaultIsFailClosed(): void
    {
        $f = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{"jsonrpc":"2.0","id":1,"error":{"message":"execution reverted"}}');
            }
        };

        $this->expectException(\RuntimeException::class);
        (new TokenBlacklist($client, $f, $f))($this->check());
    }

    /**
     * Each token asks its own function: USDC and USDT (Ethereum).
     */
    public function testEachTokenAsksItsOwnFunction(): void
    {
        Networks::add(1, '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'Tether USD', '1', rpc: 'https://eth.example');
        $expected = [
            self::BASE => '0xfe575a87',
            'eip155:1' => '0xe47d6060',
        ];
        foreach ($expected as $network => $selector) {
            $check = $this->check($network);
            ($this->listener($this->answer(self::FROZEN)))($check);

            self::assertSame('address_blacklisted', $check->reason(), $network);
            self::assertSame($selector, substr(end($this->calls)['params'][0]['data'], 0, 10), $network);
            self::assertSame(Networks::get($network)['asset'], end($this->calls)['params'][0]['to'], $network);
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function mainnetUsdc(): iterable
    {
        yield 'Ethereum' => [1, '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48'];
        yield 'Base' => [8453, '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'];
        yield 'Polygon' => [137, '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359'];
        yield 'Avalanche' => [43114, '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E'];
        yield 'Optimism' => [10, '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85'];
        yield 'Arbitrum' => [42161, '0xaf88d065e77c8cC2239327C5EDb3A432268e5831'];
        yield 'Arc' => [5042, '0x3600000000000000000000000000000000000000'];
    }

    /**
     * Mainnet USDC is checked by default once the site registers the network.
     */
    #[DataProvider('mainnetUsdc')]
    public function testMainnetUsdcIsAskedWithCirclesFunction(int $chainId, string $usdc): void
    {
        $network = Networks::add($chainId, $usdc, 'USD Coin', '2', rpc: 'https://rpc.example');
        $check = $this->check($network);
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertSame('address_blacklisted', $check->reason(), $network);
        self::assertSame('0xfe575a87', substr(end($this->calls)['params'][0]['data'], 0, 10), $network);
        self::assertSame($usdc, end($this->calls)['params'][0]['to'], $network);
    }

    public function testEthereumIsBuiltInSoItsUsdcIsAskedWithoutRegisteringAnything(): void
    {
        $check = $this->check('eip155:1');
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertSame('address_blacklisted', $check->reason());
        self::assertSame('0xfe575a87', substr($this->calls[0]['params'][0]['data'], 0, 10));
        self::assertSame(Networks::get('eip155:1')['rpc'], $this->calls[0]['url']);
        self::assertSame('0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', $this->calls[0]['params'][0]['to']);
    }

    public function testAnAssetWithoutAKnownFunctionIsNotAsked(): void
    {
        Networks::add(777, '0x1111111111111111111111111111111111111111', 'Some Token', '1', rpc: 'https://x.example');
        $check = $this->check('eip155:777');
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertNull($check->reason());
        self::assertSame([], $this->calls);
    }

    public function testUsdt0IsNotAskedUntilYouAddIt(): void
    {
        Networks::add(30, '0x779dED0C9e1022225F8e0630b35A9B54Be713736', 'USDT0', '1', rpc: 'https://rsk.example');
        $check = $this->check('eip155:30');
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertNull($check->reason());
        self::assertSame([], $this->calls);
    }

    public function testYourOwnTokenAndAMistypedFunction(): void
    {
        Networks::add(777, '0x1111111111111111111111111111111111111111', 'Some Token', '1', rpc: 'https://x.example');
        $check = $this->check('eip155:777');
        // The asset in any case, as people paste it.
        ($this->listener($this->answer(self::FROZEN), tokens: ['eip155:777|0x1111111111111111111111111111111111111111' => 'getBlackListStatus']))($check);

        self::assertSame('address_blacklisted', $check->reason());
        self::assertSame('0x59bf1abe', substr($this->calls[0]['params'][0]['data'], 0, 10));

        $this->expectException(\InvalidArgumentException::class);
        $this->listener($this->answer(self::FREE), tokens: ['eip155:777|0x1' => 'isbad']);
    }

    public function testADeclaredBlacklistIsAskedForItsOwnToken(): void
    {
        $usdc = '0x1111111111111111111111111111111111111111';
        Networks::add(777, $usdc, 'Some Token', '1', rpc: 'https://x.example', blacklist: 'isBlacklisted');
        $check = $this->check('eip155:777');
        ($this->listener($this->answer(self::FROZEN)))($check);

        self::assertSame('address_blacklisted', $check->reason());
        self::assertSame('0xfe575a87', substr($this->calls[0]['params'][0]['data'], 0, 10));
        self::assertSame($usdc, $this->calls[0]['params'][0]['to']);

        // Another asset on the same chain is not that token: not asked.
        $other = new AddressCheck(AddressCheck::PAYER, 'eip155:777', self::ADDRESS, ['network' => 'eip155:777', 'asset' => '0x2222222222222222222222222222222222222222']);
        ($this->listener($this->answer(self::FROZEN)))($other);
        self::assertNull($other->reason());
        self::assertCount(1, $this->calls);
    }

    public function testADeclaredTokenIsAskedWhateverTheCaseOfItsAddress(): void
    {
        // Registered checksummed, required in lower case and in another mix: the same token.
        $usdc = '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48';
        Networks::add(778, $usdc, 'USD Coin', '2', rpc: 'https://x.example', blacklist: 'isBlacklisted');
        foreach ([strtolower($usdc), '0xa0B86991C6218B36C1D19d4A2E9eB0Ce3606Eb48'] as $asset) {
            $check = new AddressCheck(AddressCheck::PAYER, 'eip155:778', self::ADDRESS, ['network' => 'eip155:778', 'asset' => $asset]);
            ($this->listener($this->answer(self::FROZEN)))($check);

            self::assertSame('address_blacklisted', $check->reason(), $asset);
            self::assertSame('0xfe575a87', substr(end($this->calls)['params'][0]['data'], 0, 10), $asset);
        }
    }

    public function testTheTokensArgumentWinsOverTheDeclaredFunction(): void
    {
        $usdc = '0x1111111111111111111111111111111111111111';
        Networks::add(777, $usdc, 'Some Token', '1', rpc: 'https://x.example', blacklist: 'isBlacklisted');
        $check = $this->check('eip155:777');
        ($this->listener($this->answer(self::FROZEN), tokens: ['eip155:777|'.$usdc => 'getBlackListStatus']))($check);

        self::assertSame('0x59bf1abe', substr($this->calls[0]['params'][0]['data'], 0, 10));
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
        foreach (['0x1234', self::ADDRESS.'ff', 'x'.self::ADDRESS] as $address) {
            $check = $this->check(address: $address);
            ($this->listener($this->answer(self::FREE)))($check);

            self::assertSame('invalid_payload', $check->reason(), $address);
        }
        self::assertSame([], $this->calls);
    }
}
