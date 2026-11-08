<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\AddressRejected;
use Quebracho\X402\Budget;
use Quebracho\X402\BudgetReclaim;
use Quebracho\X402\ChainalysisApi;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\PaymentFault;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\PaymentLedger;
use Quebracho\X402\PaymentLog;
use Quebracho\X402\PaymentOutcome;
use Quebracho\X402\PurchaseIntent;
use Quebracho\X402\PurchaseOutcome;
use Quebracho\X402\ScreeningSkipped;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\TokenBlacklist;
use Quebracho\X402\X402Middleware;

/**
 * The events that report what the library carries on without: a ledger that failed, a screening list that
 * could not answer or rejected, and the buyer's side of each payment. None of them changes an answer.
 */
final class ReportedEventsTest extends TestCase
{
    use TempClaims;

    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const BUYER = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
    private const NET = 'eip155:84532';

    /** @var list<object> every event dispatched, in order */
    private array $seen = [];

    /**
     * A dispatcher that records every event and hands AddressCheck to $listeners.
     *
     * @param list<callable(AddressCheck): void> $listeners
     */
    private function events(array $listeners = []): EventDispatcherInterface
    {
        return new class($this->seen, $listeners) implements EventDispatcherInterface {
            public function __construct(private array &$seen, private array $listeners)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = $event;
                if ($event instanceof AddressCheck) {
                    foreach ($this->listeners as $listener) {
                        if ($event->isPropagationStopped()) {
                            break;
                        }
                        $listener($event);
                    }
                }

                return $event;
            }
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function seen(string $class): array
    {
        return array_values(array_filter($this->seen, static fn (object $e) => $e instanceof $class));
    }

    /**
     * A FileLedger whose put() throws for the statuses in $failing.
     *
     * @param list<string> $failing
     */
    private function ledger(array $failing): PaymentLedger
    {
        return new class(new FileLedger($this->claimsDir()), $failing) implements PaymentLedger {
            public function __construct(private FileLedger $inner, private array $failing)
            {
            }

            public function claim(string $key, int $ttl): bool
            {
                return $this->inner->claim($key, $ttl);
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $state, int $ttl): void
            {
                if (\in_array($state['status'] ?? null, $this->failing, true)) {
                    throw new \RuntimeException('disk full');
                }
                $this->inner->put($key, $state, $ttl);
            }

            public function release(string $key): void
            {
                $this->inner->release($key);
            }

            public function open(string $key, array $ref, int $ttl): void
            {
                $this->inner->open($key, $ref, $ttl);
            }

            public function openKeys(int $limit): array
            {
                return $this->inner->openKeys($limit);
            }

            public function close(string $key): void
            {
                $this->inner->close($key);
            }
        };
    }

    private function seller(PaymentLedger $ledger, \Closure $settle, ?\Closure $lookup = null): X402Middleware
    {
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $f = new Psr17Factory();

        return new X402Middleware([new EvmExact()], static fn () => [$req], static fn (): bool => true, $f, $f, $settle, $this->events(), ledger: $ledger, lookup: $lookup);
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'paid content');
            }
        };
    }

    private function pay(X402Middleware $seller, ?array $payment = null): ResponseInterface
    {
        if (null === $payment) {
            $required = PaymentHeaders::decode($seller->process(new ServerRequest('POST', 'https://example.test/paid'), self::handler())->getHeaderLine(PaymentHeaders::REQUIRED));
            $payment = (new EvmExact(new Signer(self::BUYER)))->pay($required, $required['accepts'][0]);
        }

        return $seller->process(new ServerRequest('POST', 'https://example.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]), self::handler());
    }

    public function testARefusedIntentIsAFaultAndTheOutcomeSaysWhy(): void
    {
        $response = $this->pay($this->seller($this->ledger(['settling']), Settlement::mock()));

        self::assertSame(503, $response->getStatusCode());
        [$fault] = $this->seen(PaymentFault::class);
        self::assertSame([PaymentFault::INTEND, self::NET, 'disk full'], [$fault->op, $fault->network, $fault->error->getMessage()]);
        [$outcome] = $this->seen(PaymentOutcome::class);
        self::assertSame(['failed', 'ledger_unavailable'], [$outcome->status, $outcome->reason]);
        self::assertSame($fault->error, $outcome->error);
    }

    public function testAStateLostAfterTheSettleIsAFaultAndTheAnswerStands(): void
    {
        $response = $this->pay($this->seller($this->ledger(['settled', 'delivered']), Settlement::mock()));

        self::assertSame(200, $response->getStatusCode());
        $faults = $this->seen(PaymentFault::class);
        self::assertSame([PaymentFault::RECORD], array_map(static fn (PaymentFault $f) => $f->op, $faults));
        self::assertSame('settled', $this->seen(PaymentOutcome::class)[0]->status);
    }

    public function testWhatTheSettleThrewReachesTheOutcome(): void
    {
        $boom = new \RuntimeException('facilitator said nonsense');
        $response = $this->pay($this->seller($this->ledger([]), static fn () => throw $boom));

        self::assertSame(402, $response->getStatusCode());
        [$outcome] = $this->seen(PaymentOutcome::class);
        self::assertSame(['not_settled', 'unexpected_settle_error'], [$outcome->status, $outcome->reason]);
        self::assertSame($boom, $outcome->error);
    }

    public function testALookupThatFailsIsAFaultAndThePaymentStaysPending(): void
    {
        $ledger = $this->ledger([]);
        $pending = static fn (array $payment, array $req): array => ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('ab', 32), 'network' => $req['network']];
        $seller = $this->seller($ledger, $pending, static fn () => throw new \RuntimeException('facilitator down'));
        $required = PaymentHeaders::decode($seller->process(new ServerRequest('POST', 'https://example.test/paid'), self::handler())->getHeaderLine(PaymentHeaders::REQUIRED));
        $payment = (new EvmExact(new Signer(self::BUYER)))->pay($required, $required['accepts'][0]);
        $this->pay($seller, $payment);
        $this->seen = [];

        $retry = $this->pay($seller, $payment);

        self::assertSame(409, $retry->getStatusCode());
        self::assertSame([PaymentFault::LOOKUP], array_map(static fn (PaymentFault $f) => $f->op, $this->seen(PaymentFault::class)));
    }

    public function testARejectionIsReportedOnceWithWhatThrew(): void
    {
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $broken = new \RuntimeException('list unreadable');
        $check = AddressCheck::start($this->events([static fn () => throw $broken]), AddressCheck::PAYER, '0x1111111111111111111111111111111111111111', $req);
        self::assertSame('address_check_failed', $check->verdict());
        $check->verdict();

        $rejected = $this->seen(AddressRejected::class);
        self::assertCount(1, $rejected);
        self::assertSame([AddressCheck::PAYER, self::NET, 'address_check_failed'], [$rejected[0]->role, $rejected[0]->network, $rejected[0]->reason]);
        self::assertSame($broken, $rejected[0]->error);
    }

    public function testADeferredRejectionIsReportedWhenItIsRead(): void
    {
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $lookup = new \RuntimeException('api down');
        $check = AddressCheck::start($this->events([static fn (AddressCheck $c) => $c->defer(static fn () => throw $lookup)]), AddressCheck::PAY_TO, self::PAY_TO, $req);
        self::assertSame([], $this->seen(AddressRejected::class), 'nothing is known before the lookup is read');

        self::assertSame('address_check_failed', $check->verdict());
        self::assertSame([$lookup], array_map(static fn (AddressRejected $r) => $r->error, $this->seen(AddressRejected::class)));
        self::assertNull(AddressCheck::run($this->events(), AddressCheck::PAY_TO, self::PAY_TO, $req));
        self::assertCount(1, $this->seen(AddressRejected::class), 'a pass reports nothing');
    }

    private static function failing(\Throwable|ResponseInterface $answer): ClientInterface
    {
        return new class($answer) implements ClientInterface {
            public function __construct(private \Throwable|ResponseInterface $answer)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->answer instanceof \Throwable ? throw $this->answer : $this->answer;
            }
        };
    }

    public function testAListThatFailsOpenReportsTheSkip(): void
    {
        $f = new Psr17Factory();
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $down = new \RuntimeException('node down');
        $lists = [
            new TokenBlacklist(self::failing($down), $f, $f, failOpen: true),
            new ChainalysisApi(self::failing(new Response(503)), $f, 'key', failOpen: true),
        ];

        self::assertNull(AddressCheck::run($this->events($lists), AddressCheck::PAYER, '0x1111111111111111111111111111111111111111', $req));

        $skipped = $this->seen(ScreeningSkipped::class);
        self::assertSame([TokenBlacklist::class, ChainalysisApi::class], array_map(static fn (ScreeningSkipped $s) => $s->by, $skipped));
        self::assertSame($down, $skipped[0]->error);
        self::assertSame([], $this->seen(AddressRejected::class));
    }

    public function testABadKeyIsNeverSkipped(): void
    {
        $f = new Psr17Factory();
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $api = new ChainalysisApi(self::failing(new Response(401)), $f, 'wrong', failOpen: true);

        self::assertSame('address_check_failed', AddressCheck::run($this->events([$api]), AddressCheck::PAYER, '0x1111111111111111111111111111111111111111', $req));
        self::assertSame([], $this->seen(ScreeningSkipped::class));
        self::assertInstanceOf(\LogicException::class, $this->seen(AddressRejected::class)[0]->error);
    }

    /**
     * A buyer in front of a seller that settles with the mock; $send answers the paid retry instead, when set.
     */
    private function buyer(Budget $budget, ?\Closure $send = null): PayingHttpClient
    {
        $seller = $this->seller(new FileLedger($this->claimsDir()), Settlement::mock());
        $wire = new class($seller, $send) implements ClientInterface {
            public function __construct(private X402Middleware $seller, private ?\Closure $send)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (null !== $this->send && $request->hasHeader(PaymentHeaders::SIGNATURE)) {
                    return ($this->send)();
                }

                return $this->seller->process(new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), (string) $request->getBody()), new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new Response(200, [], 'paid content');
                    }
                });
            }
        };

        return new PayingHttpClient($wire, [new EvmExact(new Signer(self::BUYER))], $budget, events: $this->events());
    }

    public function testTheBuyerAnnouncesThePaymentAndReportsItPaid(): void
    {
        $this->buyer(Budget::usd(self::NET, '0.05'))->sendRequest(new Request('GET', 'https://example.test/paid'));

        $buyerSide = array_values(array_filter($this->seen, static fn (object $e) => $e instanceof PurchaseIntent || $e instanceof PurchaseOutcome));
        self::assertCount(2, $buyerSide);
        [$intent, $outcome] = $buyerSide;
        self::assertInstanceOf(PurchaseIntent::class, $intent);
        self::assertSame(self::NET, $intent->req['network']);
        self::assertArrayNotHasKey('payload', $intent->payment, 'never the signature');
        // The same key the seller's ledger used: both sides can be matched.
        self::assertSame($this->seen(PaymentIntent::class)[0]->key, $intent->key);
        self::assertInstanceOf(PurchaseOutcome::class, $outcome);
        self::assertSame(PurchaseOutcome::PAID, $outcome->status);
        self::assertTrue($outcome->receipt['success']);
    }

    public function testTheBuyerReportsRefusedAndUnsettledOffers(): void
    {
        $this->buyer(Budget::usd(self::NET, '0.001'))->sendRequest(new Request('GET', 'https://example.test/paid'));
        self::assertSame([[PurchaseOutcome::REFUSED, 'not_allowed']], array_map(static fn (PurchaseOutcome $o) => [$o->status, $o->reason], $this->seen(PurchaseOutcome::class)));

        $this->seen = [];
        $lost = new \RuntimeException('connection reset');
        try {
            $this->buyer(Budget::usd(self::NET, '0.05'), static fn () => throw $lost)->sendRequest(new Request('GET', 'https://example.test/paid'));
            self::fail('the send error reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame($lost, $e);
        }
        [$unsettled] = $this->seen(PurchaseOutcome::class);
        self::assertSame([PurchaseOutcome::UNSETTLED, $lost], [$unsettled->status, $unsettled->error]);

        $this->seen = [];
        $refusal = ['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => self::NET];
        $this->buyer(Budget::usd(self::NET, '0.05'), static fn () => new Response(402, [PaymentHeaders::RESPONSE => PaymentHeaders::encode($refusal)]))->sendRequest(new Request('GET', 'https://example.test/paid'));
        [$unpaid] = $this->seen(PurchaseOutcome::class);
        self::assertSame([PurchaseOutcome::UNSETTLED, 'insufficient_funds'], [$unpaid->status, $unpaid->reason]);
    }

    public function testReclaimReportsEachSignatureItAskedAbout(): void
    {
        $now = 2_000_000_000;
        $answers = [false, true, new \RuntimeException('rpc down')];
        $used = static function () use (&$answers): bool {
            $answer = array_shift($answers);

            return $answer instanceof \Throwable ? throw $answer : $answer;
        };
        $budget = new Budget([self::NET => '1000000'], $used, static fn (): int => $now);
        $req = ['network' => self::NET, 'amount' => '10000'];
        foreach (['01', '02', '03'] as $n) {
            $budget->spend($req);
            $budget->unsettled($req, ['payload' => ['authorization' => ['from' => '0x'.str_repeat('1', 40), 'nonce' => '0x'.str_repeat($n, 32), 'validBefore' => $now - 120]]]);
        }

        $budget->reclaim($this->events());

        self::assertSame([BudgetReclaim::FREED, BudgetReclaim::USED, BudgetReclaim::UNKNOWN], array_map(static fn (BudgetReclaim $r) => $r->status, $this->seen(BudgetReclaim::class)));
        self::assertSame('rpc down', $this->seen(BudgetReclaim::class)[2]->error?->getMessage());
        self::assertSame('20000', $budget->spent(self::NET));
    }

    public function testPaymentLogWritesALineForEachEvent(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $log = new PaymentLog($logger);
        $req = Networks::requirements(self::NET, '0.01', self::PAY_TO);
        $error = new \RuntimeException('why');
        $events = [
            new PaymentIntent('settling', 'k', ['network' => self::NET], $req),
            new PaymentOutcome('failed_after_settle', [], $req),
            new PaymentFault(PaymentFault::RECORD, 'k', self::NET, $error),
            new PaymentFault(PaymentFault::CLOSE, 'k', self::NET, $error),
            new AddressRejected(AddressCheck::PAYER, self::NET, self::PAY_TO, 'address_sanctioned'),
            new ScreeningSkipped(AddressCheck::PAYER, self::NET, self::PAY_TO, TokenBlacklist::class, $error),
            new PurchaseIntent('k', ['network' => self::NET, 'from' => self::PAY_TO], $req),
            new PurchaseOutcome(PurchaseOutcome::PAID, $req),
            new BudgetReclaim(BudgetReclaim::UNKNOWN, self::NET, self::PAY_TO, '0x01', '10000', $error),
        ];
        foreach ($events as $event) {
            $log->{PaymentLog::events()[$event::class]}($event);
        }

        self::assertSame(
            [['info', 'x402 intent'], ['error', 'x402 outcome'], ['error', 'x402 fault'], ['warning', 'x402 fault'], ['warning', 'x402 address rejected'], ['warning', 'x402 screening skipped'], ['info', 'x402 purchase intent'], ['info', 'x402 purchase'], ['warning', 'x402 budget reclaim']],
            array_map(static fn (array $l) => [$l[0], $l[1]], $logger->lines),
        );
        self::assertSame($error, $logger->lines[2][2]['exception']);
        self::assertArrayNotHasKey('exception', $logger->lines[0][2]);
        self::assertEqualsCanonicalizing(array_values(array_unique(array_map(static fn (object $e) => $e::class, $events))), array_keys(PaymentLog::events()), 'every reported event has a method');
    }
}
