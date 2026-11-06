<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\EvmExact;
use Quebracho\X402\Signer;

/**
 * The one EvmExact of the container, shared by the seller (X402McpMiddleware,
 * app:x402:check, NetworkWarmer) and the buyer (PayingMcpClient, app:ask).
 *
 * Verifying needs no key; paying needs X402_BUYER_KEY. An empty key gives a
 * scheme that verifies and refuses to pay (EvmExact::pay throws), so a host
 * that only sells boots without a buyer key. A key that is set but malformed
 * still fails at boot (Signer), never at the first payment.
 */
final class SchemeFactory
{
    public static function evmExact(#[\SensitiveParameter] string $buyerKey): EvmExact
    {
        return new EvmExact('' === $buyerKey ? null : new Signer($buyerKey));
    }
}
