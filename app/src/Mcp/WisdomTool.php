<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

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
