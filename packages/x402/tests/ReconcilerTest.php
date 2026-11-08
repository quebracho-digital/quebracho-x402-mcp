<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\FileLedger;
use Quebracho\X402\ReceiptLookup;
use Quebracho\X402\Reconciler;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The reconciler against a scripted node: each JSON-RPC method answers what the script says.
 */
final class ReconcilerTest extends TestCase
{
    private const NET = 'eip155:84532';
    private const USDC = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
    private const FROM = '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23';
    private const NONCE = '0x7a1c0ef4f6b6d8c2a0b9e3d5c7f1a2b4c6d8e0f2a4b6c8d0e2f4a6b8c0d2e4f6';
    private const TX = '0xef2ee93a74e2e62dfbcabff838f123de2993a958f4656ee6fdaed9fc6cd6b20a';
    private const OTHER_TX = '0x1111111111111111111111111111111111111111111111111111111111111111';
    private const USED = '0x0000000000000000000000000000000000000000000000000000000000000001';
    private const UNUSED = '0x0000000000000000000000000000000000000000000000000000000000000000';

    private string $dir;
    private FileLedger $ledger;
    /** @var list<array{method: string, params: array<mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/x402-reconcile-'.bin2hex(random_bytes(6));
        $this->ledger = new FileLedger($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $script method => result, or a \Throwable to fail that call
     */
    private function reconciler(array $script): Reconciler
    {
        $node = new MockHttpClient(function (string $method, string $url, array $options) use ($script) {
            $call = json_decode($options['body'], true);
            $this->calls[] = ['method' => $call['method'], 'params' => $call['params']];
            $answer = $script[$call['method']] ?? throw new \LogicException('unscripted '.$call['method']);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new JsonMockResponse(['jsonrpc' => '2.0', 'id' => 1, 'result' => $answer]);
        });
        $f = new Psr17Factory();

        return new Reconciler($this->ledger, new Psr18Client($node), $f, $f);
    }

    /**
     * A payment left as a crash leaves it: its state and its open entry.
     *
     * @param array<string, mixed> $ref
     * @param array<string, mixed> $state
     */
    private function seed(string $status, array $ref = [], array $state = []): string
    {
        $key = 'k-'.bin2hex(random_bytes(4));
        $tx = $ref['transaction'] ?? null;
        $this->ledger->put($key, ['status' => $status, 'receipt' => array_filter(['success' => false, 'transaction' => $tx, 'network' => self::NET])] + $state, 86400);
        $this->ledger->open($key, $ref + [
            'network' => self::NET, 'asset' => self::USDC, 'from' => self::FROM, 'nonce' => self::NONCE,
            'payTo' => '0x000000000000000000000000000000000000dEaD', 'amount' => '10000',
            'validBefore' => time() + 300, 'transaction' => null, 'paymentId' => null, 'status' => $status, 'upfront' => false,
        ], 259200);

        return $key;
    }

    /** @return array{key: string, status: string, result: string, detail: string} */
    private function only(array $results): array
    {
        self::assertCount(1, $results);

        return $results[0];
    }

    public function testAUsedAuthorizationWhoseTransactionSucceededIsSettled(): void
    {
        $key = $this->seed('settling', ['transaction' => self::TX]);

        $result = $this->only($this->reconciler(['eth_call' => self::USED, 'eth_getTransactionReceipt' => ['status' => '0x1']])->reconcile(10));
        self::assertSame(['key' => $key, 'status' => 'settling', 'result' => 'settled'], array_diff_key($result, ['detail' => 0]));
        $state = $this->ledger->get($key);
        self::assertSame('settled', $state['status']);
        self::assertSame(['success' => true, 'transaction' => self::TX, 'network' => self::NET, 'payer' => self::FROM], $state['receipt']);
        self::assertArrayNotHasKey('handler_ran', $state, 'default flow: a retry answers already_settled');
        self::assertSame([], $this->ledger->openKeys(10));
        // authorizationState(from, nonce) on the offer's asset.
        self::assertSame([['to' => self::USDC, 'data' => '0xe94a0102'.str_pad(substr(self::FROM, 2), 64, '0', \STR_PAD_LEFT).substr(self::NONCE, 2)], 'latest'], $this->calls[0]['params']);
    }

    public function testAnUpfrontPaymentIsSettledWithItsHandlerNotRun(): void
    {
        $key = $this->seed('settling', ['transaction' => self::TX, 'upfront' => true]);

        $this->reconciler(['eth_call' => self::USED, 'eth_getTransactionReceipt' => ['status' => '0x1']])->reconcile(10);
        self::assertFalse($this->ledger->get($key)['handler_ran'], 'a retry runs the handler, no second settle');
    }

