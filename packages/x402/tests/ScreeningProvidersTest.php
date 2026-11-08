<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\AddressList;
use Quebracho\X402\ChainalysisApi;
use Quebracho\X402\ChainalysisOracle;
use Quebracho\X402\Networks;
use Quebracho\X402\OfacSdn;

/** The lists other than OFAC's own, and turning OFAC's off. */
final class ScreeningProvidersTest extends TestCase
{
    private const LISTED_BY_OFAC = '0x1CAb8177ACe78b1B6B1c393371F4f2dCAE40CbEB';
    private const CLEAN = '0x000000000000000000000000000000000000dEaD';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lists-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @param list<ResponseInterface|\Throwable> $answers */
    private static function http(array $answers, array &$asked = []): ClientInterface
    {
        return new class($answers, $asked) implements ClientInterface {
            public function __construct(private array $answers, private array &$asked)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->asked[] = $request;
                $next = array_shift($this->answers) ?? new Response(500);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };
    }

    /** A dispatcher with these listeners, in order. */
    private static function events(callable ...$listeners): EventDispatcherInterface
    {
        return new class($listeners) implements EventDispatcherInterface {
            public function __construct(private array $listeners)
            {
            }

            public function dispatch(object $event): object
            {
                foreach ($this->listeners as $listener) {
                    if ($event instanceof AddressCheck && $event->isPropagationStopped()) {
                        break;
                    }
                    $listener($event);
                }

                return $event;
            }
        };
    }

    private static function check(?EventDispatcherInterface $events, string $address, ?OfacSdn $ofac = null): ?string
    {
        return AddressCheck::run($events, AddressCheck::PAYER, $address, Networks::requirements('eip155:8453', '0.01', self::CLEAN), $ofac);
    }

    public function testOfacIsOffOnlyWhenAskedByName(): void
    {
        self::assertSame(OfacSdn::REASON, self::check(null, self::LISTED_BY_OFAC));
        self::assertNull(self::check(null, self::LISTED_BY_OFAC, OfacSdn::off()));
        self::assertTrue(OfacSdn::off()->isOff());
        self::assertFalse(OfacSdn::bundled()->isOff());
    }

    public function testTheExampleListParses(): void
    {
        self::assertSame(
            ['0x000000000000000000000000000000000000dead', '9xQeWvG816bUx9EPjHmaT23yvVM2ZWbrrpZb9PusVFin', 'scam-example.near'],
            AddressList::parse((string) file_get_contents(__DIR__.'/../resources/address-list.example.txt')),
        );
    }

    public function testAnAddressListRefusesItsAddressesInAnyCaseAfterOfac(): void
    {
        $file = $this->dir.'/mine.txt';
        file_put_contents($file, "# mine\n0xAAAA00000000000000000000000000000000aaaa  a note\n\nSoLaNaAddress111\n");
        $events = self::events(new AddressList([$file]));

        self::assertSame('address_denied', self::check($events, '0xaaaa00000000000000000000000000000000AAAA'));
        self::assertSame('address_denied', self::check($events, 'SoLaNaAddress111'));
        self::assertNull(self::check($events, 'solanaaddress111'), 'not 0x: exact');
        self::assertSame(OfacSdn::REASON, self::check($events, self::LISTED_BY_OFAC), 'OFAC answers first');
        self::assertSame('my_reason', self::check(self::events(new AddressList([$file], 'my_reason')), 'SoLaNaAddress111'));
    }

    public function testAListNeverSyncedRefusesEveryone(): void
    {
        self::assertSame('address_check_failed', self::check(self::events(new AddressList([$this->dir.'/missing.txt'])), self::CLEAN));
    }

    public function testSyncWritesAListTheListReadsAndKeepsTheOldOneOnABadAnswer(): void
    {
        $file = $this->dir.'/remote.txt';
        $asked = [];
        $count = AddressList::sync(self::http([new Response(200, [], "0xBBBB00000000000000000000000000000000bbbb\n# c\nnear-bad.near\n")], $asked), new Psr17Factory(), 'https://lists.example/bad.txt', $file);

        self::assertSame(2, $count);
        self::assertSame('https://lists.example/bad.txt', (string) $asked[0]->getUri());
        self::assertSame('address_denied', self::check(self::events(new AddressList([$file])), 'near-bad.near'));

        foreach ([
            'empty' => [self::http([new Response(200, [], "# nothing\n")]), 'https://lists.example/bad.txt'],
            'an error' => [self::http([new Response(404)]), 'https://lists.example/bad.txt'],
            'not https' => [self::http([]), 'http://lists.example/bad.txt'],
        ] as $case => [$http, $url]) {
            $before = file_get_contents($file);
            try {
                AddressList::sync($http, new Psr17Factory(), $url, $file);
                self::fail("$case: no exception");
            } catch (\RuntimeException) {
                self::assertSame($before, file_get_contents($file), $case);
            }
        }
    }

