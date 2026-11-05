<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\ChainFacilitator;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\Eip712;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\PaymentStatus;
use Quebracho\X402\Rpc;
use Quebracho\X402\Signer;

/**
 * HttpFacilitator (any URL), CircleFacilitator and ChainFacilitator against a scripted PSR-18 client.
 */
final class FacilitatorsTest extends TestCase
{
    private const BUYER = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
    private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    private const TX = '0xabababababababababababababababababababababababababababababababab';
    private const NETWORK = 'eip155:5042002';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * A client answering each request with $answer($request), and recording it.
     */
    private function client(\Closure $answer): ClientInterface
    {
        return new class($answer, $this->sent) implements ClientInterface {
            public function __construct(private \Closure $answer, private array &$sent)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return ($this->answer)($request);
            }
        };
    }

    private function circle(array $responses, ?string $bearer = null, ?Signer $seller = null): CircleFacilitator
    {
        $f = new Psr17Factory();

        return new CircleFacilitator($this->client(function () use (&$responses) {
            return array_shift($responses);
        }), $f, $f, $bearer, $seller, pollDelaySeconds: 0);
    }

    private function http(array $responses): HttpFacilitator
    {
        $f = new Psr17Factory();

        return new HttpFacilitator($this->client(function () use (&$responses) {
            return array_shift($responses);
        }), $f, $f, 'https://x402.example/facilitator/');
    }

    /**
     * A node answering JSON-RPC by method; a \RuntimeException answers with an error.
     */
    private function chain(array $answers, ?\Closure $serialize = null): ChainFacilitator
    {
        $f = new Psr17Factory();
        $client = $this->client(function (RequestInterface $r) use ($answers) {
            $method = json_decode((string) $r->getBody(), true)['method'];
            $a = $answers[$method] ?? null;

            return new Response(200, [], json_encode($a instanceof \RuntimeException ? ['error' => ['message' => $a->getMessage()]] : ['result' => $a]));
        });

        return new ChainFacilitator(new Rpc($client, $f, $f, 'https://rpc.example'), new Signer('0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a'), self::NETWORK, $serialize ?? fn (string $name, callable $run) => $run(), null, 0, 3);
    }

    /** Every node answer a settle needs, so the transaction is sent. */
    private function sendable(): array
    {
        return ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0x1', 'eth_getTransactionCount' => '0x0', 'eth_sendRawTransaction' => self::TX];
    }

    private function payment(array $auth = []): array
    {
        return ['x402Version' => 2, 'payload' => [
            'signature' => '0x'.str_repeat('11', 32).str_repeat('22', 32).'1c',
            'authorization' => $auth + ['from' => self::BUYER, 'to' => self::PAY_TO, 'value' => '10000', 'validAfter' => '0', 'validBefore' => '9999999999', 'nonce' => '0x'.str_repeat('33', 32)],
        ]];
    }

    private function req(): array
    {
        return ['scheme' => 'exact', 'network' => self::NETWORK, 'amount' => '10000', 'asset' => '0x3600000000000000000000000000000000000000', 'payTo' => self::PAY_TO];
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testCalldataEqualsViem(): void
    {
        $this->assertSame(
            '0xe3ee160e000000000000000000000000f39fd6e51aad88f6f4ce6ab8827279cfffb9226600000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000000000000002710000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000002540be3ff3333333333333333333333333333333333333333333333333333333333333333000000000000000000000000000000000000000000000000000000000000001c11111111111111111111111111111111111111111111111111111111111111112222222222222222222222222222222222222222222222222222222222222222',
            ChainFacilitator::calldata($this->payment()),
        );
    }

    public function testChainSettleSendsAndWaitsForTheReceipt(): void
    {
        $result = $this->chain(['eth_gasPrice' => '0x5d21dba00', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0xea60', 'eth_getTransactionCount' => '0x7', 'eth_sendRawTransaction' => self::TX, 'eth_getTransactionReceipt' => ['status' => '0x1']])
            ->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        // The hash of what was signed, not whatever the node said.
        $raw = json_decode((string) $this->sent[4]->getBody(), true)['params'][0];
        $this->assertSame(Eip712::keccak(Eip712::raw($raw)), $result['transaction']);
        $methods = array_map(fn ($r) => json_decode((string) $r->getBody(), true)['method'], $this->sent);
        $this->assertSame(['eth_gasPrice', 'eth_maxPriorityFeePerGas', 'eth_estimateGas', 'eth_getTransactionCount', 'eth_sendRawTransaction', 'eth_getTransactionReceipt'], $methods);
    }

    public function testChainRefusesWithoutSpendingGas(): void
    {
        $cases = [
            'revert' => [$this->payment(), $this->req(), ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => new \RuntimeException('execution reverted: authorization is used')]],
            'other seller' => [$this->payment(['to' => '0x'.str_repeat('1', 40)]), $this->req(), []],
            'other amount' => [$this->payment(['value' => '1']), $this->req(), []],
            'expired' => [$this->payment(['validBefore' => '1']), $this->req(), []],
            'nonce spelled twice' => [$this->payment(['nonce' => '33']), $this->req(), []],
            'other network' => [$this->payment(), ['network' => 'eip155:84532'] + $this->req(), []],
        ];
        foreach ($cases as $case => [$payment, $req, $answers]) {
            $this->sent = [];
            $this->assertFalse($this->chain($answers)->settle($payment, $req)['success'], $case);
            $this->assertNotContains('eth_sendRawTransaction', array_map(fn ($r) => json_decode((string) $r->getBody(), true)['method'], $this->sent), $case);
        }
    }

    public function testChainKeepsTheHashWhenNotMinedOrReverted(): void
    {
        $base = ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0x1', 'eth_getTransactionCount' => '0x0', 'eth_sendRawTransaction' => self::TX];
        $pending = $this->chain($base + ['eth_getTransactionReceipt' => null])->settle($this->payment(), $this->req());
        $this->assertSame('settlement_pending', $pending['errorReason']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $pending['transaction']);
        // A send that errors after signing may have been broadcast: pending, with its hash.
        $lost = $this->chain(['eth_sendRawTransaction' => new \RuntimeException('already known')] + $base)->settle($this->payment(), $this->req());
        $this->assertSame('settlement_pending', $lost['errorReason']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $lost['transaction']);
        $reverted = $this->chain($base + ['eth_getTransactionReceipt' => ['status' => '0x0']])->settle($this->payment(), $this->req());
        $this->assertSame(['transaction_reverted', false], [$reverted['errorReason'], $reverted['success']]);
    }

    public function testALockFailureAfterTheSendKeepsTheHash(): void
    {
        // The lock throws after the transaction left (LockReleasingException): money moved, the claim stays.
        $serialize = function (string $name, callable $run): mixed {
            $run();
            throw new \RuntimeException('lock release failed');
        };
        $result = $this->chain($this->sendable(), $serialize)->settle($this->payment(), $this->req());

        $this->assertSame('settlement_pending', $result['errorReason']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $result['transaction']);
        $this->assertFalse($result['success']);
    }

    public function testALockFailureBeforeTheSendSendsNothing(): void
    {
        $result = $this->chain($this->sendable(), fn () => throw new \RuntimeException('lock busy'))->settle($this->payment(), $this->req());

        $this->assertSame(['lock busy', ''], [$result['errorReason'], $result['transaction']]);
        $this->assertSame([], $this->sent);
    }

    public function testALockErrorThatIsNotARuntimeExceptionIsARefusal(): void
    {
        $result = $this->chain($this->sendable(), fn () => throw new \TypeError('bad lock'))->settle($this->payment(), $this->req());

        $this->assertSame(['settle_refused', ''], [$result['errorReason'], $result['transaction']]);
        $this->assertSame([], $this->sent);
    }

    public function testChainHandsTheReferenceOverBeforeSendingAndSendsNothingWithoutIt(): void
    {
        $seen = [];
        $kept = $this->chain($this->sendable() + ['eth_getTransactionReceipt' => ['status' => '0x1']])
            ->settle($this->payment(), $this->req(), function (string $tx) use (&$seen): bool {
                $seen[] = [$tx, count($this->sent)];

                return true;
            });
        $this->assertTrue($kept['success']);
        // Handed over after the four reads, before the send.
        $this->assertSame([[$kept['transaction'], 4]], $seen);

        foreach (['refused' => fn () => false, 'thrown' => fn () => throw new \RuntimeException('store down')] as $case => $reference) {
            $this->sent = [];
            $result = $this->chain($this->sendable())->settle($this->payment(), $this->req(), $reference);
            $this->assertSame(['reference_unavailable', '', false], [$result['errorReason'], $result['transaction'], $result['success']], $case);
            $this->assertNotContains('eth_sendRawTransaction', array_map(fn ($r) => json_decode((string) $r->getBody(), true)['method'], $this->sent), $case);
        }
    }

    public function testChainGasCapSendsNothing(): void
    {
        $f = new Psr17Factory();
        $answers = ['eth_gasPrice' => '0x3b9aca00', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0xea60'];
        $client = $this->client(fn (RequestInterface $r) => new Response(200, [], json_encode(['result' => $answers[json_decode((string) $r->getBody(), true)['method']] ?? null])));
        $chain = new ChainFacilitator(new Rpc($client, $f, $f, 'https://rpc.example'), new Signer('0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a'), self::NETWORK, fn ($n, callable $run) => $run(), '1000', 0, 3);
        $result = $chain->settle($this->payment(), $this->req());
        $this->assertSame(['gas_too_expensive', ''], [$result['errorReason'], $result['transaction']]);
        $this->assertCount(3, $this->sent);
    }

    public function testChainVerifySimulates(): void
    {
        $this->assertTrue($this->chain(['eth_call' => '0x'])->verify($this->payment(), $this->req())['isValid']);
        $this->assertFalse($this->chain(['eth_call' => new \RuntimeException('reverted')])->verify($this->payment(), $this->req())['isValid']);
    }

    public function testKeylessProofSignsTheExactBodySent(): void
    {
        $seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
        $this->assertTrue($this->circle([$this->json(200, ['isValid' => true])], seller: $seller)->verify($this->payment(), $this->req())['isValid']);

        $request = $this->sent[0];
        $this->assertSame(CircleFacilitator::URL.'/verify', (string) $request->getUri());
        $this->assertSame(['x402Version' => 2, 'paymentPayload' => $this->payment(), 'paymentRequirements' => $this->req()], json_decode((string) $request->getBody(), true));
        $proof = json_decode(base64_decode(strtr($request->getHeaderLine('Facilitator-Seller-Proof'), '-_', '+/')), true);
        $digest = Eip712::hash(['name' => 'Circle Facilitator Seller Request', 'version' => '1', 'chainId' => 5042002], ['SellerRequest' => [
            ['name' => 'purpose', 'type' => 'string'], ['name' => 'method', 'type' => 'string'], ['name' => 'bodyHash', 'type' => 'bytes32'], ['name' => 'network', 'type' => 'string'],
            ['name' => 'payTo', 'type' => 'address'], ['name' => 'nonce', 'type' => 'bytes32'], ['name' => 'issuedAt', 'type' => 'uint64'], ['name' => 'expiresAt', 'type' => 'uint64'],
        ]], 'SellerRequest', ['purpose' => 'verify', 'method' => 'POST', 'bodyHash' => Eip712::keccak((string) $request->getBody()), 'network' => self::NETWORK, 'payTo' => $seller->address(), 'nonce' => $proof['nonce'], 'issuedAt' => $proof['issuedAt'], 'expiresAt' => $proof['expiresAt']]);
        $this->assertSame($seller->address(), Signer::recover($digest, $proof['signature']));
    }

    public function testASellerProofThatCannotBeBuiltRefusesBeforeSending(): void
    {
        // Solana is no EVM network for the proof: nothing leaves, and the refusal frees the claim.
        $seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
        $result = $this->circle([], seller: $seller)->settle($this->payment(), ['network' => 'solana:devnet'] + $this->req());

        $this->assertSame(['seller_proof_failed', '', 'solana:devnet'], [$result['errorReason'], $result['transaction'], $result['network']]);
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->sent);
    }

    public function testBearerReplacesTheProof(): void
    {
        $this->circle([$this->json(200, ['isValid' => true])], 'KEY', new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'))->verify($this->payment(), $this->req());
        $this->assertSame('Bearer KEY', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertFalse($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
    }

    public function testPendingSettleIsPolled(): void
    {
        $result = $this->circle([
            $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-1']]]),
            $this->json(200, ['status' => 'pending']),
            $this->json(200, ['status' => 'completed', 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => self::BUYER]),
        ], 'KEY')->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        $this->assertSame(CircleFacilitator::URL.'/status/pay-1', (string) $this->sent[2]->getUri());
    }

    public function testStillPendingAfterPollingKeepsThePaymentId(): void
    {
        $pending = $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-1']]]);
        $result = $this->circle([$pending, ...array_fill(0, 5, $this->json(200, ['status' => 'pending']))], 'K')->settle($this->payment(), $this->req());
        $this->assertSame(['settlement_pending', 'pay-1'], [$result['errorReason'], $result['paymentId']]);
    }

    public function testOnlyAConsistentSettleCounts(): void
    {
        $good = ['success' => true, 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => strtolower(self::BUYER)];
        $this->assertSame($good, $this->http([$this->json(200, $good)])->settle($this->payment(), $this->req()));
        foreach (['no transaction' => ['transaction' => ''], 'other network' => ['network' => 'eip155:7'], 'other payer' => ['payer' => self::PAY_TO]] as $case => $change) {
            $this->assertFalse($this->http([$this->json(200, $change + $good)])->settle($this->payment(), $this->req())['success'], $case);
        }
        $this->assertSame('insufficient_funds', $this->http([$this->json(402, ['success' => false, 'errorReason' => 'insufficient_funds'])])->settle($this->payment(), $this->req())['errorReason']);
        foreach (['503 with a success body' => $this->json(503, $good), 'HTML 502' => new Response(502, [], '<html>')] as $case => $response) {
            try {
                $this->http([$response])->settle($this->payment(), $this->req());
                $this->fail($case);
            } catch (FacilitatorException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFeePayerFromSupported(): void
    {
        $kinds = ['kinds' => [['scheme' => 'exact', 'network' => 'solana:devnet', 'extra' => ['feePayer' => 'CKPK']]]];
        $this->assertSame('CKPK', $this->http([$this->json(200, $kinds)])->feePayer('solana:devnet'));
        $this->assertNull($this->http([$this->json(200, $kinds)])->feePayer(self::NETWORK));
    }

    public function testGenericVerifyAddsNoCircleHeaders(): void
    {
        $this->assertTrue($this->http([$this->json(200, ['isValid' => true])])->verify($this->payment(), $this->req())['isValid']);
        $this->assertSame('https://x402.example/facilitator/verify', (string) $this->sent[0]->getUri());
        $this->assertFalse($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
        $this->assertFalse($this->sent[0]->hasHeader('Authorization'));
    }

    public function testOnlyCircleHasAStatusRoute(): void
    {
        $this->assertInstanceOf(PaymentStatus::class, $this->circle([]));
        $this->assertNotInstanceOf(PaymentStatus::class, $this->http([]));
    }

    private function statusQueries(): int
    {
        return count(array_filter($this->sent, fn (RequestInterface $r) => str_contains((string) $r->getUri(), '/status/')));
    }

    public function testTheFirstStatusQueryGoesOutBeforeAnyDelay(): void
    {
        // /status answers 404: the first query fails and polling stops. The delay is 60 s, so a sleep before that
        // first query would make this test take a minute and fail on the elapsed time. Nothing sleeps: the query is first.
        $f = new Psr17Factory();
        $pending = fn () => $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-3']]]);
        $facilitators = [
            'http' => fn ($client) => new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', 'KEY', null, 60, 5),
            'circle' => fn ($client) => new CircleFacilitator($client, $f, $f, 'KEY', null, 60, 5),
        ];
        foreach ($facilitators as $name => $build) {
            $this->sent = [];
            $responses = [$pending(), $this->json(404, ['message' => 'not found'])];
            $client = $this->client(function () use (&$responses) {
                return array_shift($responses);
            });
            $started = microtime(true);
            $result = $build($client)->settle($this->payment(), $this->req());

            $this->assertLessThan(10.0, microtime(true) - $started, $name);
            $this->assertSame(['settlement_pending', 'pay-3'], [$result['errorReason'], $result['paymentId']], $name);
            $this->assertCount(2, $this->sent, $name);
            $this->assertSame(1, $this->statusQueries(), $name);
        }
    }

    public function testPersistentlyPendingSettleQueriesExactlyPollAttempts(): void
    {
        $pending = $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-4']]]);
        $f = new Psr17Factory();
        $responses = [$pending, ...array_fill(0, 3, $this->json(200, ['status' => 'pending']))];
        $client = $this->client(function () use (&$responses) {
            return array_shift($responses);
        });
        $result = (new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', 'KEY', null, 0, 3))->settle($this->payment(), $this->req());

        $this->assertSame(['settlement_pending', 'pay-4', false], [$result['errorReason'], $result['paymentId'], $result['success']]);
        $this->assertSame(3, $this->statusQueries());
        $this->assertCount(4, $this->sent);
    }

    public function testCompletedOnTheSecondQueryIsSuccess(): void
    {
        $result = $this->circle([
            $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-5']]]),
            $this->json(200, ['status' => 'pending']),
            $this->json(200, ['status' => 'completed', 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => self::BUYER]),
        ], 'KEY')->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        $this->assertSame(self::TX, $result['transaction']);
        $this->assertSame(2, $this->statusQueries());
    }

    /**
     * Runs $build with the deprecations silenced, and returns its result.
     */
    private function quietly(\Closure $build): mixed
    {
        set_error_handler(static fn (): bool => true, \E_USER_DEPRECATED);
        try {
            return $build();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The [level, message] of each E_USER_DEPRECATED raised by $build.
     *
     * @return list<array{int, string}>
     */
    private function raised(\Closure $build): array
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = [$level, $message];

            return true;
        }, \E_USER_DEPRECATED);
        try {
            $build();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }

    private const SELLER_KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';

    public function testTheV021SellerCallStillSignsAndPolls(): void
    {
        $responses = [
            $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-1']]]),
            $this->json(200, ['status' => 'completed', 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => self::BUYER]),
        ];
        $f = new Psr17Factory();
        $client = $this->client(function () use (&$responses) {
            return array_shift($responses);
        });
        $facilitator = $this->quietly(fn () => new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', null, new Signer(self::SELLER_KEY), 0, 3));

        $result = $facilitator->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        $this->assertSame(self::TX, $result['transaction']);
        $this->assertSame('https://x402.example/facilitator/settle', (string) $this->sent[0]->getUri());
        $this->assertTrue($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
        $this->assertSame('https://x402.example/facilitator/status/pay-1', (string) $this->sent[1]->getUri());
        $this->assertTrue($this->sent[1]->hasHeader('Facilitator-Seller-Proof'));
        $this->assertFalse($this->sent[1]->hasHeader('Authorization'));
    }

    public function testBearerOnlyPendingSettleIsPolledAsV021(): void
    {
        $responses = [
            $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-2']]]),
            $this->json(200, ['status' => 'completed', 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => self::BUYER]),
        ];
        $f = new Psr17Factory();
        $client = $this->client(function () use (&$responses) {
            return array_shift($responses);
        });
        // v0.2.1 polled whenever a settle came back pending with a paymentId, bearer or not: parity, DECISIONS.md D15.
        $facilitator = new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', 'KEY', null, 0, 3);

        $result = $facilitator->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        $this->assertSame(self::TX, $result['transaction']);
        $this->assertSame('https://x402.example/facilitator/status/pay-2', (string) $this->sent[1]->getUri());
        $this->assertSame('Bearer KEY', $this->sent[1]->getHeaderLine('Authorization'));
    }

    public function testAHeaderRefusalOnSettleFreesTheClaimInsteadOfThrowing(): void
    {
        $f = new Psr17Factory();
        $client = $this->client(fn () => $this->json(200, ['success' => true]));
        $refuse = function (): array {
            throw new \InvalidArgumentException('no proof for this body');
        };
        $facilitator = new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', null, null, 0, 3, $refuse);

        $result = $facilitator->settle($this->payment(), $this->req());

        $this->assertFalse($result['success']);
        $this->assertSame('seller_proof_failed', $result['errorReason']);
        $this->assertSame('', $result['transaction']);
        $this->assertCount(0, $this->sent, 'the refusal happens before anything is sent');
    }

    public function testTheSellerTriggersADeprecation(): void
    {
        $f = new Psr17Factory();
        $client = $this->client(fn () => $this->json(200, ['isValid' => true]));
        $raised = $this->raised(fn () => new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', null, new Signer(self::SELLER_KEY)));

        $this->assertCount(1, $raised);
        $this->assertSame(\E_USER_DEPRECATED, $raised[0][0]);
        $this->assertStringContainsString('CircleFacilitator', $raised[0][1]);
    }

    public function testWithoutTheSellerThereIsNoDeprecation(): void
    {
        $f = new Psr17Factory();
        $client = $this->client(fn () => $this->json(200, ['isValid' => true]));
        $this->assertSame([], $this->raised(fn () => new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', 'KEY')));
    }

    public function testTheSellerAsANamedArgument(): void
    {
        $responses = [$this->json(200, ['isValid' => true])];
        $f = new Psr17Factory();
        $client = $this->client(function () use (&$responses) {
            return array_shift($responses);
        });
        $facilitator = $this->quietly(fn () => new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', seller: new Signer(self::SELLER_KEY), pollDelaySeconds: 0));

        $this->assertTrue($facilitator->verify($this->payment(), $this->req())['isValid']);
        $this->assertTrue($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
    }

    public function testTheHeadersHookAsANamedArgument(): void
    {
        $responses = [$this->json(200, ['isValid' => true])];
        $f = new Psr17Factory();
        $client = $this->client(function () use (&$responses) {
            return array_shift($responses);
        });
        $facilitator = new HttpFacilitator($client, $f, $f, 'https://x402.example/facilitator', headers: static fn (string $purpose): array => ['X-Purpose' => $purpose]);

        $facilitator->verify($this->payment(), $this->req());
        $this->assertSame('verify', $this->sent[0]->getHeaderLine('X-Purpose'));
        $this->assertFalse($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
    }
}
