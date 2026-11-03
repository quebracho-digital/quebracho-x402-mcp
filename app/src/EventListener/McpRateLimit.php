<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /mcp per client IP (behind TRUSTED_PROXIES), before the x402 middleware:
 * a flood reaches no ecrecover, payee screening or facilitator /verify.
 * Nothing ran, so nothing is claimed or charged.
 */
#[AsEventListener(priority: 64)]
final class McpRateLimit
{
    public function __construct(
        #[Target('mcp_ip.limiter')] private readonly RateLimiterFactoryInterface $perClient,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || '/mcp' !== $request->getPathInfo()) {
            return;
        }
        $limit = $this->perClient->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Rate limit reached for /mcp. Nothing was charged; try later.']],
                429,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
            ));
        }
    }
}
