<?php

// Pays an MCP tool call in band (x402 transports-v2/mcp.md). Reads two lines
// on stdin: the tool result carrying the PaymentRequired, then the original
// tools/call. Prints the call again with the payment in
// params._meta["x402/payment"]. Run inside DDEV: ddev exec php bin/pay.php
declare(strict_types=1);

require __DIR__ . '/../packages/x402/vendor/autoload.php';

use Quebracho\X402\EvmExact;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;

// Default: the unfunded web3.js docs key, enough for mock mode.
$key = getenv('X402_BUYER_KEY') ?: '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
$required = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR)['result']['structuredContent'];
if (!in_array($required['accepts'][0]['network'] ?? '', ['eip155:84532', 'eip155:5042002'], true)) {
    fwrite(STDERR, "pay.php signs on testnets only; use app:ask, which has caps and an allowlist.\n");
    exit(1);
}
$call = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$call['params']['_meta'][X402McpMiddleware::PAYMENT] = (new EvmExact(new Signer($key)))->pay($required, $required['accepts'][0]);
echo json_encode($call, JSON_UNESCAPED_SLASHES);
