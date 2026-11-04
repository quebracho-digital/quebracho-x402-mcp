<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402Middleware;

/**
 * PaymentIntent is dispatched before each risky step (the settle, the handler), so a crash after the send
 * still leaves a trace; and it never carries the signature, nor blocks a sale.
 */
final class PaymentIntentTest extends TestCase
{
    use TempClaims;

    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const BUYER = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    /** Order of calls: 'intent:<phase>', 'settle', 'handler'. */
    private \ArrayObject $log;
    /** @var list<PaymentIntent> */
    private array $intents = [];

    protected function setUp(): void
    {
        $this->log = new \ArrayObject();
    }

    private function dispatcher(bool $throws = false): EventDispatcherInterface
    {
        return new class($this->log, $this->intents, $throws) implements EventDispatcherInterface {
            public function __construct(private \ArrayObject $log, private array &$intents, private bool $throws)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof PaymentIntent) {
                    $this->intents[] = $event;
                    $this->log[] = 'intent:'.$event->phase;
                    if ($this->throws) {
                        throw new \RuntimeException('a broken listener');
                    }
                }

                return $event;
            }
        };
    }

    private function middleware(bool $upfront, bool $throws = false): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();
        $settle = function (array $payment, array $r): array {
            $this->log[] = 'settle';

            return ['success' => true, 'transaction' => '0xabc', 'network' => $r['network'], 'payer' => $payment['payload']['authorization']['from'] ?? null];
        };

        return new X402Middleware(
            [new EvmExact()],
            static fn () => [$req],
            static fn (string $key): bool => throw new \LogicException('the ledger claims, not the closure'),
            $f,
            $f,
            $settle,
            $this->dispatcher($throws),
            ledger: new FileLedger($this->claimsDir()),
        );
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this->log) implements RequestHandlerInterface {
            public function __construct(private \ArrayObject $log)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->log[] = 'handler';

                return new Response(200, [], 'paid content');
            }
        };
    }

    /** @return array<string, mixed> the signed payment, as a buyer would send it */
    private function signedPayment(bool $upfront): array
    {
        $first = $this->middleware($upfront)->process(new ServerRequest('POST', 'https://example.test/paid'), $this->handler());
        $required = PaymentHeaders::decode($first->getHeaderLine(PaymentHeaders::REQUIRED));

        return (new EvmExact(new Signer(self::BUYER)))->pay($required, $required['accepts'][0]);
    }

    private function pay(bool $upfront, bool $throws = false): ResponseInterface
    {
        $payment = $this->signedPayment($upfront);
        $this->log->exchangeArray([]);
        $this->intents = [];

        return $this->middleware($upfront, $throws)->process(
            new ServerRequest('POST', 'https://example.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]),
            $this->handler(),
        );
    }

    public function testTheSettleIsAnnouncedBeforeItIsSent(): void
    {
        $response = $this->pay(false);

        self::assertSame(200, $response->getStatusCode());
        // The handler answers first (the default flow settles after it), then the intent, then the settle.
        self::assertSame(['handler', 'intent:settling', 'settle'], $this->log->getArrayCopy());
    }

    public function testTheUpfrontHandlerIsAnnouncedBeforeItRuns(): void
    {
        $response = $this->pay(true);

        $calls = $this->log->getArrayCopy();
        self::assertSame(200, $response->getStatusCode());
        // The settle is announced, sent, then the handling is announced, then the handler runs.
        self::assertSame(['intent:settling', 'settle', 'intent:handling', 'handler'], $calls);
        self::assertSame(['settling', 'handling'], array_map(static fn (PaymentIntent $i) => $i->phase, $this->intents));
    }

    #[DataProvider('flows')]
    public function testAListenerThatThrowsDoesNotBlockThePayment(bool $upfront): void
    {
        $response = $this->pay($upfront, throws: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader(PaymentHeaders::RESPONSE));
        self::assertContains('settle', $this->log->getArrayCopy());
    }

    public static function flows(): array
    {
        return [[false], [true]];
    }

    public function testTheIntentHasTheLedgerKeyAndNoSignatureOrPayload(): void
    {
        $payment = $this->signedPayment(false);
        $this->log->exchangeArray([]);
        $this->intents = [];
        $this->middleware(false)->process(
            new ServerRequest('POST', 'https://example.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]),
            $this->handler(),
        );

        self::assertNotEmpty($this->intents);
        $intent = $this->intents[0];
        $signature = $payment['payload']['signature'];
        self::assertNotSame('', $signature);

        self::assertSame(Settlement::key($payment), $intent->key);
        self::assertSame(['phase', 'key', 'payment', 'req', 'paymentId', 'transaction'], array_keys(get_object_vars($intent)));
        self::assertSame(['network', 'from'], array_keys($intent->payment));
        self::assertSame(['network', 'payTo', 'amount', 'asset'], array_keys($intent->req));
        self::assertStringNotContainsString($signature, serialize($intent));
        self::assertNull($intent->paymentId);
        self::assertNull($intent->transaction);
    }
}
