<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\X402StopCommand;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\Buyer;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\FileLedger;
use Quebracho\X402\MockFacilitator;
use Quebracho\X402\PriceList;
use Quebracho\X402\Seller;
use Quebracho\X402\Signer;
use Quebracho\X402\Stop;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * app:x402:stop on the library's Stop, and what the seller and the buyer do while it is engaged. Every way the
 * switch is used in this app:
 *
 *   the command   on, on again, off, off again, status (and its exit code), an unknown action, a flag set by
 *                 `touch`, a flag path that cannot be trusted or removed
 *   the seller    no offer and no sale while paused, free tools untouched, a payment the ledger already holds
 *                 still delivered, selling again once released
 *   the buyer     nothing signed while paused, the offer reported as refused
 *
 * The deploy check's side is in X402CheckCommandTest, the wiring (one instance for both) in AppKernelTest. What
 * the library does between the claim and the settle when the switch is engaged mid-request is the library's own
 * test (StopTest in quebracho-digital/x402).
 */
final class X402StopCommandTest extends TestCase
{
    private string $dir;
    private Stop $stop;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/x402-stop-'.bin2hex(random_bytes(4));
        $this->stop = new Stop($this->dir.'/flag/paused');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @return array{int, string} */
    private function run_(string ...$args): array
    {
        $code = (new X402StopCommand($this->stop))(new SymfonyStyle(new ArrayInput([]), $out = new BufferedOutput()), ...$args);

        return [$code, $out->fetch()];
    }

    public function testOnOffAndStatus(): void
    {
        [$code, $text] = $this->run_();
        self::assertSame(0, $code, 'status is the default action, and 0 means running');
        self::assertStringContainsString('Payments are running', $text);

        [$code, $text] = $this->run_('on', 'facilitator incident');
        self::assertSame(0, $code);
        self::assertStringContainsString('paused', $text);
        self::assertTrue($this->stop->engaged());

        // Status while paused: says so, shows the reason, and exits 1 so a script can tell.
        [$code, $text] = $this->run_('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('PAUSED', $text);
        self::assertStringContainsString('facilitator incident', $text);

        // On again: still paused, and the newer reason replaces the old one.
        self::assertSame(0, $this->run_('on', 'second thoughts')[0]);
        [$code, $text] = $this->run_('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('second thoughts', $text);
        self::assertStringNotContainsString('facilitator incident', $text);

        [$code, $text] = $this->run_('off');
        self::assertSame(0, $code);
        self::assertStringContainsString('resumed', $text);
        self::assertFalse($this->stop->engaged());
        self::assertSame(0, $this->run_('status')[0]);
        // Off twice is not an error: the state asked for is the state reached.
        self::assertSame(0, $this->run_('off')[0]);

        self::assertSame(2, $this->run_('pause')[0], 'an unknown action changes nothing');
        self::assertFalse($this->stop->engaged());
    }

    public function testAFlagSetByHandCountsAndTheCommandClearsIt(): void
    {
        // The emergency path the docs promise: no PHP, just touch.
        mkdir($this->dir.'/flag', 0700, true);
        touch($this->dir.'/flag/paused');

        [$code, $text] = $this->run_('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('PAUSED', $text);
        self::assertSame(0, $this->run_('off')[0]);
        self::assertSame(0, $this->run_('status')[0]);
    }

    public function testAFlagPathThatCannotBeTrustedIsAnErrorThatSaysWhereThingsStand(): void
    {
        // A directory others can write to: anyone could set or remove the flag. The library refuses to engage there.
        mkdir($this->dir.'/flag', 0777, true);
        chmod($this->dir.'/flag', 0777);
        [$code, $text] = $this->run_('on');
        self::assertSame(1, $code);
        self::assertStringContainsString('NOT paused', $text);
        self::assertFalse($this->stop->engaged());

        // Something that is no file sits at the path: it counts as engaged (fails closed), and neither on nor
        // off can change it. The command says payments are paused instead of pretending either worked.
        chmod($this->dir.'/flag', 0700);
        mkdir($this->dir.'/flag/paused');
        [$code, $text] = $this->run_('on');
        self::assertSame(1, $code);
        self::assertStringContainsString('ARE paused', $text);
        [$code, $text] = $this->run_('off');
        self::assertSame(1, $code);
        self::assertStringContainsString('still paused', $text);
        self::assertSame(1, $this->run_('status')[0]);
    }

    /** An MCP server of two tools behind the x402 middleware: `wisdom` is paid, `echo` is free. */
    private function seller(array &$ran, ?Stop $stop, bool $ledger = false): \Closure
    {
        $f = new Psr17Factory();
        $prices = new PriceList('eip155:84532', '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794', '0.01', ['wisdom']);
        $seller = Seller::with([new EvmExact()], new MockFacilitator(), $f, $f);
        $seller = $ledger ? $seller->ledger(new FileLedger($this->dir.'/ledger')) : $seller->claims(new FileClaims($this->dir.'/claims'));
        $x402 = (null === $stop ? $seller : $seller->stop($stop))->mcp($prices);
        $server = new class($ran) implements RequestHandlerInterface {
            /** @param list<string> $ran */
            public function __construct(private array &$ran)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $call = json_decode((string) $request->getBody(), true);
                $this->ran[] = $call['params']['name'];

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['jsonrpc' => '2.0', 'id' => $call['id'], 'result' => ['content' => [['type' => 'text', 'text' => 'ok '.\count($this->ran)]]]]));
            }
        };

        return static fn (ServerRequestInterface $request): ResponseInterface => $x402->process($request, $server);
    }

