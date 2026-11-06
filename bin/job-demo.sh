#!/usr/bin/env bash
# Job mode end to end (docs/job-mode.md): analyze_terms_start (a deposit, settled before the tool runs),
# analyze_terms_status until ready, analyze_terms_result (the rest, settled after). x402 in band over MCP.
#
# Runs its own PHP server inside the DDEV web container with X402_FACILITATOR=mock, whatever
# app/.env.local says: nothing is sent to a facilitator, nothing moves on chain. The work does call the
# model once (Hugging Face, HF_TOKEN in app/.env.local); the same text is answered from the 7-day memo.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -d /var/www/html ]; then exec ddev exec -s web bash bin/job-demo.sh "$@"; fi

PORT=${DEMO_PORT:-8099}
U="http://localhost:$PORT/mcp"
# bin/pay.php signs with X402_BUYER_KEY, or the unfunded web3.js docs key: enough for the mock.
TEXT=${DEMO_TEXT:-We may change these terms at any time without notice. Any dispute will be settled by binding arbitration, and you waive any class action.}

# The real environment wins over app/.env*: this server settles with the mock. The MCP server checks the
# Host header, so the calls go to localhost (allowed in config/packages/mcp.yaml), not 127.0.0.1.
# php -S has no fastcgi_finish_request: the work may run before the _start's request ends, so the
# first status can already be ready; under PHP-FPM it goes through queued and running.
X402_FACILITATOR=mock php -S 127.0.0.1:"$PORT" -t app/public app/public/index.php >/tmp/job-demo-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for _ in $(seq 20); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.3; done

c(){ curl -sS -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$@"; }
S=$(c -D - -o /dev/null "$U" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"job-demo","version":"1"}}}' | grep -i '^mcp-session-id' | cut -d' ' -f2 | tr -d '\r')
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' -o /dev/null
call(){ c -H "Mcp-Session-Id: $S" "$U" -d "$1"; }
# A field of the tool's answer (the JSON in its first text content), or of its structuredContent.
field(){ php -r '$r = json_decode(stream_get_contents(STDIN), true)["result"] ?? []; $a = $r["structuredContent"] ?? json_decode($r["content"][0]["text"] ?? "null", true); $v = $a[$argv[1]] ?? null; echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }
# Pays a tool call: the unpaid answer (its PaymentRequired), then the call again with the payment.
paid(){ local required; required=$(call "$1"); printf '%s\n%s\n' "$required" "$1" | php bin/pay.php; }
tool(){ php -r 'echo json_encode(["jsonrpc" => "2.0", "id" => 2, "method" => "tools/call", "params" => ["name" => $argv[1], "arguments" => json_decode($argv[2], true)]], JSON_UNESCAPED_SLASHES);' "$1" "$2"; }

START=$(tool analyze_terms_start "$(php -r 'echo json_encode(["text" => $argv[1]]);' "$TEXT")")
echo "== analyze_terms_start, unpaid: the deposit's PaymentRequired"
call "$START" | php -r '$a = json_decode(stream_get_contents(STDIN), true)["result"]["structuredContent"]["accepts"][0]; echo "  {$a["amount"]} of {$a["asset"]} on {$a["network"]}, paymentFlow: ", $a["extra"]["paymentFlow"] ?? "default", "\n";'

echo "== analyze_terms_start, paid: settled before the tool runs, answers a jobId"
ANSWER=$(call "$(paid "$START")")
JOB=$(echo "$ANSWER" | field jobId)
# Who paid, from the deposit's receipt: _status answers only that address.
PAYER=$(echo "$ANSWER" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["result"]["_meta"]["x402/payment-response"]["payer"] ?? "";')
[ -n "$JOB" ] || { echo "FAIL: no jobId: $ANSWER"; exit 1; }
echo "  jobId $JOB, state $(echo "$ANSWER" | field state)"

echo "== analyze_terms_status (free), until the job is no longer moving"
STATUS=$(tool analyze_terms_status "{\"jobId\":\"$JOB\",\"payer\":\"$PAYER\"}")
for _ in $(seq 60); do
  NOW=$(call "$STATUS")
  STATE=$(echo "$NOW" | field state)
  echo "  $STATE ($(echo "$NOW" | field reason))"
  [ "$(echo "$NOW" | field retryable)" = true ] || break
  sleep 2
done
[ "$STATE" = ready ] || { echo "FAIL: the job ended $STATE"; exit 1; }

RESULT=$(tool analyze_terms_result "{\"jobId\":\"$JOB\"}")
echo "== analyze_terms_result, paid: the tool answers, then the payment settles"
OUT=$(call "$(paid "$RESULT")")
echo "$OUT" | grep -q '"x402/payment-response":{"success":true' || { echo "FAIL: $OUT"; exit 1; }
# The mock's receipt says so: a run that reached a real facilitator stops here.
echo "$OUT" | grep -q '"mock":true' || { echo "FAIL: the receipt is not the mock's"; exit 1; }
echo "$OUT" | php -r '$r = json_decode(stream_get_contents(STDIN), true)["result"]; $a = json_decode($r["content"][0]["text"], true); echo "  categories: ", implode(", ", array_keys($a["categories"] ?? [])) ?: "none", "\n";'
echo "  state $(call "$STATUS" | field state)"

echo OK
