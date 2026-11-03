#!/usr/bin/env bash
# End to end against the DDEV site, x402 in band (transports-v2/mcp.md): free tool,
# paid tool unpaid (isError + PaymentRequired), paid tool paid (result + receipt in _meta).
set -euo pipefail
cd "$(dirname "$0")/.."
U=${MCP_URL:-https://quebracho-x402-mcp.ddev.site/mcp}
c(){ curl -sSk --http1.1 -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$@"; }
S=$(c -D - -o /dev/null "$U" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' | grep -i '^mcp-session-id' | cut -d' ' -f2 | tr -d '\r')
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' -o /dev/null
CALL='{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wisdom","arguments":{}}}'

echo "== echo (free)"
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hello hackathon"}}}'; echo

echo "== wisdom, unpaid"
REQUIRED=$(c -H "Mcp-Session-Id: $S" "$U" -d "$CALL")
echo "$REQUIRED" | cut -c1-160
echo "$REQUIRED" | grep -q '"isError":true' && echo "$REQUIRED" | grep -q '"x402Version":2' || { echo "FAIL: no PaymentRequired"; exit 1; }

echo "== wisdom, paid"
PAID=$(printf '%s\n%s\n' "$REQUIRED" "$CALL" | ddev exec -s web php bin/pay.php)
c -H "Mcp-Session-Id: $S" "$U" -d "$PAID" | tee /dev/stderr | grep -q '"x402/payment-response":{"success":true' && echo && echo OK