    private static function call(string $tool, array $meta = []): ServerRequest
    {
        return new ServerRequest('POST', 'https://shop.example/mcp', ['Content-Type' => 'application/json'], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []] + ([] === $meta ? [] : ['_meta' => $meta])]));
    }

    public function testAPausedSellerSellsNothingAndStillServesFreeTools(): void
    {
        $ran = [];
        $seller = $this->seller($ran, $this->stop);
        $call = static fn (string $tool, array $meta = []): array => json_decode((string) $seller(self::call($tool, $meta))->getBody(), true);

        // Running: an unpaid call of a paid tool gets the offer.
        $offer = $call('wisdom')['result']['structuredContent']['accepts'][0];

        $this->run_('on');
        // Paused: no offer to sign, and a payment for our own offer is refused before it is verified or claimed.
        self::assertSame('payments_paused', $call('wisdom')['error']['data']['reason']);
        self::assertSame('payments_paused', $call('wisdom', [X402McpMiddleware::PAYMENT => ['x402Version' => 2, 'accepted' => $offer, 'payload' => []]])['error']['data']['reason']);
        self::assertSame([], $ran, 'the paid tool never ran');
        // A free tool is not a payment.
        self::assertSame('ok 1', $call('echo')['result']['content'][0]['text']);
        self::assertSame(['echo'], $ran);

        $this->run_('off');
        self::assertArrayHasKey('accepts', $call('wisdom')['result']['structuredContent']);
    }

    public function testAPaymentTheLedgerAlreadyHoldsIsStillDeliveredWhilePaused(): void
    {
        $ran = [];
        $seller = $this->seller($ran, $this->stop, ledger: true);
        $call = static fn (array $meta = []): array => json_decode((string) $seller(self::call('wisdom', $meta))->getBody(), true);
        $required = $call()['result']['structuredContent'];
        $paid = [X402McpMiddleware::PAYMENT => (new EvmExact(new Signer('0x'.bin2hex(random_bytes(32)))))->pay($required, $required['accepts'][0])];

        $first = $call($paid);
        self::assertSame('ok 1', $first['result']['content'][0]['text']);
        self::assertTrue($first['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);

        $this->run_('on');
        // The buyer lost the answer and retries with the same payment: it was paid, so it is answered, from the
        // ledger. The tool does not run again and nothing new is charged.
        self::assertSame($first['result'], $call($paid)['result']);
        self::assertSame(['wisdom'], $ran);
        // A payment the ledger has never seen is new business: paused.
        $other = [X402McpMiddleware::PAYMENT => (new EvmExact(new Signer('0x'.bin2hex(random_bytes(32)))))->pay($required, $required['accepts'][0])];
        self::assertSame('payments_paused', $call($other)['error']['data']['reason']);
        self::assertSame(['wisdom'], $ran);
    }

    public function testAPausedBuyerSignsNothingAndReportsTheOfferAsRefused(): void
    {
        $ran = [];
        // The seller is running (no switch of its own here): what stops this purchase is the buyer's side alone.
        $seller = $this->seller($ran, null);
        $sent = [];
        $wire = new class($seller, $sent) implements ClientInterface {
            /** @param list<bool> $sent whether each request carried a payment */
            public function __construct(private \Closure $seller, private array &$sent)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $body = (string) $request->getBody();
                $this->sent[] = isset(json_decode($body, true)['params']['_meta'][X402McpMiddleware::PAYMENT]);

                return ($this->seller)(new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), $body));
            }
        };
        $refused = [];
        $paid = 0;
        $buyer = Buyer::with([new EvmExact(new Signer('0x'.bin2hex(random_bytes(32))))], Budget::usd('eip155:84532', '0.05'))
            ->onPaid(static function () use (&$paid): void { ++$paid; })
            ->onRefused(static function (array $req, string $reason) use (&$refused): void { $refused[] = $reason; })
            ->stop($this->stop)
            ->mcp($wire, new Psr17Factory());
        $buy = static fn (): array => json_decode((string) $buyer->sendRequest(self::call('wisdom'))->getBody(), true);

        $this->run_('on');
        $answer = $buy();
        // The caller gets the seller's PaymentRequired as it came: nothing was signed, so nothing was sent again.
        self::assertTrue($answer['result']['isError']);
        self::assertArrayHasKey('accepts', $answer['result']['structuredContent']);
        self::assertSame([false], $sent);
        self::assertSame(['payments_paused'], $refused);
        self::assertSame([0, []], [$paid, $ran]);

        $this->run_('off');
        self::assertSame('ok 1', $buy()['result']['content'][0]['text']);
        self::assertSame([false, false, true], $sent, 'the offer, then the same call with its payment');
        self::assertSame(1, $paid);
    }
}
