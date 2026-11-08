<?php

declare(strict_types=1);

namespace App\X402;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;

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
     * The catalog of what McpPass registered: each builder addTool call's arguments,
     * [[class, method], name, ...]. Fails closed: a served tool missing from the
     * catalog would be priced as free.
     *
     * @param iterable<array<int, mixed>> $calls
     *
     * @return array<string, array{job: ?array{role: string, pairedWith: string, price: ?string}, schema: string}>
     */
    public static function served(iterable $calls): array
    {
        $handlers = [];
        $names = [];
        foreach ($calls as $args) {
            [$class, $method] = \is_array($handler = $args[0] ?? null) ? [$handler[0] ?? null, $handler[1] ?? null] : [null, null];
            $name = $args[1] ?? null;
            if (!\is_string($class) || !class_exists($class) || !\is_string($method) || (null !== $name && !\is_string($name))) {
                throw new \LogicException('An MCP addTool call that is not [[class, method], name]: '.json_encode($args).'.');
            }
            // One tool on two servers is one handler.
            $handlers["$class::$method"] = [$class, $method];
            $names[] = $name;
        }
        $tools = self::catalog(array_values($handlers));
        foreach ($names as $name) {
            if (null !== $name && !isset($tools[$name])) {
                throw new \LogicException("MCP tool $name is served but not in app.mcp_tools: Pricing would treat it as free.");
            }
        }

        return $tools;
    }

    /**
     * Every tool given, by name: its job flag (or NULL) and its input schema as
     * the SDK generates it (JSON, for the validate hook). Run at container compile
     * time (App\Kernel) over the tools the MCP servers serve, so a mistake stops the build.
     *
     * @param iterable<array{0: class-string, 1: string}> $handlers [class, method] of each served #[McpTool]
     *
     * @return array<string, array{job: ?array{role: string, pairedWith: string, price: ?string}, schema: string}>
     */
    public static function catalog(iterable $handlers): array
    {
        $tools = [];
        $schemas = new SchemaGenerator(new DocBlockParser());
        foreach ($handlers as [$class, $method]) {
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
            // The schema the SDK checks a call against (McpPass generates it the same way); JSON keeps {} apart from [].
            $schema = json_encode($schemas->generate($at), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            $tools[$name] = ['job' => null === $job ? null : ['role' => $job->role, 'pairedWith' => $job->pairedWith, 'price' => $job->price], 'schema' => $schema];
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
}
