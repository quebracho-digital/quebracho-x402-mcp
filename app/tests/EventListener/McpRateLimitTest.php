<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\McpRateLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class McpRateLimitTest extends TestCase
{
    private McpRateLimit $limit;

    protected function setUp(): void
    {
        $this->limit = new McpRateLimit(new RateLimiterFactory(['id' => 'mcp', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()));
    }

    private function send(string $ip, string $method = 'POST', string $path = '/mcp'): ?int
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($path, $method, server: ['REMOTE_ADDR' => $ip]), HttpKernelInterface::MAIN_REQUEST);
        ($this->limit)($event);

        return $event->getResponse()?->getStatusCode();
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

    public function testOnlyPostToMcpCounts(): void
    {
        self::assertNull($this->send('203.0.113.1', 'GET'));
        self::assertNull($this->send('203.0.113.1', path: '/other'));
        self::assertNull($this->send('203.0.113.1'));
    }

    public function testTheRefusalIsAJsonRpcErrorWithRetryAfter(): void
    {
        $this->send('203.0.113.1');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.1']), HttpKernelInterface::MAIN_REQUEST);
        ($this->limit)($event);

        self::assertSame(-32000, json_decode((string) $event->getResponse()?->getContent(), true)['error']['code']);
        self::assertGreaterThanOrEqual(1, (int) $event->getResponse()?->headers->get('Retry-After'));
    }
}
