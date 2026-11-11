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
            // After CORS and DNS rebinding protection: a list replaces the SDK defaults. JobIdOnRefusal wraps the
            // x402 middleware: it adds the jobId to a `_start` refusal once the job row exists.
            middleware: [...($this->middlewareFactory->create() ?? StreamableHttpTransport::defaultMiddleware()), $this->jobIds, $this->x402],
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === strtolower($psrResponse->getHeaderLine('Content-Type'));

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}
