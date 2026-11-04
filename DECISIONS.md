# DECISIONS — quebracho-x402-mcp

One decision per section, numbered in order. The agent proposes, Leo decides.
An accepted decision is never rewritten: if it changes, a new one replaces it.

## D1 — The x402-foundation specs are the norm

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: the canonical x402 repo moved from `coinbase/x402` to `github.com/x402-foundation/x402` (`specs/` plus a TypeScript reference implementation; Go mirrors its layout). Our library had its own error names and formats.
- Decision: where the spec defines something (payload format, §9 error codes, verification rules), we follow the spec. Where it is silent, we follow the TypeScript facilitator.
- Consequences: `EvmExact` returns §9 codes, requires `x402Version` 2 and `extra.name/version`, and allows 6 s of slack on `validBefore`; `Signer::recover` rejects high-s signatures. Official clients understand our rejections.

## D2 — MCP charges in band (`_meta`), not with HTTP 402

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: `specs/transports-v2/mcp.md` defines x402 over MCP: PaymentRequired as an `isError` tool result, the payment in `params._meta["x402/payment"]`, the receipt in `result._meta["x402/payment-response"]`. An official MCP client does not understand an HTTP 402.
- Decision: the MCP server uses `X402McpMiddleware`. `X402Middleware` (HTTP 402) stays in the library for plain web endpoints.
- Consequences: the MCP buyer (the `app:ask` agent) cannot use `PayingHttpClient` as is: it must read `structuredContent` and resend with `_meta`. If settlement fails, the tool output is withheld.

## D3 — Paid tools are not allowed in JSON-RPC batches

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: in a batch, one payment covered several calls and was charged even if some failed. MCP 2025-06-18 removed batching.
- Decision: a batch containing a paid tool call is rejected with JSON-RPC `-32600`.
- Consequences: each call is charged on its own; `Pricing` no longer multiplies the price.

## D4 — NEAR stays experimental, offline, and out of the demo

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: PR #1 (Jules) brought `NearExact`; the official spec `scheme_exact_near.md` also requires RPC checks (`view_access_key`, nonce, FullAccess, balances).
- Decision: `NearExact` follows the spec on payload shape (`{"signedDelegateAction"}`), asset, block window, nonce and gas; ed25519 only (secp256k1 is rejected). The RPC checks belong to the facilitator, not to the offline `verify()`. It is not wired into the app.
- Consequences: a NEAR payment that passes `verify()` can still fail at settlement (foreign key, used nonce). It enters the demo only by a new decision.

## D5 — A payment is claimed once, before the tool runs

- Date: 2026-10-28
- Status: accepted (Leo, via review)
- Context: the x402-reviewer sent one payment 20 times in parallel and got 20 deliveries; the tool runs before settlement, so even a real facilitator (which rejects the spent nonce) would deliver N times and charge once. Mock mode was also the silent default.
- Decision: both middlewares take a required `$claim` (atomic, durable `fn(string $key): bool`) and call it after verify, before the handler. The key is network + payer + nonce for EVM, so re-signing a nonce does not buy a second delivery. `EvmExact` caps `validBefore` at the offer's `maxTimeoutSeconds` (+60 s). The app uses `FileClaims` and requires `X402_FACILITATOR=mock` explicitly.
- Not done, on purpose: binding a payment to a tool name or its arguments. `resource` is not signed, so checking it is cosmetic; with single-use claims a payment buys exactly one call at its exact price.
- Consequences: a payment whose tool then fails is consumed without being charged; the buyer signs a new one. `FileClaims` is per host and never pruned (bounded by the `validBefore` cap).

## D6 — The buyer spends its budget when it signs, and signs only known tokens

