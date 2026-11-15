<?php

declare(strict_types=1);

namespace App\X402;

use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * X402McpMiddleware's $validate (through Quebracho\X402\Job\JobDesk, services.yaml): why a tools/call must not
 * be sold at all, asked before any payment is read. Here: the call's arguments against its tool's input
 * schema, with the MCP SDK's own validator and the way its CallToolHandler calls it. A call the tool would
 * refuse is never charged, an upfront (_start) deposit included.
 *
 * The library takes any fn(array $params): ?string here: NULL sells the call, a string is the JSON-RPC -32602
 * message the buyer gets, with nothing claimed or charged. What the check is belongs to the application.
 */
final class ToolInput
{
    /**
     * @param array<string, array{job: ?array<string, mixed>, schema: string}> $tools every served tool by name (PaidJob::catalog)
     */
    public function __construct(#[Autowire(param: 'app.mcp_tools')] private readonly array $tools)
    {
    }

    /**
     * @param array{name: string, arguments?: array<string, mixed>} $params the tools/call params
     */
    public function __invoke(array $params): ?string
    {
        $args = $params['arguments'] ?? [];
        $schema = $this->tools[$params['name']]['schema'] ?? null;
        if (null === $schema) {
            // ponytail: unreachable, PriceList refuses a paid name that is no served tool; fail closed anyway.
            return "Unknown tool: {$params['name']}";
        }
        $decoded = json_decode($schema, false, 512, \JSON_THROW_ON_ERROR);
        if (!\is_object($decoded)) {
            // ponytail: unreachable, the SDK generates an object schema; fail closed anyway.
            return "Tool {$params['name']} has no input schema.";
        }
        $errors = (new SchemaValidator())->validateAgainstJsonSchema([] === $args ? new \stdClass() : $args, $decoded);

        return [] === $errors ? null : "Invalid parameters for tool '{$params['name']}': ".implode('; ', array_map(static fn (array $e) => ('' !== $e['pointer'] ? $e['pointer'].': ' : '').$e['message'], $errors));
    }
}
