<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\ReceiptLookup;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReceiptLookupTest extends TestCase
{
    private const TX = '0xef2ee93a74e2e62dfbcabff838f123de2993a958f4656ee6fdaed9fc6cd6b20a';
    private const NET = 'eip155:84532';
    private const PAYER = '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23';

    /** A node that answers every JSON-RPC call with $result (NULL: not mined). */
    private function node(mixed $result, ?string &$url = null): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $requested) use ($result, &$url) {
            $url = $requested;

            return new JsonMockResponse(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    private function lookup(MockHttpClient $node, ?MockHttpClient $facilitator = null, string $mode = 'https://f.example/facilitator/'): ReceiptLookup
    {
        $factory = new Psr17Factory();
        $client = new Psr18Client($facilitator ?? new MockHttpClient());
        // Only Circle's facilitator has a status route (PaymentStatus); any other, or the mock, has none.
        $status = str_starts_with($mode, CircleFacilitator::URL) ? new CircleFacilitator($client, $client, $client) : null;

        return new ReceiptLookup(
            $status,
            new Psr18Client($node),
            $factory,
            $factory,
        );
    }

    /** The state the middleware stores for a pending payment: the settle receipt under 'receipt'. */
    private function pending(array $receipt): array
    {
        return ['status' => 'pending', 'receipt' => ['success' => false, 'errorReason' => 'settlement_pending', 'network' => self::NET] + $receipt];
    }

    private const NONCE = '0x7a1c0ef4f6b6d8c2a0b9e3d5c7f1a2b4c6d8e0f2a4b6c8d0e2f4a6b8c0d2e4f6';
    private const USDC = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

    /** The buyer's payment and the requirements it paid, as the middleware hands them to the lookup. */
    private const PAYMENT = ['payload' => ['authorization' => ['from' => '0x2C7536E3605D9C16a7a3D7b1898e529396a65c23', 'nonce' => self::NONCE]]];
    private const REQ = ['asset' => self::USDC];

    /** A mined success whose logs hold the asset's AuthorizationUsed for $from and $nonce. */
    private static function mined(string $from = self::PAYER, string $nonce = self::NONCE, string $asset = self::USDC): array
    {
        $authorizer = '0x'.str_pad(substr($from, 2), 64, '0', \STR_PAD_LEFT);

        return ['status' => '0x1', 'logs' => [
            ['address' => $asset, 'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', $authorizer, $authorizer]],
            ['address' => strtolower($asset), 'topics' => [ReceiptLookup::AUTHORIZATION_USED, $authorizer, $nonce]],
        ]];
    }

    public function testAConfirmedEvmTransactionSettles(): void
    {
        $url = null;
        $out = $this->lookup($this->node(self::mined(), $url))($this->pending(['transaction' => self::TX]), self::PAYMENT, self::REQ);

        $this->assertSame(['success' => true, 'transaction' => self::TX, 'network' => self::NET, 'payer' => null], $out);
        $this->assertSame('https://sepolia.base.org', rtrim((string) $url, '/'), 'asks the network\'s own RPC');
    }

    public function testTheTopicIsKeccakOfTheEventSignature(): void
    {
        $this->assertSame(\Quebracho\X402\Eip712::keccak('AuthorizationUsed(address,bytes32)'), ReceiptLookup::AUTHORIZATION_USED);
    }

    public function testAMinedSuccessThatDidNotSpendThisAuthorizationIsUnknown(): void
    {
        $state = $this->pending(['transaction' => self::TX]);
        $other = '0x'.str_repeat('1', 64);

        $this->assertNull($this->lookup($this->node(self::mined(nonce: $other)))($state, self::PAYMENT, self::REQ), 'another nonce');
        $this->assertNull($this->lookup($this->node(self::mined(from: '0x'.str_repeat('2', 40))))($state, self::PAYMENT, self::REQ), 'another authorizer');
        $this->assertNull($this->lookup($this->node(self::mined(asset: '0x'.str_repeat('3', 40))))($state, self::PAYMENT, self::REQ), 'emitted by another contract');
        $this->assertNull($this->lookup($this->node(['status' => '0x1']))($state, self::PAYMENT, self::REQ), 'no logs');
        $this->assertNull($this->lookup($this->node(self::mined()))($state), 'no payment to compare: unknown, fail-closed');
    }

    public function testARevertedEvmTransactionIsRefuted(): void
    {
        $out = $this->lookup($this->node(['status' => '0x0']))($this->pending(['transaction' => self::TX]));

        $this->assertSame(['success' => false, 'errorReason' => 'transaction_reverted', 'transaction' => self::TX, 'network' => self::NET], $out);
    }

    public function testAnUnminedEvmTransactionIsUnknown(): void
    {
        $this->assertNull($this->lookup($this->node(null))($this->pending(['transaction' => self::TX])));
    }

    public function testANetworkErrorIsUnknownNotRefuted(): void
    {
        $down = new MockHttpClient(static fn () => new MockResponse('', ['error' => 'timeout']));

        $this->assertNull($this->lookup($down)($this->pending(['transaction' => self::TX])));
    }

    public function testAStateWithoutTransactionOrPaymentIdIsUnknown(): void
    {
        $lookup = $this->lookup($this->node(['status' => '0x1']));

        $this->assertNull($lookup($this->pending([])));
        $this->assertNull($lookup(['status' => 'pending']));
        $this->assertNull($lookup($this->pending(['transaction' => 'not-a-hash'])));
    }

    public function testAFacilitatorPaymentIdFollowsItsStatus(): void
    {
        $answer = static fn (array $body) => new MockHttpClient(static fn (string $method, string $url) => new JsonMockResponse($body + ['requested' => $url]));
        $state = $this->pending(['paymentId' => 'circle-1']);
        $circle = 'https://api.circle.com/v1/facilitator/x402';

        $completed = $this->lookup($this->node(null), $answer(['status' => 'completed', 'transaction' => self::TX, 'payer' => self::PAYER]), $circle)($state);
        $this->assertSame(['success' => true, 'transaction' => self::TX, 'network' => self::NET, 'payer' => self::PAYER], $completed);

        $failed = $this->lookup($this->node(null), $answer(['status' => 'failed', 'reason' => 'insufficient_funds']), $circle)($state);
        $this->assertSame(['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => self::NET], $failed);

        $this->assertNull($this->lookup($this->node(null), $answer(['status' => 'pending']), $circle)($state));
        $this->assertNull($this->lookup($this->node(null), new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500])), $circle)($state));
        $this->assertNull($this->lookup($this->node(null), $answer(['status' => 'completed', 'transaction' => self::TX]))($state), 'a generic facilitator has no status route');
        $this->assertNull($this->lookup($this->node(null), mode: 'mock')($state), 'the mock facilitator has no status');
    }
}