- Date: 2026-10-28
- Status: accepted (Leo, via review)
- Context: the x402-reviewer showed the buyer's cap counted only payments the seller confirmed; a seller that never confirms got 20 signed authorizations against a 5-call cap, each settleable on its own. It also got signatures for arbitrary tokens and a 10-year validity.
- Decision: `Budget::allows` is the one gate for both buyers: known network and token (asset and EIP-712 domain from `Networks`), positive integer amount, `maxTimeoutSeconds` ≤ 600, within the cap. `spend()` runs before signing and is never refunded by what the seller says.
- Not done, on purpose: a `payTo` allowlist (the cap bounds the loss; add when the agent pays third parties), a durable budget (per run for now), human confirmation per payment.
- Consequences: a refused or unconfirmed payment still uses budget until its `validBefore` passes; the cap is a hard ceiling on what can be signed in one run.

## D7 — Addresses are screened through a PSR-14 event, on both sides

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: Leo wants to block known bad wallets (sanctioned or reported) to protect sellers and, above all, paying agents. The official x402 SDKs offer no screening of their own: they expose hooks around verify/settle, and the CDP facilitator screens OFAC/KYT at settlement.
- Decision: the library dispatches an `AddressCheck` (PSR-14, stoppable) when given a dispatcher: on the payer after `verify()` (signature proven) and before the claim; on `payTo` before the buyer signs. `Scheme` gains `payer()`. It fails closed: an unreadable address or a throwing listener is a rejection. The app answers it with `App\X402\Screening`: our allowlist > our denylist > OFAC (local file, `app:screening sync`) > Chainalysis API (opt-in by key). No Symfony detection: PSR-14 is the seam, Symfony's dispatcher is one implementation.
- Not done, on purpose: IP blocking (infrastructure's job), async screening (no event loop; the API is opt-in for whoever puts compliance before latency), an admin UI (a console command for now), rejection codes from the spec (§9 has none for this; ours are `address_denied`, `address_sanctioned`, `address_check_failed`).
- Consequences: a payer blocked by the seller has still spent the buyer's budget (it signed). NEAR's payer is `sender_id`, not bound to the key offline; a forged one passes screening and fails at settlement. OFAC lists are mainnet addresses, applied to every EVM chain alike (EVM entries only: 124 of 210). `Budget::allows` signs only a canonical `payTo` (`0x` + 40 hex): Eip712 pads, so `0330…` or `0x000330…` would sign to a sanctioned address that screening saw in another form (x402-reviewer, critical). A corrupt or unreadable list stops payments; a never-synced OFAC list only logs a warning (ponytail: no staleness check yet).

## D8 — Deferred screening, budget freed by the chain, NEAR payer proven or absent

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: Leo accepted three follow-ups to D7. First, a sanctions API must not add its full latency to every payment. Second, a buyer that signed for a seller who then refused it should get that budget back. Third, a NEAR `sender_id` cannot be trusted offline.
- Decision:
  - A listener may `defer()` a closure. The seller dispatches the check right after verify and reads the deferred answer just before settling; a late rejection withholds the output, the same as a failed settle. The buyer waits before signing.
  - In the app, the Chainalysis request leaves when the check is dispatched. Answers, clean or sanctioned, are cached `X402_SCREENING_CACHE_TTL` seconds. With no answer within `X402_SCREENING_TIMEOUT` (or a 5xx), `X402_SCREENING_UNAVAILABLE` decides: `reject` (the default) or `allow`. Any other error rejects. An address on our allowlist never reaches the API.
  - `Budget` keeps every signed payment whose receipt did not say success. When the buyer next pays, `reclaim()` frees an entry once its `validBefore` has passed and the token's `authorizationState(from, nonce)` on chain says the nonce is unused. A used nonce or an RPC error keeps it spent. Never on the seller's word (D6).
  - `NearExact::payer()` returns the `sender_id` only when an injected `$keyOwner` (RPC `view_access_key`) proves the key is theirs. Otherwise it returns NULL, which screening rejects.
- Consequences:
  - A payer rejected late still had the tool run (its output is withheld) and its payment claimed.
  - The overlap between lookup and tool was not measured. Symfony HttpClient moves a request forward when the code waits on a response, so with a fast tool the API sets the pace on a payer's first payment, and the cache answers after that.
  - Budget release is EVM only and per process; it needs the network's public RPC (from `Networks`).
  - Release waits 60 s past `validBefore`, since the chain's clock, not ours, decides whether a signature can still settle. The RPC is trusted: one that lies or lags can free budget it should not.
  - An unread lookup (the tool failed, the claim was a duplicate) is cancelled, not read, so it neither blocks nor throws. Replays of one signature still each start a lookup before the claim (ponytail: no in-flight de-duplication).
  - NEAR screening needs that RPC check; NEAR is still out of the demo (D4).

## D9 — One spelling per signed value, and the spec gaps we know of

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: x402-reviewer, second pass against the v2 spec. The EIP-712 encoder reads `0xAB…`, `0xab…` and `ab…` as the same bytes, while `Settlement::key` hashed the nonce as sent. One signature, re-sent with its nonce spelled differently, verified every time and won a new claim: N deliveries for one payment, the hole D5 says is closed (critical, reproduced). Malformed numbers (`validAfter: "0abc"`) also reached `gmp_init` and threw: a 5xx instead of a rejection.
- Decision: `EvmExact::verify` accepts one spelling only: `from`/`to` `0x`+40 hex, `nonce` `0x`+64 hex, signature `0x`+130 hex, amounts and times plain decimals with no leading zero. Anything else is `invalid_payload`. `Settlement::key` lowercases the nonce too, so hex case alone cannot make a second claim. The HTTP transport now sends the failed `SettlementResponse` in `PAYMENT-RESPONSE`, as `transports-v2/http.md` asks.
- Known divergences, left on purpose until a real facilitator exists:
  - `settlement_pending` is treated as a failure (output withheld) but now keeps its transaction hash in the receipt, so the seller can reconcile it. Nothing retries or reconciles it yet.
  - The HTTP transport answers an invalid payment with 402, not the 400 the spec's error table gives (MCP has no status codes; only `X402Middleware` is affected).
  - `duplicate_settlement` comes from the SVM scheme, not v2 §9; official clients do not know it.
  - A facilitator that broadcasts but answers late (over 60 s) or drops the connection leaves `unexpected_settle_error` with no hash: the buyer may be charged without the output. The seller logs payer and nonce (`x402 settlement failed.`) to reconcile by hand with `authorizationState(from, nonce)`; nothing does it automatically.
  - EVM verification is offline: no balance check and no `transferWithAuthorization` simulation (`scheme_exact_evm.md`, phase 2). In mock mode a valid signature from an empty wallet gets the delivery.

## D10 — Submit before 11-08, with every gap x402_pay found

- Date: 2026-10-29
- Status: accepted (Leo)
- Context: refactoring the Drupal module `x402_pay` onto this library found 13 gaps (G1–G13: facilitator clients, Solana, network catalogue, online verify, resource, upfront, unavailable facilitator, claim release, budget timeouts, outcome hook, RPC helpers, PSR-7 bridge notes, Packagist). The hub suggested only G7 and G2 before the cutoff.
- Decision: all of G1–G13 go in, and we submit the week of 11-02, before 11-08, not on 11-20. Leo: "vamos x todo para el hackathon" and "tratemos de entregar el hackathon la semana que viene".
- Consequences: the 11-09 cutoff is gone; README and video move to 11-04 → 11-07.

## D11 — A real facilitator, verified before running; what frees a claim

- Date: 2026-10-29
- Status: accepted (follows from D10, decided by Leo; supersedes the parts of D5 and D9 below)
- Context: D5 required `X402_FACILITATOR=mock`; D9 left divergences "until a real facilitator exists". Since 2026-10-29 the app settles real testnet USDC on Base Sepolia through https://x402.org/facilitator, and the library gained online verify, claim release and an upfront flow (G2–G10).
- Decision:
  - `X402_FACILITATOR` is `mock` or an `https://` facilitator URL; anything else still fails closed. A settle counts only when consistent (2xx, `success: true`, a transaction, our network, the payer who signed).
  - The app asks the facilitator's `/verify` after the offline check and before the claim: an empty wallet runs nothing.
  - A claim is freed only when nothing can have been charged: the handler delivered nothing, or the facilitator clearly refused (a reason, no transaction). It is kept for `settlement_pending`, `facilitator_unavailable` and `unexpected_settle_error`: the transaction may still land, and a freed claim would let the same payment buy a second delivery.
  - Facilitator down: 502 on HTTP, `facilitator_unavailable` on MCP.
- Still divergent (D9): nothing reconciles pending or silent settles automatically; they are logged with payer and nonce (or Circle's `paymentId`).

## D12 — Mock mode by name; one claim key per NEAR payment (audit 2026-10-29)

- Status: accepted (Leo: "soluciona los criticos")
- Context: the x402-reviewer's full audit of v0.1.0 found that leaving out `$settle` silently ran mock mode (anyone who signs gets the delivery), and that a NEAR payment with a trailing byte or an extra payload field still verified under a new claim key, so N copies passed the claim.
- Decision:
  - `$settle` is required on both middlewares; mock mode is `Settlement::mock()`, and its receipt carries `extensions.mock: true`.
  - NEAR: `decodeSignedDelegateAction` rejects trailing bytes, and `Settlement::key` is network + sender + public key + nonce.
  - Also from the audit: `invalid_settle_response` keeps the claim (money may have moved); an upfront HTTP handler that throws after settling records `failed_after_settle`; the paying clients document that the wrapped PSR-18 client must not follow redirects.
- Consequences: breaking for callers that relied on the default, so the next tag is v0.2.0.

## D13 — Verify is not paid: README states it; zero-amount offers and smart-wallet signatures are refused on purpose (paper review 2026-11-02)

- Status: accepted (Leo: "si, haz los 3, pero que el README quede muy descriptivo sobre las funcionalidades y protecciones que implementamos en toda la libreria")
- Context: Wang et al., *x402 facilitators* (USENIX Security 2026, https://www.usenix.org/system/files/usenixsecurity26-wang-qinying.pdf) found 49 violations in 15 facilitators: free shopping (deliver after verify, settle fails or repeats), asset theft through ERC-6492 execution, DoS and gas abuse on sponsored gas. Reviewing this library against it: the default flow runs the handler before settle, so an irreversible handler can deliver an effect without payment; that was only a README line, not a rule.
- Decision:
  - The README says it plainly: verify is not paid, default flow suits content, `paymentFlow = 'upfront'` is for irreversible handlers. No second middleware (YAGNI); the choice stays per offer.
  - ERC-1271/ERC-6492 stay refused (signatures are exactly 65 bytes) and the README says so; a test pins it.
  - `EvmExact::verify` refuses an amount that is not a plain positive integer (`invalid_payment_requirements`); `Networks::requirements` already did.
  - The library README now lists each protection by layer, and the known limits.
- Consequences: no behaviour change for offers built with `Networks::requirements`. Needs re-split and push of `packages/x402` to quebracho-digital/x402 to publish.

## D14 — Mainnet is in production: Ethereum is built in to read its blacklist, Concordium leaves the repo (2026-11-03)

- Status: accepted (Leo: "ya estamos en mainnet, por ahora solo usamos ethereum para consultar la blacklist, eso no consume gas, el resto dependera del uso que le den y las blockchains que agreguen"; "[Concordium] sino se puede, borralo ... todavia no podemos empezar a codear concordium hasta el 19 de noviembre")
- Context: Ombú runs on Arc mainnet through `x402_pay`, so "testnets only" in `Networks.php` and `SECURITY.md` was false. `TokenBlacklist` knew only testnet USDC and Tether's USDT on Ethereum, so a frozen address on a mainnet passed. `ConcordiumExact` (b83b145) was experimental, had no facilitator and failed `phpstan` and `php-cs-fixer`, which blocked the pre-push audit.
- Decision:
  - Ethereum mainnet is a built-in network, to be read: its USDC and USDT blacklist is an `eth_call`, which costs no gas. Paying there costs real gas, so it is documented, not promoted.
  - `TOKENS` lists Circle's native USDC on Ethereum, Base, Polygon, Avalanche, Optimism, Arbitrum and Arc (5042), each read from a public RPC. They are asked only once a site registers the network; no other mainnet is built in.
  - The README documents how to add a blockchain: register it, name the token's blacklist function (and the contract), and check both with a real `eth_call`.
  - `Concordium` and `ConcordiumExact` are removed (`git show b83b145` recovers them). `eip155:1` was the "unknown network" in four tests; they use `eip155:7`.
- Consequences: a payment on `eip155:1` is no longer `invalid_network`; a seller chooses its networks, so none is charged unless offered. The library has 152 tests.


## D15 — HttpFacilitator keeps v0.2.1's settle polling for every caller; the 0.2.x signature stays as a shim until 1.0 (2026-11-04)

- Status: accepted (Leo: "vamos x la 1 con paridad exacta y dejalo documentado en decisions.")
- Context: separating Circle into `CircleFacilitator` (commit d711bc8) left `HttpFacilitator` without the v0.2.1 behaviour for callers that pass a bearer and no seller: a pending settle with a `paymentId` came back as `settlement_pending` instead of being polled at `/status`. The v0.2.1 constructor also had a different sixth parameter (`?Signer $seller`, `pollDelaySeconds`, `pollAttempts`), which 0.2.x callers could not compile against.
- Decision:
  - `HttpFacilitator::settle` polls `/status` whenever `/settle` answers pending with a `paymentId`, with or without `$seller` or `$bearer`, as v0.2.1 did. The generic facilitator therefore also waits, up to `pollAttempts × pollDelaySeconds` (5 s by default), in that case only.
  - The constructor keeps v0.2.1's positional parameters (`$seller`, `$pollDelaySeconds`, `$pollAttempts`). `$seller` raises `E_USER_DEPRECATED`; the new `$headers` closure is the last parameter.
  - A header hook that refuses (for example a seller proof that cannot be built) answers `seller_proof_failed` and frees the claim; it no longer throws out of `settle`, which had been lost in the split.
  - The shim and the copied poll loop are removed in 1.0, together with the rest of the 0.2.x compatibility.
- Consequences: a caller that passes a bearer to Circle through `HttpFacilitator` gets the same answer as with 0.2.1. Callers that move to `CircleFacilitator` see no difference. Tests: `FacilitatorsTest` (bearer polling, refusal freeing the claim, the 0.2.1 call with a seller). The published 0.3.0 is checked separately by `CompatibilityTest`.
- Timing of the poll: changed by D16 (ver D16).

## D16 — The first /status query goes out at once; the poll delay sits only between queries (2026-11-04)

- Date: 2026-11-04
- Status: accepted (Leo: "corrige el orden del polling")
- Context: the poll loop slept `pollDelaySeconds` before every `/status` query, the first included. With a facilitator that returns a `paymentId` but has no `/status` route, the first query failed with an HTTP error, `FacilitatorException` ended the loop, and the settle had already waited the delay for nothing. The v0.2.1 loop had the same order; D15 copied it.
- Decision:
  - `HttpFacilitator::settleWithPoll` and `CircleFacilitator::settle` query `/status` immediately; `sleep($pollDelaySeconds)` runs only before each later query.
  - Unchanged: the `$pollAttempts` limit on queries, the stop on `FacilitatorException`, the `completed`/`failed` handling, and `settlement_pending` with the `paymentId` when it is still pending.
  - This changes the timing only. The results are D15's.
- Consequences:
  - D15 is partially modified in timing: a settle waits at most `(pollAttempts − 1) × pollDelaySeconds` (4 s by default, not 5 s), and not at all before the first query. The v0.2.1 wait before the first query is not kept.
  - Tests: `testTheFirstStatusQueryGoesOutBeforeAnyDelay` uses a 60 s delay; a leading sleep would fail its elapsed-time check after a real minute. The other polling tests run with a 0 s delay, so they do not measure timing.
