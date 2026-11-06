<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\McpBodyLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class McpBodyLimitTest extends TestCase
{
    private function answer(string $body, string $method = 'POST', ?string $route = '_mcp_endpoint_default', ?string $declared = null): ?int
    {
        $request = Request::create('http://localhost/mcp', $method, content: $body);
        $request->attributes->set('_route', $route);
        if (null !== $declared) {
            $request->headers->set('Content-Length', $declared);
        }
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new McpBodyLimit(10))($event);

        return $event->getResponse()?->getStatusCode();
    }

    public function testABodyUpToTheLimitPasses(): void
    {
        self::assertNull($this->answer(str_repeat('a', 10)));
    }

    public function testALargerBodyIsRefusedWithAJsonRpcError(): void
    {
        $request = Request::create('http://localhost/mcp', 'POST', content: str_repeat('a', 11));
        $request->attributes->set('_route', '_mcp_endpoint_default');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new McpBodyLimit(10))($event);

        self::assertSame(413, $event->getResponse()?->getStatusCode());
        self::assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Request body over 10 bytes. Nothing was charged.']], json_decode((string) $event->getResponse()->getContent(), true));
    }

    public function testADeclaredOversizeIsRefusedAndAShortHeaderDoesNotHideTheBody(): void
    {
        self::assertSame(413, $this->answer('small', declared: '11'));
        self::assertSame(413, $this->answer(str_repeat('a', 11), declared: '5'));
    }

    public function testOnlyAPostToTheMcpRouteIsLooked(): void
    {
        self::assertNull($this->answer(str_repeat('a', 11), 'PUT'));
        self::assertNull($this->answer(str_repeat('a', 11), route: '_preview_error'));
        self::assertNull($this->answer(str_repeat('a', 11), route: null));
    }

    public function testTheLargestAnalyzeTermsCallFitsTheDefault(): void
    {
        // Every character escaped as \uXXXX, plus a payment's worth of _meta.
        $text = str_repeat('é', \App\Terms\TermsAnalyzer::MAX_CHARS);
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'analyze_terms_start', 'arguments' => ['text' => $text], '_meta' => ['x402/payment' => str_repeat('x', 4096)]]]);

        self::assertLessThan(131072, \strlen((string) $body));
    }
}
