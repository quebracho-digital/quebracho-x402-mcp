<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST to an MCP endpoint, per client (behind TRUSTED_PROXIES), before the x402
 * middleware: a flood reaches no ecrecover, payee screening or facilitator
 * /verify. Nothing ran, so nothing is claimed or charged.
 */
// After the router (32): the endpoint is known by its route, so the path set in mcp.yaml is followed.
#[AsEventListener(priority: 31)]
final class McpRateLimit
{
    /** symfony/mcp-bundle names each server's route _mcp_endpoint_<server> (McpRateLimitKernelTest pins it). */
    public const ROUTE_PREFIX = '_mcp_endpoint_';
    /** Bodies up to this size are read for the JSON-RPC id of the 429; a larger one gets id null. */
    private const ID_BYTES = 8192;

    public function __construct(
        #[Target('mcp_ip.limiter')] private readonly RateLimiterFactoryInterface $perClient,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !str_starts_with((string) $request->attributes->get('_route'), self::ROUTE_PREFIX)) {
            return;
        }
        $limit = $this->perClient->create(self::client($request->getClientIp()))->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $body = \strlen($request->getContent()) <= self::ID_BYTES ? json_decode($request->getContent(), true) : null;
        // A batch or a notification has no single id to answer: null, as JSON-RPC says for an unknown id.
        $id = \is_array($body) && (\is_string($body['id'] ?? null) || \is_int($body['id'] ?? null)) ? $body['id'] : null;
        $event->setResponse(new JsonResponse(
            ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => 'Rate limit reached for /mcp. Nothing was charged; try later.']],
            429,
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
        ));
    }

    /**
     * The bucket: an IPv4 address as is, an IPv6 one by its /64 (one host usually holds the whole /64).
     */
    public static function client(?string $ip): string
    {
        return null === $ip ? 'unknown' : IpUtils::anonymize($ip, 0, 8);
    }
}
