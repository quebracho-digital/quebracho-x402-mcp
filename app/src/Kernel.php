<?php

namespace App;

use App\X402\PaidJob;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait {
        registerBundles as private registerTraitBundles;
    }

    /**
     * config/bundles.php, plus the optional bundles that are installed (symfony/monolog-bundle is suggested, not required).
     *
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield from $this->registerTraitBundles();
        yield from self::optionalBundles([\Symfony\Bundle\MonologBundle\MonologBundle::class]);
    }

    /**
     * @param list<class-string<BundleInterface>> $classes
     *
     * @return \Generator<BundleInterface>
     */
    public static function optionalBundles(array $classes): \Generator
    {
        foreach ($classes as $class) {
            if (class_exists($class)) {
                yield new $class();
            }
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * app.mcp_tools: every tool an MCP server serves, by name, with its #[PaidJob]
     * flag and input schema (PaidJob::catalog), for Pricing. Read from the tools
     * McpPass registered on each server builder (it ran before: this pass is at
     * -10000), so a service id, an alias or a class the registry matches is
     * counted exactly as the bundle serves it.
     */
    public function process(ContainerBuilder $container): void
    {
        $calls = [];
        $servers = $container->getParameter('mcp.servers.elements');
        foreach (\is_array($servers) ? array_keys($servers) : [] as $server) {
            foreach ($container->getDefinition("mcp.server.$server.builder")->getMethodCalls() as [$call, $args]) {
                if ('addTool' === $call) {
                    $calls[] = $args;
                }
            }
        }
        $tools = PaidJob::served($calls);
        // A "%" in a schema (a description) would read as a parameter placeholder.
        array_walk_recursive($tools, static function (mixed &$v): void {
            $v = \is_string($v) ? str_replace('%', '%%', $v) : $v;
        });
        $container->setParameter('app.mcp_tools', $tools);
    }
}
