<?php

declare(strict_types=1);

namespace App\Controller;

use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\Job\JobIdOnRefusal;
use Quebracho\X402\X402McpMiddleware;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bundle's McpController plus the x402 middleware, which its final
 * MiddlewareFactory cannot take. Replaces mcp.server.default.controller.
 *
 * This is where the library meets the MCP server. X402McpMiddleware is a PSR-15 middleware, and the MCP SDK's
 * HTTP transport takes a list of them, so charging for tools is one more entry in that list: no tool, handler
 * or SDK class is changed. The middleware reads each JSON-RPC message; a tools/call of a tool with a price
 * (Quebracho\X402\PriceList) and no payment is answered with the x402 PaymentRequired, one with a payment is verified,
 * handed on to the server, and settled once the tool answered. Everything else (initialize, tools/list, free
 * tools) passes through untouched.
 *
 * In an application that is not MCP the same place takes Quebracho\X402\X402Middleware, which charges plain
 * HTTP routes (the 402 status and the PAYMENT-* headers) and is built from the same parts.
 */
final class McpController
{
    public function __construct(
        private readonly Server $server,
        private readonly HttpMessageFactoryInterface $httpMessageFactory,
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly MiddlewareFactory $middlewareFactory,
        private readonly X402McpMiddleware $x402,
        private readonly JobIdOnRefusal $jobIds,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $transport = new StreamableHttpTransport(
            $this->httpMessageFactory->createRequest($request),
            $this->responseFactory,
            $this->streamFactory,
            logger: $this->logger,
            // After CORS and DNS rebinding protection: a list replaces the SDK defaults. The x402 middleware goes
            // last, next to the server: a request refused before it (CORS, a bad host) is never read for a payment.
            // JobIdOnRefusal wraps it (job mode only): it adds the jobId to a `_start` refusal once the job row exists.
            middleware: [...($this->middlewareFactory->create() ?? StreamableHttpTransport::defaultMiddleware()), $this->jobIds, $this->x402],
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === strtolower($psrResponse->getHeaderLine('Content-Type'));

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}
