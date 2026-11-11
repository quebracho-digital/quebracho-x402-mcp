<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

/**
 * A free tool: not in X402_PAID_TOOLS, so App\X402\Pricing answers null and the x402 middleware lets the
 * call through without asking for a payment. Free and paid tools live on the same server.
 */
final class EchoTool
{
    // ponytail: free tool to prove the MCP wiring; paid tools come with the x402 middleware.
    #[McpTool(name: 'echo', description: 'Returns the given text unchanged.')]
    public function __invoke(string $text): string
    {
        return $text;
    }
}
