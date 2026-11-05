<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Mcp\AnalyzeTermsTool;
use App\Terms\TermsAnalyzer;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\X402McpMiddleware;
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
 * A paid analyze_terms call also spends its client's hourly bucket (TERMS_LIMIT_IP) here, before
 * its payment is verified; the server's daily one stays in the tool (AnalyzeTermsTool).
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
        // analyze_terms spends Hugging Face credit: per client per hour, for the calls that carry a payment.
        #[Target('terms_ip.limiter')] private readonly RateLimiterFactoryInterface $terms,
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
        $client = self::client($request->getClientIp());
        $limit = $this->perClient->create($client)->consume();
        // Only a client within its own limit counts against everybody's.
        if ($limit->isAccepted()) {
            $limit = $this->global->create('all')->consume();
        }
        $what = '/mcp';
        // Last: a call the limits above refuse does not spend the client's few analyze_terms calls.
        if ($limit->isAccepted() && self::paidTerms($request)) {
            $limit = $this->terms->create($client)->consume();
            $what = AnalyzeTermsTool::NAME;
        }
        if (!$limit->isAccepted()) {
            $event->setResponse($this->refuse($request, $limit, $what));
        }
    }

    /**
     * A tools/call of analyze_terms that carries a payment (what X402McpMiddleware would verify) and a text the
     * tool would take. Unpaid, the middleware only answers with the price; a bad text is refused by the tool,
     * charged nothing, and must not use up the client's limit either.
     */
    private static function paidTerms(Request $request): bool
    {
        $m = json_decode($request->getContent(), true);
        if (!\is_array($m) || 'tools/call' !== ($m['method'] ?? null) || AnalyzeTermsTool::NAME !== ($m['params']['name'] ?? null)
          || !\is_array($m['params']['_meta'][X402McpMiddleware::PAYMENT] ?? null) || !\is_string($text = $m['params']['arguments']['text'] ?? null)) {
            return false;
        }
        try {
            TermsAnalyzer::check($text);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
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

    private function refuse(Request $request, RateLimit $limit, string $what): JsonResponse
    {
        $body = \strlen($request->getContent()) <= self::ID_BYTES ? json_decode($request->getContent(), true) : null;
        // A batch or a notification has no single id to answer: null, as JSON-RPC says for an unknown id.
        $id = \is_array($body) && (\is_string($body['id'] ?? null) || \is_int($body['id'] ?? null)) ? $body['id'] : null;

        return new JsonResponse(
            ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => "Rate limit reached for $what. Nothing was charged; try later."]],
            429,
            // Exposed so a browser client can read when to retry.
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Access-Control-Expose-Headers' => 'Retry-After'],
        );
    }
}
