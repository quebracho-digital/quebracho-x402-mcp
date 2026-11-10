<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Re-export of library's JobIdOnRefusal in App\X402 namespace for DI compatibility.
 *
 * Identical to Quebracho\X402\Job\JobIdOnRefusal - same logic, just in our namespace.
 */
final class JobIdOnRefusal implements MiddlewareInterface
{
    public function __construct(
        private readonly JobDeposit $deposit,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getBody();
        $message = json_decode((string) $body, true);
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $response = $handler->handle($request);
        if (!\is_array($message) || 'tools/call' !== ($message['method'] ?? null) || !\is_array($message['params'] ?? null)
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json') || !$response->getBody()->isSeekable()) {
            return $response;
        }
        $out = json_decode((string) $response->getBody(), true);
        $response->getBody()->rewind();
        $result = \is_array($out) ? ($out['result'] ?? null) : null;
        $required = \is_array($result) ? ($result['structuredContent'] ?? null) : null;
        if (!\is_array($out) || !\is_array($result) || true !== ($result['isError'] ?? null) || !\is_array($required) || !isset($required['x402Version'], $required['error'])) {
            return $response;
        }
        $jobId = $this->deposit->jobIdFor($message['params']);
        if (null === $jobId) {
            return $response;
        }
        $required['jobId'] = $jobId;
        $result['structuredContent'] = $required;
        $result['content'] = [['type' => 'text', 'text' => json_encode($required, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)]];
        $out['result'] = $result;

        return $response->withBody($this->streams->createStream(json_encode($out, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)));
    }
}