    public function testAUsedAuthorizationWhoseTransactionRevertedNeedsReview(): void
    {
        $key = $this->seed('settling', ['transaction' => self::TX]);

        self::assertSame('needs_review', $this->only($this->reconciler(['eth_call' => self::USED, 'eth_getTransactionReceipt' => ['status' => '0x0']])->reconcile(10))['result']);
        self::assertSame('settling', $this->ledger->get($key)['status'], 'not freed');
        self::assertArrayHasKey($key, $this->ledger->openKeys(10));
    }

    public function testAUsedAuthorizationWithoutATransactionIsSettledWithTheEventsTransaction(): void
    {
        $key = $this->seed('settling');
        $authorizer = '0x'.str_pad(substr(self::FROM, 2), 64, '0', \STR_PAD_LEFT);
        $log = ['address' => strtolower(self::USDC), 'topics' => [ReceiptLookup::AUTHORIZATION_USED, $authorizer, self::NONCE], 'transactionHash' => self::OTHER_TX];

        $result = $this->only($this->reconciler(['eth_call' => self::USED, 'eth_blockNumber' => '0x2710f', 'eth_getLogs' => [$log]])->reconcile(10));
        self::assertSame('settled', $result['result']);
        self::assertSame(self::OTHER_TX, $this->ledger->get($key)['receipt']['transaction']);
        self::assertSame([], $this->ledger->openKeys(10));
        $filter = $this->calls[2]['params'][0];
        self::assertSame([self::USDC, [ReceiptLookup::AUTHORIZATION_USED, $authorizer, self::NONCE], '0x249ff', 'latest'], [$filter['address'], $filter['topics'], $filter['fromBlock'], $filter['toBlock']]);
    }

    public function testAUsedAuthorizationWithoutATransactionOrEventNeedsReview(): void
    {
        $key = $this->seed('settling');

        self::assertSame('needs_review', $this->only($this->reconciler(['eth_call' => self::USED, 'eth_blockNumber' => '0x10', 'eth_getLogs' => []])->reconcile(10))['result']);
        self::assertSame('settling', $this->ledger->get($key)['status']);
        self::assertArrayHasKey($key, $this->ledger->openKeys(10));
    }

    public function testAnUnusedExpiredAuthorizationIsReleased(): void
    {
        $key = $this->seed('pending', ['transaction' => self::TX, 'validBefore' => time() - 61]);

        self::assertSame('released', $this->only($this->reconciler(['eth_call' => self::UNUSED])->reconcile(10))['result']);
        self::assertNull($this->ledger->get($key));
        self::assertSame([], $this->ledger->openKeys(10));
    }

    public function testAnUnusedAuthorizationStillValidIsUntouched(): void
    {
        $key = $this->seed('settling', ['validBefore' => time() - 30]);

        self::assertSame('untouched', $this->only($this->reconciler(['eth_call' => self::UNUSED])->reconcile(10))['result'], 'inside the 60 s grace');
        self::assertSame('settling', $this->ledger->get($key)['status']);
        self::assertArrayHasKey($key, $this->ledger->openKeys(10));
    }

    public function testAnRpcThatFailsChangesNothing(): void
    {
        $key = $this->seed('settling', ['transaction' => self::TX]);
        $before = [$this->ledger->get($key), $this->ledger->openKeys(10)];

        $result = $this->only($this->reconciler(['eth_call' => self::USED, 'eth_getTransactionReceipt' => new TransportException('node down')])->reconcile(10));
        self::assertSame('untouched', $result['result']);
        self::assertStringContainsString('node down', $result['detail']);
        self::assertSame($before, [$this->ledger->get($key), $this->ledger->openKeys(10)]);
    }

    public function testAnEntryOpenForMoreThanADayUnresolvedNeedsReview(): void
    {
        $key = $this->seed('settling', ['opened' => time() - 86401]);

        self::assertSame('needs_review', $this->only($this->reconciler(['eth_call' => self::UNUSED])->reconcile(10))['result']);
        self::assertSame('settling', $this->ledger->get($key)['status'], 'nothing freed');
        self::assertArrayHasKey($key, $this->ledger->openKeys(10));
    }

    public function testANetworkWithoutRpcIsAnRpcError(): void
    {
        $this->seed('settling', ['network' => 'eip155:999999']);

        self::assertSame('rpc_error', $this->only($this->reconciler([])->reconcile(10))['result']);
        self::assertSame([], $this->calls);
    }

    public function testAnEntryWhoseStateWasAlreadyDeliveredIsClosedUntouched(): void
    {
        $key = $this->seed('settling');
        $this->ledger->put($key, ['status' => 'delivered'], 600);

        self::assertSame('untouched', $this->only($this->reconciler([])->reconcile(10))['result']);
        self::assertSame(['status' => 'delivered'], $this->ledger->get($key));
        self::assertSame([], $this->ledger->openKeys(10));
    }

    public function testTheLimitBoundsTheRun(): void
    {
        $this->seed('settling');
        $this->seed('settling');

        self::assertCount(1, $this->reconciler(['eth_call' => self::UNUSED])->reconcile(1));
    }
}
