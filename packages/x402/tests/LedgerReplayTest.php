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

    private function http(?\Closure $settle = null, ?\Closure $lookup = null, ?PaymentLedger $ledger = null, bool $upfront = false): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402Middleware([new EvmExact()], static fn (ServerRequestInterface $r) => [$req], $this->unusedClaim(), $f, $f, $settle ?? Settlement::mock(), ledger: $ledger ?? $this->ledger, lookup: $lookup);
    }

    private function mcp(?\Closure $settle = null, ?\Closure $lookup = null, ?PaymentLedger $ledger = null, bool $upfront = false): X402McpMiddleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402McpMiddleware([new EvmExact()], static fn (array $params) => 'wisdom' === $params['name'] ? [$req] : null, $this->unusedClaim(), $f, $f, $settle ?? Settlement::mock(), ledger: $ledger ?? $this->ledger, lookup: $lookup);
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

    /** A ledger whose put() fails with $error (not a LedgerUnavailableException), every other operation working. */
    private function brokenPutLedger(\Throwable $error): PaymentLedger
    {
        return new class($this->ledger, $error) implements PaymentLedger {
            public function __construct(private readonly PaymentLedger $real, private readonly \Throwable $error)
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
                throw $this->error;
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

        $response = $this->http(ledger: $this->downLedger('put'))->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
        self::assertSame(1, $this->runs);
    }

    public function testMcpAnswerIsKeptWhenTheStoreFailsAfterASettle(): void
    {
        $payment = $this->mcpPayment($this->mcp());

        $answer = self::answer($this->mcp(ledger: $this->downLedger('put'))->process(self::mcpCall(2, $payment), $this->toolHandler()));
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

        $response = $this->http(ledger: $this->brokenPutLedger(new \RuntimeException('disk gone')))->process($this->httpCall($payment), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertSame(1, $this->runs);
    }

    public function testMcpAnswerIsKeptWhenTheStoreFailsWithAnyErrorAfterASettle(): void
    {
        $payment = $this->mcpPayment($this->mcp());

        $answer = self::answer($this->mcp(ledger: $this->brokenPutLedger(new \RuntimeException('disk gone')))->process(self::mcpCall(2, $payment), $this->toolHandler()));
        self::assertArrayNotHasKey('isError', $answer['result']);
        self::assertSame('paid content', $answer['result']['content'][0]['text']);
        self::assertSame(1, $this->runs);
    }
}
