<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A POST to an MCP endpoint larger than MCP_MAX_BODY_BYTES is refused with 413, before anything parses it:
 * the rate limit (McpRateLimit reads the JSON for analyze_terms), the transport, the x402 middleware.
 * Nothing ran, so nothing is claimed or charged.
 *
 * The default, 128 KiB, holds the largest call this app takes with room to spare: an analyze_terms text of
 * TermsAnalyzer::MAX_CHARS characters, each escaped as \uXXXX (6 bytes), plus the payment (about 2 KiB).
 * The web server's own limit (nginx client_max_body_size, PHP post_max_size) still applies first.
 */
// After the router (32), before McpRateLimit (30): the endpoint is known by its route.
#[AsEventListener(priority: 31)]
final class McpBodyLimit
{
    public function __construct(
        #[Autowire(env: 'int:MCP_MAX_BODY_BYTES')] private readonly int $maxBytes,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !str_starts_with(McpRateLimit::route($request), McpRateLimit::ROUTE_PREFIX)) {
            return;
        }
        // The header first, so a declared oversize is refused without reading it; then what was really sent
        // (a chunked body has no Content-Length, and a header can lie).
        $declared = $request->headers->get('Content-Length');
        if ((is_numeric($declared) && (int) $declared > $this->maxBytes) || \strlen($request->getContent()) > $this->maxBytes) {
            $event->setResponse(new JsonResponse(
                // Not read, so no id to answer: null, as JSON-RPC says for an unknown id.
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => "Request body over {$this->maxBytes} bytes. Nothing was charged."]],
                413,
            ));
        }
    }
}
