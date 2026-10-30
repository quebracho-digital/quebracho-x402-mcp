#!/usr/bin/env python3
"""Third-party-style MCP client (stdlib only): lists tools and calls the paid one unpaid.
Usage: bin/inspect.py [url]   (default: the DDEV endpoint)"""
import json, ssl, sys, urllib.request

URL = sys.argv[1] if len(sys.argv) > 1 else "https://quebracho-x402-mcp.ddev.site/mcp"
CTX = ssl.create_default_context()
CTX.check_hostname, CTX.verify_mode = False, ssl.CERT_NONE  # DDEV's local CA
session = None

def rpc(method, params=None, id=None):
    global session
    body = {"jsonrpc": "2.0", "method": method, **({"params": params} if params else {}), **({"id": id} if id else {})}
    headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
    if session:
        headers["Mcp-Session-Id"] = session
    with urllib.request.urlopen(urllib.request.Request(URL, json.dumps(body).encode(), headers), context=CTX) as r:
        session = r.headers.get("Mcp-Session-Id") or session
        raw = r.read().decode()
    if not id:
        return None
    # Streamable HTTP may answer as SSE ("data: {...}") or plain JSON.
    data = next((l[5:] for l in raw.splitlines() if l.startswith("data:")), raw)
    return json.loads(data)["result"]

info = rpc("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "inspect.py", "version": "1"}}, 1)
assert session, "no Mcp-Session-Id"
rpc("notifications/initialized")
print("server:", info["serverInfo"])
tools = rpc("tools/list", id=2)["tools"]
print("tools:", [t["name"] for t in tools])
assert all(t["inputSchema"].get("properties") for t in tools if t["name"] == "wisdom"), "wisdom needs a non-empty schema"
free = rpc("tools/call", {"name": "echo", "arguments": {"text": "hi"}}, 3)
assert not free["isError"], free
print("echo (free):", free["content"][0]["text"])
unpaid = rpc("tools/call", {"name": "wisdom", "arguments": {}}, 4)
req = unpaid["structuredContent"]
assert unpaid["isError"] and req["x402Version"] == 2, unpaid
a = req["accepts"][0]
print(f"wisdom (unpaid): isError, PaymentRequired {a['amount']} of {a['asset']} on {a['network']} -> {a['payTo']}")
print("OK")
