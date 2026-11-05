<?php

declare(strict_types=1);

namespace App\Tests;

use App\EventListener\McpRateLimit;
use App\Job\FileJobStore;
use App\Job\JobRunner;
use App\Job\JobStore;
use App\Kernel;
use App\X402\Facilitator;
use App\X402\JobDesk;
use App\X402\Pricing;
use App\X402\ReceiptLookup;
use Psr\Cache\CacheItemPoolInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingMcpClient;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The committed app/.env, through the real container: Pricing is only built on
 * the first /mcp request, so a bad X402_TIMEOUTS or X402_DURATIONS shows up here.
 */
final class AppKernelTest extends KernelTestCase
{
    public function testTheCommittedEnvBuildsPricing(): void
    {
        $pricing = self::getContainer()->get(Pricing::class);
        self::assertInstanceOf(Pricing::class, $pricing);

        self::assertSame(120, $pricing(['name' => 'analyze_terms'])[0]['maxTimeoutSeconds']);
        self::assertSame(60, $pricing->duration(['name' => 'analyze_terms']));
        self::assertSame(60, $pricing(['name' => 'wisdom'])[0]['maxTimeoutSeconds']);
        self::assertSame(0, $pricing->duration(['name' => 'wisdom']));
    }

    public function testTheReceiptLookupHasItsOwnTimeoutAndTheMemoIsAPrunablePool(): void
    {
        // ReceiptLookup's RPC client: 10 s, not the framework default (no timeout).
        $http = self::getContainer()->get('app.receipt_http_client');
        $options = (new \ReflectionProperty($http, 'defaultOptions'))->getValue($http);
        self::assertSame(10.0, (float) $options['timeout']);
        self::assertSame(10.0, (float) $options['max_duration']);
        $lookup = (new \ReflectionProperty(ReceiptLookup::class, 'http'))->getValue(self::getContainer()->get(ReceiptLookup::class));
        self::assertSame($http, (new \ReflectionProperty(Psr18Client::class, 'client'))->getValue($lookup));

        // The memo is a cache.pool: cache:pool:list shows it and it keeps the 7-day default lifetime.
        $pool = self::getContainer()->get('terms.cache');
        self::assertSame(604800, (new \ReflectionProperty(AbstractAdapter::class, 'defaultLifetime'))->getValue($pool));
        $list = new CommandTester((new Application(self::bootKernel()))->find('cache:pool:list'));
        $list->execute([]);
        self::assertStringContainsString('terms.cache', $list->getDisplay());
        $prune = new CommandTester((new Application(self::bootKernel()))->find('cache:pool:prune'));
        self::assertSame(0, $prune->execute([]));
        self::assertStringContainsString('Pruning cache pool: terms.cache', $prune->getDisplay());
    }

    public function testTheRateLimitSeesTheMcpRouteAndOnlyIt(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        // Only POST is limited; a GET to /mcp/ would be redirected, a POST is not.
        $router->getContext()->setMethod('POST');
        $path = $router->getRouteCollection()->get(McpRateLimit::ROUTE_PREFIX.'default')?->getPath();
        self::assertNotNull($path, 'symfony/mcp-bundle still names its route _mcp_endpoint_<server>');
        self::assertStringStartsWith(McpRateLimit::ROUTE_PREFIX, $router->match($path)['_route']);

        foreach ([$path.'x', $path.'/'] as $other) {
            try {
                self::assertStringStartsNotWith(McpRateLimit::ROUTE_PREFIX, $router->match($other)['_route'], $other);
            } catch (ResourceNotFoundException) {
                // Not routed at all: not counted either.
            }
        }
    }

    public function testTheMiddlewareHasTheDurationAndValidateHooksWired(): void
    {
        $middleware = self::getContainer()->get(X402McpMiddleware::class);
        $hook = static fn (string $name) => (new \ReflectionProperty(X402McpMiddleware::class, $name))->getValue($middleware);

        self::assertSame(60, $hook('duration')(['name' => 'analyze_terms']));
        self::assertSame(0, $hook('duration')(['name' => 'wisdom']));
        self::assertStringStartsWith("Invalid parameters for tool 'analyze_terms'", (string) $hook('validate')(['name' => 'analyze_terms', 'arguments' => []]));
        self::assertNull($hook('validate')(['name' => 'analyze_terms', 'arguments' => ['text' => 'x']]));
    }

    public function testTheCatalogHoldsExactlyWhatTheServerServes(): void
    {
        $tools = self::getContainer()->getParameter('app.mcp_tools');
        \assert(\is_array($tools));
        // The test env serves JobToolsFixture under the id App\Mcp\AliasedJobs (the registry
        // matches the id, not the class) and tags InvokableJobStartFixture outside the registry.
        self::assertEqualsCanonicalizing(['analyze_terms', 'analyze_terms_start', 'analyze_terms_result', 'analyze_terms_status', 'echo', 'ombu_search', 'wisdom', 'demo_start', 'demo_result', 'demo_status'], array_keys($tools));
        self::assertSame(['text'], json_decode($tools['analyze_terms']['schema'], true)['required']);
        self::assertStringContainsString('100%percent%', $tools['demo_start']['schema'], 'a "%name%" survives the container');

        // Served by id, so priced: before, a #[PaidJob] tool served this way was free.
        $start = self::getContainer()->get(Pricing::class)(['name' => 'demo_start']);
        self::assertSame('upfront', $start[0]['extra']['paymentFlow'] ?? null);
    }