    public function testTheYoungestListWinsAndItsDateIsKept(): void
    {
        $file = $this->dir.'/remote.txt';
        $url = 'https://lists.example/bad.txt';
        // The source dates itself: that date is written.
        AddressList::sync(self::http([new Response(200, ['Last-Modified' => 'Fri, 06 Nov 2026 10:00:00 GMT'], "# updated: 2026-11-06T12:00:00Z\nnew.near\n")]), new Psr17Factory(), $url, $file);
        self::assertSame([$file => '2026-11-06T12:00:00Z'], (new AddressList([$file]))->updatedAt());

        // An older copy (a lagging mirror, a cache) never replaces it.
        self::assertSame(1, AddressList::sync(self::http([new Response(200, ['Last-Modified' => 'Thu, 05 Nov 2026 10:00:00 GMT'], "old.near\nolder.near\n")]), new Psr17Factory(), $url, $file));
        self::assertTrue((new AddressList([$file]))->listed('new.near'));
        self::assertFalse((new AddressList([$file]))->listed('old.near'));

        // A younger one does, dated by its Last-Modified when it has no line of its own.
        self::assertSame(2, AddressList::sync(self::http([new Response(200, ['Last-Modified' => 'Sat, 07 Nov 2026 10:00:00 GMT'], "a.near\nb.near\n")]), new Psr17Factory(), $url, $file));
        self::assertSame('2026-11-07T10:00:00Z', AddressList::updated((string) file_get_contents($file)));
        self::assertNull(AddressList::updated("# updated: someday\n"));
    }

    public function testTheOracleAsksIsSanctionedAndFailsClosed(): void
    {
        $f = new Psr17Factory();
        $true = new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1']));
        $false = new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]));
        $asked = [];

        self::assertSame(OfacSdn::REASON, self::check(self::events(new ChainalysisOracle(self::http([$true], $asked), $f, $f, 'https://rpc.example')), self::CLEAN));
        $call = json_decode((string) $asked[0]->getBody(), true)['params'][0];
        self::assertSame([ChainalysisOracle::CONTRACT, '0xdf592f7d'.str_repeat('0', 24).substr(strtolower(self::CLEAN), 2)], [$call['to'], strtolower($call['data'])]);

        self::assertNull(self::check(self::events(new ChainalysisOracle(self::http([$false]), $f, $f, 'https://rpc.example')), self::CLEAN));
        self::assertSame('address_check_failed', self::check(self::events(new ChainalysisOracle(self::http([new Response(502)]), $f, $f, 'https://rpc.example')), self::CLEAN));
        self::assertNull(self::check(self::events(new ChainalysisOracle(self::http([new Response(502)]), $f, $f, 'https://rpc.example', failOpen: true)), self::CLEAN));
        $none = [];
        self::assertNull(self::check(self::events(new ChainalysisOracle(self::http([], $none), $f, $f, 'https://rpc.example')), 'some.near'), 'EVM only');
        self::assertSame([], $none);
    }

    public function testTheApiSendsTheKeyAndReadsIdentifications(): void
    {
        $f = new Psr17Factory();
        $asked = [];
        $listed = new Response(200, [], json_encode(['identifications' => [['category' => 'sanctions']]]));

        self::assertSame(OfacSdn::REASON, self::check(self::events(new ChainalysisApi(self::http([$listed], $asked), $f, 'k3y')), self::CLEAN));
        self::assertSame('k3y', $asked[0]->getHeaderLine('X-API-Key'));
        self::assertStringEndsWith('/address/'.self::CLEAN, (string) $asked[0]->getUri());
        self::assertNull(self::check(self::events(new ChainalysisApi(self::http([new Response(200, [], '{"identifications":[]}')]), $f, 'k3y')), self::CLEAN));
        self::assertSame('address_check_failed', self::check(self::events(new ChainalysisApi(self::http([new Response(503)]), $f, 'k3y')), self::CLEAN));
        self::assertNull(self::check(self::events(new ChainalysisApi(self::http([new Response(503)]), $f, 'k3y', failOpen: true)), self::CLEAN));
        self::assertSame('address_check_failed', self::check(self::events(new ChainalysisApi(self::http([new Response(401)]), $f, 'k3y', failOpen: true)), self::CLEAN), 'a wrong key never passes');
    }
}
