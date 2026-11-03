<?php

declare(strict_types=1);

namespace App\Tests;

use App\EventListener\McpRateLimit;
use App\X402\Pricing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
}