    public function testTheBuyerSignsNoLongerThanX402BuyerMaxTimeout(): void
    {
        $client = self::getContainer()->get(PayingMcpClient::class);
        $budget = (new \ReflectionProperty(PayingMcpClient::class, 'budget'))->getValue($client);
        \assert($budget instanceof Budget);
        $offer = static fn (int $timeout) => Networks::requirements('eip155:84532', '0.01', '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794', $timeout);

        self::assertSame('170', $_SERVER['X402_BUYER_MAX_TIMEOUT'] ?? null, 'the committed default');
        self::assertTrue($budget->allows($offer(170)));
        self::assertFalse($budget->allows($offer(171)));
    }

    public function testOnePostOverMcpLimitIpThroughTheRealKernelIs429(): void
    {
        $kernel = self::bootKernel();
        // The test env keeps limiter state (var/share/test) between runs: start from empty,
        // or a re-run within a minute meets the shared mcp_global bucket half spent.
        $pool = self::getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
        $ip = '198.18.'.random_int(0, 255).'.'.random_int(1, 254);
        $send = static fn () => $kernel->handle(Request::create('http://localhost/mcp', 'POST', server: ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":9,"method":"ping"}'));

        $limit = (int) $_SERVER['MCP_LIMIT_IP'];
        for ($i = 0; $i < $limit; ++$i) {
            self::assertNotSame(429, $send()->getStatusCode(), "request $i is within the limit");
        }
        $refused = $send();
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame(9, json_decode((string) $refused->getContent(), true)['id']);
    }

    public function testAPaidAnalyzeTermsOverTermsLimitIpThroughTheRealKernelIs429(): void
    {
        $kernel = self::bootKernel();
        $pool = self::getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
        $ip = '198.18.'.random_int(0, 255).'.'.random_int(1, 254);
        // A payment for no offer we make: the middleware refuses it in band with no verify, so nothing leaves the host.
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
            'name' => 'analyze_terms', 'arguments' => ['text' => 'We may suspend your account.'],
            '_meta' => [X402McpMiddleware::PAYMENT => ['x402Version' => 2, 'accepted' => ['scheme' => 'none', 'network' => 'none']]],
        ]], \JSON_THROW_ON_ERROR);
        $send = static fn () => $kernel->handle(Request::create('http://localhost/mcp', 'POST', server: ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], content: $body));

        $limit = (int) $_SERVER['TERMS_LIMIT_IP'];
        for ($i = 0; $i < $limit; ++$i) {
            self::assertNotSame(429, $send()->getStatusCode(), "call $i is within the limit");
        }
        $refused = $send();
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('Rate limit reached for analyze_terms. Nothing was charged; try later.', json_decode((string) $refused->getContent(), true)['error']['message']);
    }

    public function testJobModeIsWiredThroughJobDesk(): void
    {
        $container = self::getContainer();
        $desk = $container->get(JobDesk::class);
        // Every job hook on the middleware is JobDesk's: one missing and a _start would settle with no row
        // written first, or a _result be sold to anyone.
        $middleware = $container->get(X402McpMiddleware::class);
        foreach (['settle', 'validate', 'payer', 'sensitive'] as $hook) {
            $closure = (new \ReflectionProperty(X402McpMiddleware::class, $hook))->getValue($middleware);
            \assert($closure instanceof \Closure);
            $fn = new \ReflectionFunction($closure);
            self::assertSame([$desk, $hook], [$fn->getClosureThis(), $fn->getName()], $hook);
        }
        // And JobDesk settles with the facilitator.
        $settle = (new \ReflectionProperty(JobDesk::class, 'settle'))->getValue($desk);
        \assert($settle instanceof \Closure);
        self::assertInstanceOf(Facilitator::class, (new \ReflectionFunction($settle))->getClosureThis());

        // Decision 9: jobs are files outside the cache, which cache:clear and a deploy wipe.
        $dir = (string) (new \ReflectionProperty(FileJobStore::class, 'dir'))->getValue($container->get(JobStore::class));
        $cache = (string) $container->getParameter('kernel.cache_dir');
        self::assertStringStartsNotWith(\dirname($cache), $dir, 'not under var/cache');
        self::assertStringEndsWith('/var/jobs', $dir);

        // Priced by their #[PaidJob]: the deposit upfront, the rest after; the status free.
        $pricing = $container->get(Pricing::class);
        self::assertSame(['10000', 'upfront'], [$pricing(['name' => 'analyze_terms_start'])[0]['amount'], $pricing(['name' => 'analyze_terms_start'])[0]['extra']['paymentFlow']]);
        self::assertSame(['10000', null], [$pricing(['name' => 'analyze_terms_result'])[0]['amount'], $pricing(['name' => 'analyze_terms_result'])[0]['extra']['paymentFlow'] ?? null]);
        self::assertNull($pricing(['name' => 'analyze_terms_status']));
        self::assertNotNull($container->get(JobRunner::class)->work('analyze_terms_start'), 'its work is tagged');
        self::assertArrayNotHasKey('context', json_decode($container->getParameter('app.mcp_tools')['analyze_terms_start']['schema'], true)['properties'], 'the RequestContext is injected, not asked of the buyer');

        // Registered for cron; not run here: the test env shares var/jobs with dev.
        self::assertTrue((new Application(self::bootKernel()))->has('app:jobs:janitor'));
    }

    public function testAnOptionalBundleIsRegisteredOnlyWhenItsClassExists(): void
    {
        // The app runs without symfony/monolog-bundle (suggested): a missing class yields nothing, no error.
        self::assertSame([], iterator_to_array(Kernel::optionalBundles(['Not\\Installed\\Bundle'])));
        self::assertCount(1, iterator_to_array(Kernel::optionalBundles([\Symfony\Bundle\MonologBundle\MonologBundle::class])));
    }
}
