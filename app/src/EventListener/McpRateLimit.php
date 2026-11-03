<?php

declare(strict_types=1);

namespace App\EventListener;

use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST to an MCP endpoint, per client (behind TRUSTED_PROXIES) and for the whole
 * server, before the x402 middleware: a flood reaches no ecrecover, payee
 * screening or facilitator /verify. Nothing ran, so nothing is claimed or charged.
 */
// After the router (32): the endpoint is known by its route, so the path set in mcp.yaml is followed.
#[AsEventListener(priority: 31)]
final class McpRateLimit
{
    /** symfony/mcp-bundle names each server's route _mcp_endpoint_<server> (AppKernelTest pins it). */
    public const ROUTE_PREFIX = '_mcp_endpoint_';
    /** Bodies up to this size are read for the JSON-RPC id of the 429; a larger one gets id null. */
    private const ID_BYTES = 8192;

    public function __construct(
        #[Target('mcp_ip.limiter')] private readonly RateLimiterFactoryInterface $perClient,
        // One /48 holds 65,536 /64 buckets: a cap for everybody too.
        #[Target('mcp_global.limiter')] private readonly RateLimiterFactoryInterface $global,
        // ponytail: the default server's host rules only; one listener per server if mcp.yaml gets a second.
        #[Autowire(service: 'mcp.server.default.middleware_factory')] private readonly MiddlewareFactory $middleware,
        #[Autowire(service: 'mcp.psr_http_factory')] private readonly HttpMessageFactoryInterface $psr,
        #[Autowire(service: 'mcp.psr17_factory')] private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !str_starts_with((string) $request->attributes->get('_route'), self::ROUTE_PREFIX)) {
            return;
        }
        // The transport answers a foreign Host or Origin with 403 anyway: it must not spend this client's bucket.
        if ($this->forbidden($request)) {
            return;
        }
        $limit = $this->perClient->create(self::client($request->getClientIp()))->consume();
        // Only a client within its own limit counts against everybody's.
        if ($limit->isAccepted()) {
            $limit = $this->global->create('all')->consume();
        }
        if (!$limit->isAccepted()) {
            $event->setResponse($this->refuse($request, $limit));
        }
    }

    /**
     * The bucket: an IPv4 address as is, an IPv6 one by its /64 (one host usually holds the whole /64).
     */
    public static function client(?string $ip): string
    {
        return null === $ip ? 'unknown' : IpUtils::anonymize($ip, 0, 8);
    }

    private function forbidden(Request $request): bool
    {
        $ok = new class($this->responses) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactoryInterface $responses)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responses->createResponse(200);
            }
        };
        foreach ($this->middleware->create() ?? StreamableHttpTransport::defaultMiddleware() as $m) {
            if ($m instanceof DnsRebindingProtectionMiddleware && 200 !== $m->process($this->psr->createRequest($request), $ok)->getStatusCode()) {
                return true;
            }
        }

        return false;
    }

    private function refuse(Request $request, RateLimit $limit): JsonResponse
    {
        $body = \strlen($request->getContent()) <= self::ID_BYTES ? json_decode($request->getContent(), true) : null;
        // A batch or a notification has no single id to answer: null, as JSON-RPC says for an unknown id.
        $id = \is_array($body) && (\is_string($body['id'] ?? null) || \is_int($body['id'] ?? null)) ? $body['id'] : null;

        return new JsonResponse(
            ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => 'Rate limit reached for /mcp. Nothing was charged; try later.']],
            429,
            // Exposed so a browser client can read when to retry.
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Access-Control-Expose-Headers' => 'Retry-After'],
        );
    }
}
