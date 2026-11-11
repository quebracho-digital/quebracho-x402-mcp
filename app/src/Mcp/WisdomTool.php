<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

/**
 * The smallest paid tool. Nothing here knows about x402: the tool is plain MCP code, and it is paid because
 * X402_PAID_TOOLS names it (App\X402\Pricing answers its price to the library's middleware, which stands in
 * front of the MCP server). It runs only for a call whose payment verified, and the payment is settled only
 * after it answered: a tool that throws, or answers isError, is not charged.
 *
 * To sell a tool of your own: write it as any #[McpTool], add its name to X402_PAID_TOOLS, and optionally
 * its own price in X402_PRICES. A tool that needs the payment itself reads it from the request's _meta
 * (X402McpMiddleware::PAYMENT), as App\Mcp\AnalyzeTermsJobTool does.
 */
final class WisdomTool
{
    private const SAYINGS = [
        'The best code is the code never written.',
        'Verify the effect, not the mechanism.',
        'A test that passes does not prove the 402 reached the client.',
    ];

    // Paid: X402_PAID_TOOLS lists it, so tools/call needs an x402 payment.
    #[McpTool(name: 'wisdom', description: 'Returns a paid piece of engineering wisdom (x402, testnet USDC).')]
    // The unused $topic keeps the input schema non-empty: Hugging Face's chat template
    // rejects a tool without `parameters`, and symfony/ai omits them for an empty schema.
    public function __invoke(?string $topic = null): string
    {
        return self::SAYINGS[array_rand(self::SAYINGS)];
    }
}
