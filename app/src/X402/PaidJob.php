<?php

declare(strict_types=1);

namespace App\X402;

use Mcp\Capability\Attribute\McpTool;

/**
 * Marks an MCP tool as one half of a paid job: `start` (the deposit, settled
 * before the tool runs) or `result` (the rest, settled after it delivers).
 * The flag, not the name, makes it a job tool: Pricing always charges it,
 * listed in X402_PAID_TOOLS or not. Publish it in the tool's _meta too:
 * #[McpTool(meta: [PaidJob::META => ['role' => ..., 'pairedWith' => ...]])].
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class PaidJob
{
    public const META = 'x402/job';

    /**
     * @param string  $role       start or result
     * @param string  $pairedWith the other half's tool name
     * @param ?string $price      USD; default: X402_PRICES, then X402_PRICE
     */
    public function __construct(
        public readonly string $role,
        public readonly string $pairedWith,
        public readonly ?string $price = null,
    ) {
        if (!\in_array($role, ['start', 'result'], true)) {
            throw new \LogicException("#[PaidJob] role must be start or result, not $role.");
        }
    }

    /**
     * Every tool the server serves, by name: its job flag (or NULL) and the
     * arguments it cannot run without. Run at container compile time (App\Kernel)
     * over the registered tools, so a mistake stops the build.
     *
     * @param iterable<array{0: class-string, 1: string}> $handlers [class, method] of each #[McpTool]
     * @param list<string>                                $served   the registry's patterns (mcp.yaml): "*", a
     *                                                              namespace ending in "\\", or a class
     *
     * @return array<string, array{job: ?array{role: string, pairedWith: string, price: ?string}, required: list<string>}>
     */
    public static function catalog(iterable $handlers, array $served = ['*']): array
    {
        $tools = [];
        foreach ($handlers as [$class, $method]) {
            if (!self::serves($served, $class)) {
                continue;
            }
            $at = new \ReflectionMethod($class, $method);
            $attrs = $at->getAttributes(McpTool::class) ?: $at->getDeclaringClass()->getAttributes(McpTool::class);
            $jobs = $at->getAttributes(self::class) ?: $at->getDeclaringClass()->getAttributes(self::class);
            if ([] === $attrs) {
                continue;
            }
            $tool = $attrs[0]->newInstance();
            $name = $tool->name ?? throw new \LogicException("$class::$method: give the #[McpTool] a name, Pricing matches tools by it.");
            if (isset($tools[$name])) {
                throw new \LogicException("Two tools are named $name: Pricing would charge one for the other.");
            }
            $job = [] === $jobs ? null : $jobs[0]->newInstance();
            if (null === $job && preg_match('/_(start|result)$/', $name)) {
                throw new \LogicException("Tool $name is named like a job tool but has no #[PaidJob]: add it, or rename the tool.");
            }
            if (null !== $job && ($tool->meta[self::META] ?? null) !== ['role' => $job->role, 'pairedWith' => $job->pairedWith]) {
                throw new \LogicException("Tool $name: publish its #[PaidJob] in #[McpTool(meta: ['".self::META."' => ['role' => '$job->role', 'pairedWith' => '$job->pairedWith']])].");
            }
            if (null !== $job?->price && !preg_match('/^(?!0+(\.0+)?$)\d+(\.\d+)?$/', $job->price)) {
                throw new \LogicException("Tool $name: #[PaidJob] price must be a positive decimal in USD, not \"$job->price\".");
            }
            // What the tool cannot run without; objects (a request context) are injected by the SDK.
            $required = [];
            foreach ($at->getParameters() as $p) {
                $type = $p->getType();
                if (!$p->isOptional() && !($type instanceof \ReflectionNamedType && !$type->isBuiltin())) {
                    $required[] = $p->getName();
                }
            }
            $tools[$name] = ['job' => null === $job ? null : ['role' => $job->role, 'pairedWith' => $job->pairedWith, 'price' => $job->price], 'required' => $required];
        }
        foreach ($tools as $name => ['job' => $job]) {
            if (null === $job) {
                continue;
            }
            $pair = $tools[$job['pairedWith']]['job'] ?? null;
            if (null === $pair || $pair['role'] === $job['role'] || $pair['pairedWith'] !== $name) {
                throw new \LogicException("Tool $name: its pair {$job['pairedWith']} must be a #[PaidJob] with the other role, paired back with it.");
            }
        }

        return $tools;
    }

    /** @param list<string> $served */
    private static function serves(array $served, string $class): bool
    {
        foreach ($served as $pattern) {
            if ('*' === $pattern || $pattern === $class || (str_ends_with($pattern, '\\') && str_starts_with($class, $pattern))) {
                return true;
            }
        }

        return false;
    }
}
