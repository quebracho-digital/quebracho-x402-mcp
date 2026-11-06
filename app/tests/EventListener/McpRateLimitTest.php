<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\McpRateLimit;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class McpRateLimitTest extends TestCase
{
    private McpRateLimit $limit;

    protected function setUp(): void
    {
        $this->limit = self::limit(perClient: 1);
    }

    private static function limit(int $perClient = 1, int $global = 100, ?RateLimiterFactoryInterface $client = null, int $terms = 100): McpRateLimit
    {
        $factory = static fn (string $id, int $n) => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $n, 'interval' => '1 minute'], new InMemoryStorage());
        $psr17 = new Psr17Factory();

        return new McpRateLimit($client ?? $factory('mcp', $perClient), $factory('all', $global), new MiddlewareFactory(['localhost'], []), new PsrHttpFactory($psr17, $psr17, $psr17, $psr17), $psr17, $factory('terms', $terms));
    }

    /** What the router leaves on a request to the MCP endpoint (or $route for any other). */
    private function event(string $ip, string $method = 'POST', ?string $route = '_mcp_endpoint_default', string $body = '', int $type = HttpKernelInterface::MAIN_REQUEST, string $host = 'localhost'): RequestEvent
    {
        $request = Request::create("http://$host/mcp", $method, server: ['REMOTE_ADDR' => $ip], content: $body);
        $request->attributes->set('_route', $route);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        ($this->limit)($event);

        return $event;
    }

    private function send(string $ip, string $method = 'POST', ?string $route = '_mcp_endpoint_default', int $type = HttpKernelInterface::MAIN_REQUEST): ?int
    {
        return $this->event($ip, $method, $route, type: $type)->getResponse()?->getStatusCode();
    }

    public function testAClientOverItsLimitIsRefusedBeforeTheControllerRuns(): void
    {
        self::assertNull($this->send('203.0.113.1'));
        self::assertSame(429, $this->send('203.0.113.1'));
    }

    public function testEachClientIpHasItsOwnBucket(): void
    {
        self::assertNull($this->send('203.0.113.1'));
        self::assertNull($this->send('203.0.113.2'));
        self::assertSame(429, $this->send('203.0.113.1'));
    }

    public function testAnIpv6ClientIsOneBucketPer64(): void
    {
        self::assertNull($this->send('2001:db8:1:2::1'));
        self::assertSame(429, $this->send('2001:db8:1:2:ffff:ffff:ffff:ffff'), 'same /64');
        self::assertNull($this->send('2001:db8:1:3::1'), 'another /64');
        self::assertSame('203.0.113.7', McpRateLimit::client('203.0.113.7'));
        self::assertSame('2001:db8:1:2::', McpRateLimit::client('2001:db8:1:2:aaaa::1'));
    }

    public function testOnlyAPostToTheMcpRouteInAMainRequestCounts(): void
    {
        self::assertNull($this->send('203.0.113.1', 'GET'));
        self::assertNull($this->send('203.0.113.1', route: '_preview_error'));
        self::assertNull($this->send('203.0.113.1', route: null));
        self::assertNull($this->send('203.0.113.1', type: HttpKernelInterface::SUB_REQUEST));
        // None of those used the only call.
        self::assertNull($this->send('203.0.113.1'));
        // A sub-request is never refused, even over the limit.
        self::assertNull($this->send('203.0.113.1', type: HttpKernelInterface::SUB_REQUEST));
    }

    public function testTheRefusalIsAJsonRpcErrorWithItsIdAndRetryAfter(): void
    {
        $this->send('203.0.113.1');
        $response = $this->event('203.0.113.1', body: '{"jsonrpc":"2.0","id":7,"method":"tools/call"}')->getResponse();

        self::assertSame(['code' => -32000, 'id' => 7], ['code' => json_decode((string) $response?->getContent(), true)['error']['code'], 'id' => json_decode((string) $response?->getContent(), true)['id']]);
        $retry = (int) $response?->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retry);
        self::assertLessThanOrEqual(61, $retry, 'the window, rounded up');
    }

    public function testABatchANotificationOrAHugeBodyGetsIdNull(): void
    {
        $this->send('203.0.113.1');
        foreach (['[{"jsonrpc":"2.0","id":1}]', '{"jsonrpc":"2.0","method":"notifications/x"}', '{"id":{"x":1}}', '{"id":1,"pad":"'.str_repeat('x', 9000).'"}', 'not json'] as $body) {
            $response = $this->event('203.0.113.1', body: $body)->getResponse();
            self::assertSame(429, $response?->getStatusCode());
            self::assertNull(json_decode((string) $response?->getContent(), true)['id'], $body);
        }
    }

    public function testRetryAfterIsNeverBelowOneSecond(): void
    {
        // A refusal whose window already ended (clock skew, a store that lags).
        $refused = new RateLimit(0, new \DateTimeImmutable('-5 seconds'), false, 1);
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($refused);
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);
        $this->limit = self::limit(client: $factory);

        self::assertSame('1', $this->event('203.0.113.1')->getResponse()?->headers->get('Retry-After'));
    }

    public function testTheWholeServerHasACapToo(): void
    {
        $this->limit = self::limit(perClient: 10, global: 2);
        self::assertNull($this->send('2001:db8:1:1::1'));
        self::assertNull($this->send('2001:db8:1:2::1'));
        self::assertSame(429, $this->send('2001:db8:1:3::1'), 'a third /64 of the same /48');
    }

    public function testAClientOverItsOwnLimitDoesNotSpendEverybodysCap(): void
    {
        $this->limit = self::limit(perClient: 1, global: 2);
        $this->send('203.0.113.1');
        $this->send('203.0.113.1');
        $this->send('203.0.113.1');
        self::assertNull($this->send('203.0.113.2'), 'the refused calls did not count globally');
    }

    public function testAForeignHostOrOriginSpendsNoBucket(): void
    {
        // The transport answers these with 403; the listener lets them through uncounted.
        self::assertNull($this->event('203.0.113.1', host: 'evil.example')->getResponse());
        $origin = Request::create('http://localhost/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.1', 'HTTP_ORIGIN' => 'https://evil.example']);
        $origin->attributes->set('_route', '_mcp_endpoint_default');
        ($this->limit)(new RequestEvent($this->createStub(HttpKernelInterface::class), $origin, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($this->send('203.0.113.1'), 'the only call is still there');
        self::assertSame(429, $this->send('203.0.113.1'));
    }

    public function testABrowserMayReadRetryAfter(): void
    {
        $this->send('203.0.113.1');
        self::assertSame('Retry-After', $this->event('203.0.113.1')->getResponse()?->headers->get('Access-Control-Expose-Headers'));
    }

    /** A tools/call body; $payment adds params._meta["x402/payment"], as a paying buyer sends it. */
    private static function toolCall(string $tool, ?string $text = 'We may suspend your account.', bool $payment = true): string
    {
        $params = ['name' => $tool, 'arguments' => null === $text ? [] : ['text' => $text]];
        if ($payment) {
            $params['_meta']['x402/payment'] = ['x402Version' => 2, 'payload' => []];
        }

        return json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $params], \JSON_THROW_ON_ERROR);
    }

    public function testAPaidAnalyzeTermsOverItsClientLimitIsRefusedBeforeThePaymentIsRead(): void
    {
        $this->limit = self::limit(perClient: 100, terms: 1);
        self::assertNull($this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse());
        // The response set here ends the request: the controller (x402 middleware, then the tool) never runs.
        $refused = $this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse();
        self::assertSame(429, $refused?->getStatusCode());
        $error = json_decode((string) $refused?->getContent(), true);
        self::assertSame([3, 'Rate limit reached for analyze_terms. Nothing was charged; try later.'], [$error['id'], $error['error']['message']]);
        self::assertGreaterThanOrEqual(1, (int) $refused?->headers->get('Retry-After'));
        self::assertNull($this->event('203.0.113.2', body: self::toolCall('analyze_terms'))->getResponse(), 'another client has its own bucket');
    }

    public function testOnlyAPaidAnalyzeTermsCallWithATextTheToolTakesSpendsItsLimit(): void
    {
        $this->limit = self::limit(perClient: 100, terms: 1);
        foreach ([
            self::toolCall('analyze_terms', payment: false), // unpaid: only the price comes back
            self::toolCall('analyze_terms', ''), // a text the tool refuses, charging nothing
            self::toolCall('analyze_terms', null),
            self::toolCall('wisdom'), // another paid tool
            '['.self::toolCall('analyze_terms').']', // a batch: the middleware refuses paid calls in one
        ] as $body) {
            self::assertNull($this->event('203.0.113.1', body: $body)->getResponse(), $body);
        }
        self::assertNull($this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse(), 'none of those used the only call');
        self::assertSame(429, $this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse()?->getStatusCode());
    }

    public function testTheJobStartSpendsTheSameHourlyBucket(): void
    {
        // analyze_terms_start calls the same model: one bucket per client for both tools, or the job doubles the limit.
        $this->limit = self::limit(perClient: 100, terms: 1);
        self::assertNull($this->event('203.0.113.1', body: self::toolCall('analyze_terms_start'))->getResponse());
        self::assertSame(429, $this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse()?->getStatusCode());
        self::assertNull($this->event('203.0.113.1', body: self::toolCall('analyze_terms_status'))->getResponse(), 'the free status read is not counted');
    }

    public function testAnalyzeTermsCountsAnIpv6ClientByIts64(): void
    {
        $this->limit = self::limit(perClient: 100, terms: 1);
        self::assertNull($this->event('2001:db8:1:2::1', body: self::toolCall('analyze_terms'))->getResponse());
        self::assertSame(429, $this->event('2001:db8:1:2::2', body: self::toolCall('analyze_terms'))->getResponse()?->getStatusCode(), 'same /64');
        self::assertNull($this->event('2001:db8:1:3::1', body: self::toolCall('analyze_terms'))->getResponse(), 'another /64');
    }

    public function testACallTheMcpLimitRefusesSpendsNoAnalyzeTermsCall(): void
    {
        // The client's /mcp bucket refuses the first call and takes the second.
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(0, new \DateTimeImmutable('+1 minute'), false, 1), new RateLimit(1, new \DateTimeImmutable(), true, 1));
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);
        $this->limit = self::limit(client: $factory, terms: 1);

        self::assertSame('Rate limit reached for /mcp. Nothing was charged; try later.', json_decode((string) $this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse()?->getContent(), true)['error']['message']);
        self::assertNull($this->event('203.0.113.1', body: self::toolCall('analyze_terms'))->getResponse(), 'the only analyze_terms call is still there');
    }
}
