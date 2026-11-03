<?php

namespace App;

use App\X402\PaidJob;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * app.mcp_tools: every registered MCP tool by name, with its #[PaidJob] flag
     * (PaidJob::catalog), for Pricing. A job tool without the flag stops the build.
     */
    public function process(ContainerBuilder $container): void
    {
        $handlers = [];
        foreach ($container->findTaggedServiceIds('mcp.tool') as $id => $tags) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            foreach ($tags as $tag) {
                $handlers[] = [$class, $tag['method'] ?? '__invoke'];
            }
        }
        $container->setParameter('app.mcp_tools', PaidJob::catalog($handlers));
    }
}
