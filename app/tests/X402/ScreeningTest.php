<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\Screening;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\AddressRejected;
use Quebracho\X402\OfacSdn;
use Quebracho\X402\ScreeningSkipped;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ScreeningTest extends TestCase
{
    private const OURS = '0x00000000000000000000000000000000000000aa';
    private const BAD = '0x00000000000000000000000000000000000000bb';
    private const CLEAN = '0x00000000000000000000000000000000000000dd';

    private string $dir;
    /** @var list<object> what the library reported */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/screening-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /** @param list<MockResponse>|MockResponse|\Closure $api */
    private function screening(array|MockResponse|\Closure $api = [], string $unavailable = 'reject', string $key = 'k', ?OfacSdn $ofac = null): Screening
    {
        return new Screening($this->dir, $key, 60, 0.5, $unavailable, $ofac ?? OfacSdn::bundled(), new MockHttpClient($api), new ArrayAdapter());
    }

    /** What the seller reads: the library's check, OFAC first, then this listener, deferred lookups included. */
    private function verdict(Screening $screening, string $address, ?OfacSdn $ofac = null): ?string
    {
        $events = new EventDispatcher();
        $events->addListener(AddressCheck::class, $screening);
        foreach ([AddressRejected::class, ScreeningSkipped::class] as $event) {
            $events->addListener($event, function (object $e): void { $this->reported[] = $e; });
        }

        return AddressCheck::run($events, AddressCheck::PAYER, $address, ['network' => 'eip155:84532'], $ofac);
    }

    /** An address OFAC lists, from the copy shipped with the library. */
    private static function sanctioned(): string
    {
        $index = json_decode((string) file_get_contents(\dirname((new \ReflectionClass(OfacSdn::class))->getFileName()).'/../resources/ofac-sdn.json'), true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator($index)) as $value) {
            if (\is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/', $value)) {
                return $value;
            }
        }
        self::fail('no EVM address in the shipped OFAC list');
    }

    public function testOurListsDecideAfterOfac(): void
    {
        $screening = $this->screening(key: '');
        $screening->set(self::OURS, 'allow');
        $screening->set('0x'.strtoupper(substr(self::BAD, 2)), 'deny');

        self::assertNull($this->verdict($screening, self::OURS));
        self::assertSame('address_denied', $this->verdict($screening, self::BAD), 'stored lower case, matched in any case');
        self::assertNull($this->verdict($screening, self::CLEAN));
        self::assertSame(['allow' => [self::OURS], 'deny' => [self::BAD]], $screening->own());
    }

    public function testOurAllowlistNeverSkipsOfac(): void
    {
        $sanctioned = self::sanctioned();
        $screening = $this->screening(key: '');
        $screening->set($sanctioned, 'allow');

        self::assertSame('address_sanctioned', $this->verdict($screening, $sanctioned));
        self::assertSame('address_sanctioned', $screening->check($sanctioned));
    }

    public function testMovingAnAddressTakesItOffTheOtherList(): void
    {
        $screening = $this->screening(key: '');
        $screening->set(self::OURS, 'deny');
        $screening->set(self::OURS, 'allow');
        self::assertSame(['allow' => [self::OURS], 'deny' => []], $screening->own());

        $screening->set(self::OURS, null);
        self::assertSame(['allow' => [], 'deny' => []], $screening->own());
    }

    public function testAnUnreadableListStopsPayments(): void
    {
        (new Filesystem())->mkdir($this->dir.'/deny.txt');

        self::assertSame('address_check_failed', $this->verdict($this->screening(key: ''), self::CLEAN));
    }

    public function testTheApiAnswersAndIsCached(): void
    {
        $calls = 0;
        $api = function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"identifications":[{"category":"sanctions"}]}');
        };
        $screening = $this->screening($api);

        self::assertSame('address_sanctioned', $this->verdict($screening, self::CLEAN));
        self::assertSame('address_sanctioned', $screening->check(self::CLEAN));
        self::assertSame(1, $calls, 'the second answer came from the cache');
        self::assertInstanceOf(AddressRejected::class, $this->reported[0] ?? null);
    }

    public function testOurAllowlistNeverReachesTheApi(): void
    {
        $screening = $this->screening(new MockResponse('', ['http_code' => 500]));
        $screening->set(self::CLEAN, 'allow');

        self::assertNull($this->verdict($screening, self::CLEAN));
    }

    public function testNoAnswerFollowsThePolicy(): void
    {
        $down = static fn () => new MockResponse('', ['http_code' => 503]);

        self::assertSame('address_check_failed', $this->verdict($this->screening($down()), self::CLEAN));
        self::assertNull($this->verdict($this->screening($down(), 'allow'), self::CLEAN));
        self::assertInstanceOf(ScreeningSkipped::class, end($this->reported), 'a pass the API never answered leaves a trace');
    }

    public function testAWrongKeyRejectsWhateverThePolicy(): void
    {
        $screening = $this->screening(new MockResponse('', ['http_code' => 401]), 'allow');

        self::assertSame('address_check_failed', $this->verdict($screening, self::CLEAN));
    }

    public function testAFailureIsNeverCached(): void
    {
        $answers = [new MockResponse('', ['http_code' => 503]), new MockResponse('{"identifications":[]}')];
        $screening = $this->screening($answers);

        self::assertSame('address_check_failed', $this->verdict($screening, self::CLEAN));
        self::assertNull($this->verdict($screening, self::CLEAN));
    }

    public function testAnUnknownPolicyFailsAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->screening(unavailable: 'maybe');
    }
}
