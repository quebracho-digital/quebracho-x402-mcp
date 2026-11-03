<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\McpRateLimit;
use PHPUnit\Framework\TestCase;
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
        $this->limit = new McpRateLimit(new RateLimiterFactory(['id' => 'mcp', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()));
    }

    /** What the router leaves on a request to the MCP endpoint (or $route for any other). */
    private function event(string $ip, string $method = 'POST', ?string $route = '_mcp_endpoint_default', string $body = '', int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/mcp', $method, server: ['REMOTE_ADDR' => $ip], content: $body);
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
        $this->limit = new McpRateLimit($factory);

        self::assertSame('1', $this->event('203.0.113.1')->getResponse()?->headers->get('Retry-After'));
    }
}
