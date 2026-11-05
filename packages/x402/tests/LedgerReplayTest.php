<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileLedger;
use Quebracho\X402\LedgerUnavailableException;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PaymentLedger;
use Quebracho\X402\Sale;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * A payment's stored outcome: a retry replays it, a pending one is confirmed by the lookup or answers in progress.
 */
final class LedgerReplayTest extends TestCase
{
    use TempClaims;

    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const BUYER = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    private int $runs = 0;
    private PaymentLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new FileLedger($this->claimsDir());
    }

    /** The middlewares' $claim is unused with a ledger; this refuses if it ever is. */
    private function unusedClaim(): \Closure
    {
        return static fn (string $key): bool => throw new \LogicException('the ledger claims, not the closure');
    }

    private function settledLater(string $transaction = '0xabc'): \Closure
    {
        return static fn (array $payment, array $req): array => ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => $transaction, 'network' => $req['network']];
    }

    private function http(?\Closure $settle = null, ?\Closure $lookup = null, ?PaymentLedger $ledger = null, bool $upfront = false, ?\Closure $sensitive = null): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402Middleware([new EvmExact()], static fn (ServerRequestInterface $r) => [$req], $this->unusedClaim(), $f, $f, $settle ?? Settlement::mock(), ledger: $ledger ?? $this->ledger, lookup: $lookup, sensitive: $sensitive);
    }

    private function mcp(?\Closure $settle = null, ?\Closure $lookup = null, ?PaymentLedger $ledger = null, bool $upfront = false, ?\Closure $sensitive = null): X402McpMiddleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402McpMiddleware([new EvmExact()], static fn (array $params) => 'wisdom' === $params['name'] ? [$req] : null, $this->unusedClaim(), $f, $f, $settle ?? Settlement::mock(), ledger: $ledger ?? $this->ledger, lookup: $lookup, sensitive: $sensitive);
    }

    /** The real ledger, recording every state it is asked to put. */
    private function spyLedger(): PaymentLedger
    {
        return new class($this->ledger) implements PaymentLedger {
            /** @var list<array<string, mixed>> */
            public array $puts = [];

            public function __construct(private readonly PaymentLedger $real)
            {
            }

            public function claim(string $key, int $ttl): bool
            {
                return $this->real->claim($key, $ttl);
            }

            public function get(string $key): ?array
            {
                return $this->real->get($key);
            }

            public function put(string $key, array $state, int $ttl): void
            {
                $this->puts[] = $state;
                $this->real->put($key, $state, $ttl);
            }

            public function release(string $key): void
            {
                $this->real->release($key);
            }
        };
    }

    /** The real ledger, except that each operation named in $down throws LedgerUnavailableException (a store that is down). */
    private function downLedger(string ...$down): PaymentLedger
    {
        return new class($this->ledger, $down) implements PaymentLedger {
            /** @param string[] $down */
            public function __construct(private readonly PaymentLedger $real, private readonly array $down)
            {
            }

            private function check(string $op): void
            {
                if (\in_array($op, $this->down, true)) {
                    throw new LedgerUnavailableException("$op: the store is down");
                }
            }

            public function claim(string $key, int $ttl): bool
            {
                $this->check('claim');

                return $this->real->claim($key, $ttl);
            }

            public function get(string $key): ?array
            {
                $this->check('get');

                return $this->real->get($key);
            }

            public function put(string $key, array $state, int $ttl): void
            {
                $this->check('put');
                $this->real->put($key, $state, $ttl);
            }

            public function release(string $key): void
            {
                $this->check('release');
                $this->real->release($key);
            }
        };
    }

    /**
     * A ledger whose puts after the first $okPuts fail with $error, every other operation working. The
     * middlewares write an intention before a settle, so the store fails on the write that records the outcome.
     */
    private function brokenPutLedger(\Throwable $error, int $okPuts = 0): PaymentLedger
    {
        return new class($this->ledger, $error, $okPuts) implements PaymentLedger {
            private int $puts = 0;

            public function __construct(private readonly PaymentLedger $real, private readonly \Throwable $error, private readonly int $okPuts)
            {
            }

            public function claim(string $key, int $ttl): bool
            {
                return $this->real->claim($key, $ttl);
            }

            public function get(string $key): ?array
            {
                return $this->real->get($key);
            }

            public function put(string $key, array $state, int $ttl): void
            {
                if (++$this->puts > $this->okPuts) {
                    throw $this->error;
                }
                $this->real->put($key, $state, $ttl);
            }

            public function release(string $key): void
            {
                $this->real->release($key);
            }
        };
    }

    /** A handler that counts its runs and answers $status with $body. */
    private function handler(string $body = 'paid content', int $status = 200): RequestHandlerInterface
    {
        return new class($this->runs, $status, $body) implements RequestHandlerInterface {
            public function __construct(private int &$runs, private int $status, private string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return new Response($this->status, [], $this->body);
            }
        };
    }

    /** The JSON-RPC answer of a tool that returns $text (id 2, as the handler names it). */
    private function toolHandler(string $text = 'paid content'): RequestHandlerInterface
    {
        $json = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);

        return new class($this->runs, $json) implements RequestHandlerInterface {
            public function __construct(private int &$runs, private string $json)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
    }

    /** The payment a buyer signs for the first 402 of this HTTP endpoint. */
    private function httpPayment(X402Middleware $middleware): array
    {
        $first = $middleware->process(new ServerRequest('POST', 'https://example.test/paid'), $this->handler());
        $required = PaymentHeaders::decode($first->getHeaderLine(PaymentHeaders::REQUIRED));

        return (new EvmExact(new Signer(self::BUYER)))->pay($required, $required['accepts'][0]);
    }

    private function httpCall(array $payment): ServerRequest
    {
        return new ServerRequest('POST', 'https://example.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]);
    }

    private static function mcpCall(int $id, ?array $payment = null): ServerRequest
    {
        $params = ['name' => 'wisdom', 'arguments' => []];
        if (null !== $payment) {
            $params['_meta'][X402McpMiddleware::PAYMENT] = $payment;
        }

        return new ServerRequest('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params]));
    }

    /** The payment a buyer signs for the first in-band 402 of this MCP endpoint. */
    private function mcpPayment(X402McpMiddleware $middleware): array
    {
        $required = self::answer($middleware->process(self::mcpCall(2), $this->toolHandler()))['result']['structuredContent'];

        return (new EvmExact(new Signer(self::BUYER)))->pay($required, $required['accepts'][0]);
    }

    private static function answer(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testRetryReplaysTheStoredResponseWithoutRunningTheHandler(): void
    {
        $middleware = $this->http();
        $payment = $this->httpPayment($middleware);

        self::assertSame(200, $middleware->process($this->httpCall($payment), $this->handler())->getStatusCode());
        $again = $middleware->process($this->httpCall($payment), $this->handler());

        self::assertSame(200, $again->getStatusCode());
        self::assertSame('paid content', (string) $again->getBody());
        self::assertTrue(PaymentHeaders::decode($again->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
        self::assertSame(1, $this->runs);
    }

    public function testAForgedSignatureGetsNoStoredResponse(): void
    {
        $middleware = $this->http();
        $payment = $this->httpPayment($middleware);
        $middleware->process($this->httpCall($payment), $this->handler());

        $payment['payload']['signature'] = substr($payment['payload']['signature'], 0, -2).('00' === substr($payment['payload']['signature'], -2) ? '01' : '00');
        self::assertSame(402, $middleware->process($this->httpCall($payment), $this->handler())->getStatusCode());
        self::assertSame(1, $this->runs);
    }

    public function testAPendingPaymentConfirmedByTheLookupGetsTheStoredResponse(): void
    {
        $payment = $this->httpPayment($this->http());
        $pending = $this->http($this->settledLater());
        self::assertSame(402, $pending->process($this->httpCall($payment), $this->handler())->getStatusCode());

        $lookup = static fn (array $state): ?array => ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532'];
        $response = $this->http($this->settledLater(), $lookup)->process($this->httpCall($payment), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertSame(1, $this->runs);
    }

    public function testAPendingPaymentNotConfirmedIsAConflict(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->http($this->settledLater())->process($this->httpCall($payment), $this->handler());

        foreach ([null, static fn (array $state): ?array => null] as $lookup) {
            $response = $this->http($this->settledLater(), $lookup)->process($this->httpCall($payment), $this->handler());
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('settlement_pending', json_decode((string) $response->getBody(), true)['error']);
        }
        self::assertSame(1, $this->runs);
    }

    public function testAPaymentRefutedByTheLookupIsFreedAndSettlesAgain(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->http($this->settledLater())->process($this->httpCall($payment), $this->handler());

        // Refuted: freed, so the payment is admitted again and the handler runs again.
        $refuted = static fn (array $state): ?array => ['success' => false, 'errorReason' => 'insufficient_funds'];
        $response = $this->http($this->settledLater(), $refuted)->process($this->httpCall($payment), $this->handler());
        self::assertSame(402, $response->getStatusCode());
        self::assertSame(2, $this->runs);
    }

    public function testAResponseOverOneMebibyteIsNotStored(): void
    {
        $big = $this->handler(str_repeat('x', Sale::MAX_BODY + 1));
        $middleware = $this->http();
        $payment = $this->httpPayment($middleware);

        self::assertSame(200, $middleware->process($this->httpCall($payment), $big)->getStatusCode());
        $again = $middleware->process($this->httpCall($payment), $this->handler());

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('already_settled', json_decode((string) $again->getBody(), true)['error']);
        self::assertSame(1, $this->runs);
    }

    public function testAPaymentInProgressIsAConflict(): void
    {
        $middleware = $this->http();
        $payment = $this->httpPayment($middleware);
        $this->ledger->claim(Settlement::key($payment), 600);

        $response = $middleware->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('in_progress', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(0, $this->runs);
    }

    public function testMcpRetryReplaysTheStoredAnswerWithTheNewCallId(): void
    {
        $middleware = $this->mcp();
        $payment = $this->mcpPayment($middleware);

        $paid = self::answer($middleware->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $paid['result']['content'][0]['text']);

        $again = self::answer($middleware->process(self::mcpCall(9, $payment), $this->toolHandler()));
        self::assertSame(9, $again['id']);
        self::assertSame('paid content', $again['result']['content'][0]['text']);
        self::assertTrue($again['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame(1, $this->runs);
    }

    public function testMcpPendingConfirmedByTheLookupReturnsTheAnswer(): void
    {
        $payment = $this->mcpPayment($this->mcp());
        $pending = self::answer($this->mcp($this->settledLater())->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertTrue($pending['result']['isError']);
        self::assertSame('settlement_pending', $pending['result']['structuredContent']['error']);

        $lookup = static fn (array $state): ?array => ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532'];
        $answer = self::answer($this->mcp($this->settledLater(), $lookup)->process(self::mcpCall(3, $payment), $this->toolHandler()));
        self::assertSame(3, $answer['id']);
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(1, $this->runs);
    }

    public function testMcpPaymentInProgressIsAnInBandRefusal(): void
    {
        $middleware = $this->mcp();
        $payment = $this->mcpPayment($middleware);
        $this->ledger->claim(Settlement::key($payment), 600);

        $answer = self::answer($middleware->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertTrue($answer['result']['isError']);
        self::assertSame('in_progress', $answer['result']['structuredContent']['error']);
        self::assertSame(0, $this->runs);
    }

    public function testHttpLedgerDownBeforeTheHandlerIsA503AndNothingRuns(): void
    {
        $payment = $this->httpPayment($this->http());

        foreach (['claim', 'get'] as $down) {
            $response = $this->http(ledger: $this->downLedger($down))->process($this->httpCall($payment), $this->handler());
            self::assertSame(503, $response->getStatusCode(), "$down down");
            self::assertSame('ledger_unavailable', json_decode((string) $response->getBody(), true)['error']);
        }
        self::assertSame(0, $this->runs);
    }

    public function testMcpLedgerDownBeforeTheToolIsAnInBandErrorAndNothingRuns(): void
    {
        $payment = $this->mcpPayment($this->mcp());

        foreach (['claim', 'get'] as $down) {
            $answer = self::answer($this->mcp(ledger: $this->downLedger($down))->process(self::mcpCall(2, $payment), $this->toolHandler()));
            self::assertTrue($answer['result']['isError'], "$down down");
            self::assertSame('ledger_unavailable', $answer['result']['structuredContent']['error']);
        }
        self::assertSame(0, $this->runs);
    }

    public function testHttpAnswerIsKeptWhenTheStoreFailsAfterASettle(): void
    {
        $payment = $this->httpPayment($this->http());

        $response = $this->http(ledger: $this->brokenPutLedger(new LedgerUnavailableException('put: the store is down'), 1))->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
        self::assertSame(1, $this->runs);
    }

    public function testMcpAnswerIsKeptWhenTheStoreFailsAfterASettle(): void
    {
        $payment = $this->mcpPayment($this->mcp());

        $answer = self::answer($this->mcp(ledger: $this->brokenPutLedger(new LedgerUnavailableException('put: the store is down'), 1))->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertArrayNotHasKey('isError', $answer['result']);
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertTrue($answer['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame(1, $this->runs);
    }

    /** A settle that must not run: a retry of a charged payment never settles it again. */
    private function noSecondSettle(): \Closure
    {
        return static fn (array $payment, array $req): array => throw new \LogicException('settled twice');
    }

    /** The lookup confirms the pending settle. */
    private function confirmed(): \Closure
    {
        return static fn (array $state): ?array => ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532'];
    }

    public function testUpfrontPendingSettleConfirmedByTheLookupRunsTheHandlerOnRetry(): void
    {
        $payment = $this->httpPayment($this->http(upfront: true));

        self::assertSame(402, $this->http($this->settledLater(), upfront: true)->process($this->httpCall($payment), $this->handler())->getStatusCode());
        self::assertSame(0, $this->runs);

        $response = $this->http($this->noSecondSettle(), $this->confirmed(), upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
        self::assertSame(1, $this->runs);

        // Delivered: a further retry replays it, the handler not run again.
        self::assertSame(200, $this->http($this->noSecondSettle(), upfront: true)->process($this->httpCall($payment), $this->handler())->getStatusCode());
        self::assertSame(1, $this->runs);
    }

    public function testUpfrontHandlerFailingAfterAConfirmedSettleStaysRecoverable(): void
    {
        $payment = $this->httpPayment($this->http(upfront: true));
        $this->http($this->settledLater(), upfront: true)->process($this->httpCall($payment), $this->handler());

        // Charged once; the handler fails: that answer goes out, and the state says the handler did not deliver.
        $failed = $this->http($this->noSecondSettle(), $this->confirmed(), upfront: true)->process($this->httpCall($payment), $this->handler('boom', 500));
        self::assertSame(500, $failed->getStatusCode());
        self::assertSame(1, $this->runs);

        // A retry runs the handler again, with no second settle, and that delivery is kept.
        $retry = $this->http($this->noSecondSettle(), upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $retry->getStatusCode());
        self::assertSame('paid content', (string) $retry->getBody());
        self::assertSame(2, $this->runs);
    }

    public function testMcpUpfrontPendingSettleConfirmedByTheLookupRunsTheToolOnRetry(): void
    {
        $payment = $this->mcpPayment($this->mcp(upfront: true));
        $pending = self::answer($this->mcp($this->settledLater(), upfront: true)->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('settlement_pending', $pending['result']['structuredContent']['error']);
        self::assertSame(0, $this->runs);

        $answer = self::answer($this->mcp($this->noSecondSettle(), $this->confirmed(), upfront: true)->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertTrue($answer['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame(1, $this->runs);
    }

    public function testHttpAnswerIsKeptWhenTheRealFileLedgerFailsToWriteAfterASettle(): void
    {
        $dir = $this->claimsDir();
        $payment = $this->httpPayment($this->http());
        // The store goes away after the claim and before the write: its directory is replaced by a file.
        $settle = static function (array $payment, array $req) use ($dir): array {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
                unlink("$dir/$file");
            }
            rmdir($dir);
            file_put_contents($dir, 'not a directory');

            return Settlement::mock()($payment, $req);
        };
        try {
            $response = $this->http($settle, ledger: new FileLedger($dir))->process($this->httpCall($payment), $this->handler());

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('paid content', (string) $response->getBody());
            self::assertSame(1, $this->runs);
        } finally {
            @unlink($dir);
        }
    }

    public function testHttpAnswerIsKeptWhenTheStoreFailsWithAnyErrorAfterASettle(): void
    {
        $payment = $this->httpPayment($this->http());

        $response = $this->http(ledger: $this->brokenPutLedger(new \RuntimeException('disk gone'), 1))->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertSame(1, $this->runs);
    }

    public function testMcpAnswerIsKeptWhenTheStoreFailsWithAnyErrorAfterASettle(): void
    {
        $payment = $this->mcpPayment($this->mcp());

        $answer = self::answer($this->mcp(ledger: $this->brokenPutLedger(new \RuntimeException('disk gone'), 1))->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertArrayNotHasKey('isError', $answer['result']);
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(1, $this->runs);
    }

    /** A settle that counts its calls and moves nothing (mock mode). */
    private function countedSettle(int &$calls): \Closure
    {
        return static function (array $payment, array $req) use (&$calls): array {
            ++$calls;

            return Settlement::mock()($payment, $req);
        };
    }

    /** Writes a state as a crash would leave it, for a payment whose settle outcome was lost. */
    private function seedSettling(array $payment, array $state): void
    {
        $this->ledger->put(Settlement::key($payment), ['status' => 'settling'] + $state, 600);
    }

    /** A settle outcome with a reference the facilitator gave, not yet confirmed. */
    private const REFERENCE = ['receipt' => ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0xabc', 'network' => 'eip155:84532']];

    public function testHttpCrashAfterASettleSentWithoutAReferenceIsUnknownAndNeverRunsAgain(): void
    {
        $payment = $this->httpPayment($this->http());
        $calls = 0;
        // The settle went out, then the store failed on the write that records its outcome: the state stays 'settling'.
        $response = $this->http($this->countedSettle($calls), ledger: $this->brokenPutLedger(new \RuntimeException('crash'), 1))->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $calls);

        $retry = $this->http($this->noSecondSettle())->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $retry->getStatusCode());
        self::assertSame('settlement_unknown', json_decode((string) $retry->getBody(), true)['error']);
        self::assertSame(1, $this->runs);
        self::assertSame(1, $calls);
    }

    public function testMcpCrashAfterASettleSentWithoutAReferenceIsUnknownAndNeverRunsAgain(): void
    {
        $payment = $this->mcpPayment($this->mcp());
        $calls = 0;
        $answer = self::answer($this->mcp($this->countedSettle($calls), ledger: $this->brokenPutLedger(new \RuntimeException('crash'), 1))->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(1, $calls);

        $retry = self::answer($this->mcp($this->noSecondSettle())->process(self::mcpCall(3, $payment), $this->toolHandler()));
        self::assertTrue($retry['result']['isError']);
        self::assertSame('settlement_unknown', $retry['result']['structuredContent']['error']);
        self::assertSame(1, $this->runs);
        self::assertSame(1, $calls);
    }

    public function testHttpSettlingWithAReferenceUnconfirmedIsUnknownAndNeverRuns(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->seedSettling($payment, self::REFERENCE);

        $response = $this->http($this->noSecondSettle())->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('settlement_unknown', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(0, $this->runs);
    }

    public function testHttpSettlingWithAReferenceConfirmedByTheLookupIsSettledAndAnUpfrontHandlerRunsOnce(): void
    {
        $payment = $this->httpPayment($this->http(upfront: true));
        $this->seedSettling($payment, self::REFERENCE);

        $response = $this->http($this->noSecondSettle(), $this->confirmed(), upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertSame(1, $this->runs);
    }

    public function testHttpSettlingWithAReferenceConfirmedByTheLookupAnswersAlreadySettledWhenTheDefaultFlowRan(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->seedSettling($payment, self::REFERENCE);

        // Default flow: the handler ran before the settle, so a confirmed settle without a kept response is not run again.
        $response = $this->http($this->noSecondSettle(), $this->confirmed())->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_settled', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(0, $this->runs);
    }

    public function testMcpSettlingWithAReferenceConfirmedByTheLookupRunsTheToolOnceUpfront(): void
    {
        $payment = $this->mcpPayment($this->mcp(upfront: true));
        $this->seedSettling($payment, self::REFERENCE);

        $answer = self::answer($this->mcp($this->noSecondSettle(), $this->confirmed(), upfront: true)->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertTrue($answer['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame(1, $this->runs);
    }

    public function testUpfrontCrashInsideTheHandlerIsNeedsReviewAndTheHandlerNeverRunsAgain(): void
    {
        $payment = $this->httpPayment($this->http(upfront: true));
        // Settle, then the handler's intention, then the handler ran; the store failed on the write that records its answer.
        $response = $this->http(ledger: $this->brokenPutLedger(new \RuntimeException('crash'), 3), upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->runs);

        $retry = $this->http($this->noSecondSettle(), upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $retry->getStatusCode());
        self::assertSame('needs_review', json_decode((string) $retry->getBody(), true)['error']);
        self::assertSame(1, $this->runs);
    }

    public function testMcpUpfrontCrashInsideTheToolIsNeedsReviewAndTheToolNeverRunsAgain(): void
    {
        $payment = $this->mcpPayment($this->mcp(upfront: true));
        $answer = self::answer($this->mcp(ledger: $this->brokenPutLedger(new \RuntimeException('crash'), 3), upfront: true)->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(1, $this->runs);

        $retry = self::answer($this->mcp($this->noSecondSettle(), upfront: true)->process(self::mcpCall(3, $payment), $this->toolHandler()));
        self::assertTrue($retry['result']['isError']);
        self::assertSame('needs_review', $retry['result']['structuredContent']['error']);
        self::assertSame(1, $this->runs);
    }

    /** @return iterable<string, array{\Closure, bool}> */
    public static function sensitiveFlows(): iterable
    {
        foreach ([false, true] as $upfront) {
            $flow = $upfront ? 'upfront' : 'default';
            yield "$flow, marked" => [static fn (ServerRequestInterface|array $r): bool => true, $upfront];
            // Fail-closed: a check that throws keeps nothing either.
            yield "$flow, check throws" => [static fn (ServerRequestInterface|array $r): bool => throw new \RuntimeException('down'), $upfront];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveFlows')]
    public function testASensitivePaymentIsDeliveredButItsBodyIsNeverStored(\Closure $sensitive, bool $upfront): void
    {
        $ledger = $this->spyLedger();
        $middleware = $this->http(ledger: $ledger, upfront: $upfront, sensitive: $sensitive);
        $payment = $this->httpPayment($middleware);

        $response = $middleware->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertNotEmpty($ledger->puts);
        foreach ($ledger->puts as $state) {
            self::assertArrayNotHasKey('response', $state);
        }

        // The retry neither replays the body nor runs the handler again.
        $again = $middleware->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('already_settled', json_decode((string) $again->getBody(), true)['error']);
        self::assertStringNotContainsString('paid content', (string) $again->getBody());
        self::assertSame(1, $this->runs);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveFlows')]
    public function testMcpSensitiveCallIsDeliveredButItsAnswerIsNeverStored(\Closure $sensitive, bool $upfront): void
    {
        $ledger = $this->spyLedger();
        $seen = [];
        $spy = static function (array $params) use ($sensitive, &$seen): bool {
            $seen[] = $params['name'];

            return $sensitive($params);
        };
        $middleware = $this->mcp(ledger: $ledger, upfront: $upfront, sensitive: $spy);
        $payment = $this->mcpPayment($middleware);
        $seen = [];

        $answer = self::answer($middleware->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(['wisdom'], $seen, 'called once per paid call');
        foreach ($ledger->puts as $state) {
            self::assertArrayNotHasKey('response', $state);
        }

        $retry = self::answer($middleware->process(self::mcpCall(3, $payment), $this->toolHandler()));
        self::assertTrue($retry['result']['isError']);
        self::assertSame('already_settled', $retry['result']['structuredContent']['error']);
        self::assertSame(1, $this->runs);
    }

    public function testNotSensitiveStillStoresTheBody(): void
    {
        $ledger = $this->spyLedger();
        $middleware = $this->http(ledger: $ledger, sensitive: static fn (ServerRequestInterface $r): bool => false);
        $middleware->process($this->httpCall($this->httpPayment($middleware)), $this->handler());

        self::assertArrayHasKey('response', end($ledger->puts));
    }

    /**
     * A ledger in memory. $beforeHandling runs once, the first time a 'handling' state is put, before it is
     * stored: a concurrent retry slipped in between a retry's read and its write.
     */
    private function memoryLedger(): PaymentLedger
    {
        return new class implements PaymentLedger {
            /** @var array<string, array<string, mixed>> */
            public array $states = [];
            /** @var list<string> */
            public array $claims = [];
            public ?\Closure $beforeHandling = null;

            public function claim(string $key, int $ttl): bool
            {
                $this->claims[] = $key;
                if (isset($this->states[$key])) {
                    return false;
                }
                $this->states[$key] = ['status' => 'claimed'];

                return true;
            }

            public function get(string $key): ?array
            {
                return $this->states[$key] ?? null;
            }

            public function put(string $key, array $state, int $ttl): void
            {
                json_encode($state, JSON_THROW_ON_ERROR);
                if ('handling' === ($state['status'] ?? null) && null !== ($hook = $this->beforeHandling)) {
                    $this->beforeHandling = null;
                    $hook();
                }
                $this->states[$key] = $state;
            }

            public function release(string $key): void
            {
                unset($this->states[$key]);
            }
        };
    }

    /** A handler that counts its runs and throws. */
    private function throwingHandler(): RequestHandlerInterface
    {
        return new class($this->runs) implements RequestHandlerInterface {
            public function __construct(private int &$runs)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;
                throw new \RuntimeException('boom');
            }
        };
    }

    /** Failed before the run claim (reproduced by review): both retries read 'run' and both ran the handler. */
    public function testConcurrentUpfrontRetriesRunTheHandlerOnce(): void
    {
        $ledger = $this->memoryLedger();
        $payment = $this->httpPayment($this->http(ledger: $ledger, upfront: true));
        self::assertSame(500, $this->http(ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler('boom', 500))->getStatusCode());
        self::assertSame(1, $this->runs);

        $second = null;
        $ledger->beforeHandling = function () use (&$second, $ledger, $payment): void {
            // The first retry read 'run' and holds the run claim; its 'handling' is not stored yet.
            $second = $this->http($this->noSecondSettle(), ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler());
        };
        $first = $this->http($this->noSecondSettle(), ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertInstanceOf(ResponseInterface::class, $second);
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('in_progress', json_decode((string) $second->getBody(), true)['error']);
        self::assertSame(2, $this->runs, 'the failed first attempt, then one delivery');
        self::assertNull($ledger->get(Sale::RUN.Settlement::key($payment)));
    }

    /** Failed before the run claim, as the HTTP one. */
    public function testMcpConcurrentUpfrontRetriesRunTheToolOnce(): void
    {
        $ledger = $this->memoryLedger();
        $payment = $this->mcpPayment($this->mcp(ledger: $ledger, upfront: true));
        $this->mcp(ledger: $ledger, upfront: true)->process(self::mcpCall(2, $payment), $this->handler('boom', 500));
        self::assertSame(1, $this->runs);

        $second = null;
        $ledger->beforeHandling = function () use (&$second, $ledger, $payment): void {
            $second = self::answer($this->mcp($this->noSecondSettle(), ledger: $ledger, upfront: true)->process(self::mcpCall(3, $payment), $this->toolHandler()));
        };
        $first = self::answer($this->mcp($this->noSecondSettle(), ledger: $ledger, upfront: true)->process(self::mcpCall(2, $payment), $this->toolHandler()));

        self::assertSame('paid content', $first['result']['content'][0]['text']);
        self::assertIsArray($second);
        self::assertTrue($second['result']['isError']);
        self::assertSame('in_progress', $second['result']['structuredContent']['error']);
        self::assertSame(2, $this->runs);
    }

    /** A retry that read 'run' before another finished takes the freed claim, sees 'delivered' and replays. */
    public function testARetryThatReadRunTooEarlyReplaysInsteadOfRunningAgain(): void
    {
        $ledger = $this->memoryLedger();
        $payment = $this->httpPayment($this->http(ledger: $ledger, upfront: true));
        $this->http(ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler('boom', 500));
        $key = Settlement::key($payment);
        $stale = $ledger->get($key);

        self::assertSame(200, $this->http($this->noSecondSettle(), ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler())->getStatusCode());
        // Its first read is the stale state; under the claim it reads the delivery.
        $reads = 0;
        $late = new class($ledger, $stale, $reads) implements PaymentLedger {
            public function __construct(private readonly PaymentLedger $real, private readonly ?array $stale, private int &$reads)
            {
            }

            public function claim(string $key, int $ttl): bool
            {
                return $this->real->claim($key, $ttl);
            }

            public function get(string $key): ?array
            {
                return 0 === $this->reads++ ? $this->stale : $this->real->get($key);
            }

            public function put(string $key, array $state, int $ttl): void
            {
                $this->real->put($key, $state, $ttl);
            }

            public function release(string $key): void
            {
                $this->real->release($key);
            }
        };
        $replay = $this->http($this->noSecondSettle(), ledger: $late, upfront: true)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $replay->getStatusCode());
        self::assertSame(2, $this->runs);
        self::assertNull($ledger->get(Sale::RUN.$key));
    }

    public function testTheRunClaimIsFreedAfterAFailureAnExceptionAndASuccess(): void
    {
        $ledger = $this->memoryLedger();
        $payment = $this->httpPayment($this->http(ledger: $ledger, upfront: true));
        $run = Sale::RUN.Settlement::key($payment);
        $retry = fn (RequestHandlerInterface $h): ResponseInterface => $this->http($this->noSecondSettle(), ledger: $ledger, upfront: true)->process($this->httpCall($payment), $h);
        $this->http(ledger: $ledger, upfront: true)->process($this->httpCall($payment), $this->handler('boom', 500));

        self::assertSame(500, $retry($this->handler('boom', 500))->getStatusCode());
        self::assertNull($ledger->get($run), 'freed after a non-2xx');
        try {
            $retry($this->throwingHandler());
            self::fail('the handler threw');
        } catch (\RuntimeException) {
        }
        self::assertNull($ledger->get($run), 'freed after an exception');
        self::assertFalse($ledger->get(Settlement::key($payment))['handler_ran'], 'still to deliver');

        self::assertSame(200, $retry($this->handler())->getStatusCode());
        self::assertSame(4, $this->runs);
        self::assertNull($ledger->get($run), 'freed after a delivery');
        self::assertSame('delivered', $ledger->get(Settlement::key($payment))['status']);
    }

    public function testOnlyAnUpfrontRetryTakesARunClaim(): void
    {
        foreach ([false, true] as $upfront) {
            $ledger = $this->memoryLedger();
            $middleware = $this->http(ledger: $ledger, upfront: $upfront);
            $payment = $this->httpPayment($middleware);
            self::assertSame(200, $middleware->process($this->httpCall($payment), $this->handler())->getStatusCode());
            self::assertSame(200, $middleware->process($this->httpCall($payment), $this->handler())->getStatusCode());
            self::assertSame([Settlement::key($payment)], $ledger->claims, $upfront ? 'upfront' : 'default');
        }
        self::assertSame(2, $this->runs);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function foreignReceipts(): iterable
    {
        $ok = ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532'];
        yield 'another transaction' => [['transaction' => '0xdef'] + $ok];
        yield 'another network' => [['network' => 'eip155:8453'] + $ok];
        yield 'another payer' => [$ok + ['payer' => '0x0000000000000000000000000000000000000001']];
        yield 'no network' => [array_diff_key($ok, ['network' => 0])];
    }

    /** Failed before the comparison: any success:true confirmed the payment. */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignReceipts')]
    public function testALookupSuccessAboutAnotherPaymentConfirmsNothing(array $receipt): void
    {
        $payment = $this->httpPayment($this->http());
        $this->http($this->settledLater())->process($this->httpCall($payment), $this->handler());

        $response = $this->http($this->settledLater(), static fn (array $state): ?array => $receipt)->process($this->httpCall($payment), $this->handler());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('settlement_pending', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame('pending', $this->ledger->get(Settlement::key($payment))['status'], 'the claim is kept');
        self::assertSame(1, $this->runs);
    }

    /** Failed before: the receipt was stored as given, and its INF made every retry a JsonException (500). */
    public function testALookupWithExtensionsThatCannotBeEncodedStillConfirms(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->http($this->settledLater())->process($this->httpCall($payment), $this->handler());

        $lookup = static fn (array $state): ?array => ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532', 'extensions' => ['x' => INF]];
        $response = $this->http($this->settledLater(), $lookup)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertArrayNotHasKey('extensions', $this->ledger->get(Settlement::key($payment))['receipt']);
    }

    public function testALookupThatMatchesConfirmsAndGetsThePaymentAndRequirements(): void
    {
        $payment = $this->httpPayment($this->http());
        $this->http($this->settledLater())->process($this->httpCall($payment), $this->handler());
        $from = $payment['payload']['authorization']['from'];
        $seen = [];
        // The payer the signature proves, in another case: EVM hex.
        $lookup = static function (array $state, array $p, array $req) use ($from, &$seen): ?array {
            $seen = [$p['payload']['authorization']['from'], $req['network']];

            return ['success' => true, 'transaction' => '0xABC', 'network' => 'eip155:84532', 'payer' => strtoupper($from)];
        };

        $response = $this->http($this->settledLater(), $lookup)->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$from, 'eip155:84532'], $seen);
        self::assertSame(1, $this->runs);
    }
}
