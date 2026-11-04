<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Facilitator;
use App\X402\ReceiptLookup;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

        return new ReceiptLookup(
            new Facilitator($mode, new NullLogger(), $facilitator ?? new MockHttpClient()),
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

    public function testAConfirmedEvmTransactionSettles(): void
    {
        $url = null;
        $out = $this->lookup($this->node(['status' => '0x1'], $url))($this->pending(['transaction' => self::TX]));

        $this->assertSame(['success' => true, 'transaction' => self::TX, 'network' => self::NET, 'payer' => null], $out);
        $this->assertSame('https://sepolia.base.org', rtrim((string) $url, '/'), 'asks the network\'s own RPC');
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

        $completed = $this->lookup($this->node(null), $answer(['status' => 'completed', 'transaction' => self::TX, 'payer' => self::PAYER]))($state);
        $this->assertSame(['success' => true, 'transaction' => self::TX, 'network' => self::NET, 'payer' => self::PAYER], $completed);

        $failed = $this->lookup($this->node(null), $answer(['status' => 'failed', 'reason' => 'insufficient_funds']))($state);
        $this->assertSame(['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => self::NET], $failed);

        $this->assertNull($this->lookup($this->node(null), $answer(['status' => 'pending']))($state));
        $this->assertNull($this->lookup($this->node(null), new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500])))($state));
        $this->assertNull($this->lookup($this->node(null), mode: 'mock')($state), 'the mock facilitator has no status');
    }
}
