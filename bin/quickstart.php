<?php

// quebracho/x402 in one file: a seller that charges for an HTTP route and a buyer that pays it.
// No framework, no server, no network, no funds: both sides run in this process, the facilitator
// is the mock (signatures are verified for real, nothing moves on chain) and the key is random.
//
//   ddev exec php bin/quickstart.php          (or: php bin/quickstart.php, PHP 8.3+ with ext-gmp)
//
// In your own project: composer require quebracho/x402 nyholm/psr7
// README.md, "Get started with the library", walks through it and says what to change for real money.
declare(strict_types=1);

require __DIR__.'/../app/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\Buyer;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Facilitators;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Networks;
use Quebracho\X402\Seller;
use Quebracho\X402\Signer;

$psr17 = new Psr17Factory();
$network = 'eip155:84532';                                  // Base Sepolia (CAIP-2). Testnet USDC.
$payTo = '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794';      // who gets paid: your wallet's address
$tmp = sys_get_temp_dir().'/x402-quickstart-'.bin2hex(random_bytes(4));

// ---------------------------------------------------------------------------------------------------------
// 1. SELL. Your application: any PSR-15 handler. It knows nothing about payments.
// ---------------------------------------------------------------------------------------------------------
$app = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'You asked for '.$request->getUri()->getPath().". Here it is.\n");
    }
};

// The middleware that charges. Four things: how payments are verified (a scheme per chain family), who
// settles them (a facilitator), where each payment is remembered (the ledger: one payment, one delivery),
// and what a request costs.
$facilitator = Facilitators::from('mock', $http = new class implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new LogicException('The mock facilitator calls nobody.');
    }
}, $psr17, $psr17);
// Real money: Facilitators::from('https://x402.org/facilitator', $anyPsr18Client, $psr17, $psr17)

$seller = Seller::with([new EvmExact()], $facilitator, $psr17, $psr17)
    ->ledger(new FileLedger($tmp.'/ledger'))                // several hosts: new RedisLedger('redis://…')
    ->http(static fn (ServerRequestInterface $request): ?array => match ($request->getUri()->getPath()) {
        '/free' => null,                                                     // null: not charged
        default => [Networks::requirements($network, '0.01', $payTo)],      // 0.01 USDC per request
    });

// ---------------------------------------------------------------------------------------------------------
// 2. BUY. A PSR-18 client that pays a 402 within a budget and sends the request again.
// ---------------------------------------------------------------------------------------------------------
// It wraps any PSR-18 client (Symfony's Psr18Client, Guzzle...; it must not follow redirects). Here the
// "wire" hands the request straight to the seller above, so the example needs no server.
$wire = new class($seller, $app) implements ClientInterface {
    public function __construct(private MiddlewareInterface $seller, private RequestHandlerInterface $app)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        echo '   -> '.$request->getMethod().' '.$request->getUri()->getPath().('' !== $request->getHeaderLine('PAYMENT-SIGNATURE') ? '  (with PAYMENT-SIGNATURE)' : '')."\n";
        $response = $this->seller->process(new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), (string) $request->getBody()), $this->app);
        echo '   <- '.$response->getStatusCode().('' !== $response->getHeaderLine('PAYMENT-REQUIRED') ? '  (PAYMENT-REQUIRED: the offer)' : '').('' !== $response->getHeaderLine('PAYMENT-RESPONSE') ? '  (PAYMENT-RESPONSE: the receipt)' : '')."\n";

        return $response;
    }
};

// The key that signs: yours, from a secret. Here a random one, made up for this run: it holds nothing, and the
// mock facilitator moves nothing. The buyer only signs; the facilitator sends the transaction and pays the gas.
$key = '0x'.bin2hex(random_bytes(32));
$buyer = Buyer::with([new EvmExact(new Signer($key))], Budget::usd($network, '0.05'))   // never more than 5 cents
    ->onPaid(static function (array $offer, array $receipt): void {
        echo "   paid {$offer['amount']} atomic units of {$offer['extra']['name']} on {$offer['network']} to {$offer['payTo']}\n";
        echo '   receipt: '.json_encode($receipt, JSON_UNESCAPED_SLASHES)."\n";
    })
    ->onRefused(static function (array $offer, string $reason): void {
        echo "   not paid: $reason\n";
    })
    ->http($wire);

// ---------------------------------------------------------------------------------------------------------
// 3. RUN.
// ---------------------------------------------------------------------------------------------------------
echo "A free route: no payment asked\n";
echo '   '.$buyer->sendRequest($psr17->createRequest('GET', 'https://shop.example/free'))->getBody();

echo "\nA paid route: 402, signed within the budget, sent again\n";
echo '   '.$buyer->sendRequest($psr17->createRequest('GET', 'https://shop.example/report'))->getBody();

echo "\nSix more: the budget (0.05 USDC) pays four of them, then the buyer keeps the 402\n";
for ($i = 1; $i <= 6; ++$i) {
    $status = $buyer->sendRequest($psr17->createRequest('GET', "https://shop.example/report/$i"))->getStatusCode();
    echo "   = $status\n";
}

exec('rm -rf '.escapeshellarg($tmp));
