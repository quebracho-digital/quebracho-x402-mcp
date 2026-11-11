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

## D17 — Sensitive requests are not cached; the memo's membership oracle is an accepted, documented risk (2026-11-04)

- Date: 2026-11-04
- Status: accepted (Leo: "podemos definir un nuevo campo que marque la sensibilidad? agentes que manejen informacion sensible, no se cachea nada? mientras aceptamos y documentamos como riesgo conocido.")
- Context: `analyze_terms` memoises each answer for 7 days under a hash of the text (`TermsAnalyzer`, `terms.cache`), so the same text is not sent to the model twice. A memo hit answers in milliseconds, a miss takes the model's seconds: any payer can time a call and learn whether someone sent exactly that text in the last 7 days (a membership oracle). The payment ledger also keeps each delivered answer, so a retry replays it; for a sensitive call that copy is one more trace of its content.
- Decision:
  - Library: `X402Middleware` and `X402McpMiddleware` take an optional last parameter `?\Closure $sensitive` (`fn(ServerRequestInterface|array $params): bool`). When it marks a paid request, the ledger keeps the payment's state (claim, settling, handling, receipt) but never the response body. It is called once per paid request, before anything is stored; one that throws, or returns anything but `false`, counts as sensitive (fail-closed toward not storing). Without it nothing changes.
  - App: `X402_SENSITIVE_TOOLS` (comma separated, empty by default) names tools whose answers the ledger never keeps (`App\X402\SensitiveTools`). A caller can also pass `sensitive: true` to `analyze_terms`: the memo is neither read nor written, the model is called directly, and the ledger keeps no copy of the answer.
  - Not closed: the oracle stays open for texts other callers sent unmarked. A marked call protects only the one who marks it: its timing reveals nothing about other callers and it leaves no answer behind, but an unmarked text is still in the memo and still answers fast to anyone who asks without the flag. Accepted as a known risk.
- Consequences:
  - A retry of a sensitive payment that already settled gets `already_settled` (409 over HTTP, an isError result over MCP) instead of the answer, and the handler does not run again: the buyer must keep the first answer.
  - Every sensitive `analyze_terms` call costs a model call, with no saving from the memo, while the price is the same.
  - Tests: `LedgerReplayTest` (no `response` in any ledger put, retry without body, a throwing check, default and upfront flows, HTTP and MCP), `TermsAnalyzerTest` (memo untouched, model asked), `SensitiveToolsTest` (the env reaches the middleware).

## D18 — The ways to ask for no cache are a dictionary of rules in YAML, checked at deploy (2026-11-04)

- Date: 2026-11-04
- Status: accepted (Leo: "te confirmo", in reply to the proposal: Pragma only as a fallback for Cache-Control, and the rules in YAML validated at deploy.)
- Context: D17 marked a request sensitive in two hard-coded ways, a tool named in `X402_SENSITIVE_TOOLS` or `arguments.sensitive` true (`App\X402\SensitiveTools`). HTTP already has its own ways to say "do not store this" (`Cache-Control: no-store`, the older `Pragma: no-cache`), and more may come; each one should be a line of config, not a code change.
- Decision:
  - `app/config/x402_sensitive.yaml` lists the rules (`app.x402_sensitive_rules`), each with `kind` (`header`, `argument`, `tool`), `name`, and `value` where the kind needs one. `App\X402\SensitiveRules` evaluates them; any rule that matches makes the request sensitive.
  - `Cache-Control` containing the `no-store` directive is sensitive. The header is parsed into directives (comma separated, spaces trimmed, any case, `name=arg` matches `name`), never searched as a substring.
  - `Pragma: no-cache` is sensitive only as a fallback: its rule has `unless_header: Cache-Control`, so a request that carries `Cache-Control` is judged by it alone (RFC 9111, §5.4).
  - `arguments.sensitive === true` (strict) and `X402_SENSITIVE_TOOLS` keep working: the first is a shipped `argument` rule, the second becomes one `tool` rule per name.
  - No rule matching means not sensitive: marking only removes the ledger's copy, so the default is not to mark.
  - The rules are validated at deploy (`SensitiveToolsWarmer` at `cache:warmup`, and `app:x402:check`): an unknown kind, an empty name, a value missing or wrong for its kind, an unexpected key, a duplicate (header names and directives compared case-insensitively, the env and the YAML together) or a `tool` rule naming no served tool fails it.
  - The library does not change: it still only receives the decision through `$sensitive`.
- Consequences:
  - `header` rules read an HTTP request (`X402Middleware`); `argument` and `tool` rules read MCP params (`X402McpMiddleware`, which passes only the params, so headers on an MCP call are not seen). The app has no paid HTTP route today, so the header rules are covered by direct tests of `SensitiveRules` only.
  - A quoted directive argument containing commas (`no-cache="a, no-store"`) can produce a stray `no-store` token and mark the request; that errs toward not storing.
  - Tests: `SensitiveRulesTest` (headers, precedence, arguments, tools, no rule, invalid YAML, the warmer), `SensitiveToolsTest` and `SensitiveToolsWarmerTest` (the env still works).

## D19 — ChainFacilitator's transaction is stored before it is sent (2026-11-04)

- Date: 2026-11-04
- Status: accepted (Leo: "si, implementa")
- Context: `ChainFacilitator::settle` computes the transaction hash before `eth_sendRawTransaction`, but the hash reached the ledger only with the outcome. A process that died between the send and that write left `settling` with no reference: the buyer paid, a retry got `409 settlement_unknown` until the TTL, and the payment could not be reconciled from the ledger.
- Decision:
  - The chain reference is written before it is sent. `ChainFacilitator::settle` takes an optional `?\Closure $reference` (`fn(string $transaction): bool`) and calls it with the hash just before the send. `Settlement::run` passes it to `$settle` as a third argument; a closure that does not take it ignores it, so `Facilitator` and the existing closures do not change.
  - `Sale` gives that callback: it adds `receipt.transaction` to the `settling` state `intend()` already wrote, through the same `intend()`.
  - A write that fails sends nothing: the callback returning false or throwing gives `reference_unavailable` with no transaction, the claim is freed, HTTP answers `503 ledger_unavailable` and MCP an `isError` result.
  - A `settling` or `pending` state with a transaction lives 24 h (`Sale::REFERENCED_TTL`, 86400 s) instead of `min(600, validBefore − now)`. States without one keep that TTL.
  - The residual window stays open, and documented, for HTTP facilitators (`HttpFacilitator`, `CircleFacilitator`): they return no reference before they answer.
  - Reconciliation (finding and resolving the payments stuck in `settling` or `pending`) is separate work.
- Consequences:
  - Each chain settle writes the ledger one more time, inside the network lock, before the send.
  - The 24 h covers the time for a buyer's retry or a person to reconcile with the hash after a crash or an unconfirmed send. The 600 s TTL let that reference go before anyone could use it. A key that lives longer also keeps the claim longer, which can only block a second charge, never allow one. The cost is ledger space for those few states.
  - A `$settle` closure that wraps `ChainFacilitator` without passing the third argument gets the old behaviour. The README says to pass `$chain->settle(...)`.
  - Tests: `FacilitatorsTest::testChainHandsTheReferenceOverBeforeSendingAndSendsNothingWithoutIt`, and in `LedgerReplayTest` the crash after the send, the retry asking the lookup about that transaction, a refused reference over HTTP and MCP (no send), and the TTLs.

## D20 — An open index of unresolved payments, and a reconciler that corrects it with the chain (2026-11-05)

- Date: 2026-11-05
- Status: accepted. Decidido por Leo (2026-11-05 05:39): "acepto d20". Implemented on request of the working session; the real-node test against testnet is still pending Leo's go-ahead for testnet payments.
- Context: D19 left reconciliation as separate work. A process that dies after the settle went out leaves `settling` or `pending` (with a transaction or not): the buyer may have paid and got nothing, a retry gets `settlement_unknown` or `settlement_pending` until the TTL, and nobody looks at those states.
- Decision:
  - `PaymentLedger` gets `open(key, ref, ttl)`, `openKeys(limit)` and `close(key)`; `release()` closes the entry too. `FileLedger` keeps one `<sha256>.open` file per key next to its state (`prune()` leaves them alone); `RedisLedger` keeps the ref under `x402:open:<key>` with its own TTL and the key in the set `x402:open` (`openKeys` samples it with SRANDMEMBER and drops members whose ref expired). The ledger stamps `opened` on the first open and keeps it on later ones.
  - The ref is the minimum to ask the chain: network, asset, from, nonce, payTo, amount, validBefore, transaction, paymentId, the status, and `upfront`. Never a signature nor a body.
  - `Sale` opens the key whenever it writes `settling` (with or without a transaction) or `pending`, and closes it when it writes `settled` or `delivered` (`remember()` and the lookup's `confirm()`). The entry lives `Sale::OPEN_TTL`, 3 days: 24 h to resolve it, then visible as needs_review.
  - An open that fails throws. While writing `settling` (`intend()`) that refuses the write, so nothing is sent: 503 `ledger_unavailable` over HTTP, an `isError` over MCP, the rule for a `settling` the ledger refuses. After a settle (`pending`) the middlewares' `record()` drops the error: the index never blocks a payment whose money moved. A close that fails is dropped; the reconciler finds the state settled and closes it.
  - `App\X402\Reconciler` (`app:x402:reconcile [--limit=100]`, cron every 5 minutes, not set up by us) never delivers nor charges. Per open key, all RPC questions first, then at most one write:

    | Chain | Result |
    |---|---|
    | state already settled, delivered, handling or claimed | entry closed, `untouched` |
    | `authorizationState` true, our transaction mined with status `0x1` | `settled` with that transaction, closed |
    | true, our transaction `0x0` | `needs_review`, nothing freed |
    | true, no transaction (or ours not mined): `AuthorizationUsed(from, nonce)` found by `eth_getLogs` | `settled` with its `transactionHash`, closed |
    | true, no event in the window | `needs_review` |
    | false, `validBefore + 60 s` passed | `released` (state deleted, entry closed) |
    | false, still valid | `untouched` |
    | any RPC call failed | `untouched`, nothing changed |
    | network without `rpc` | `rpc_error` |
    | not EIP-3009 (Solana, NEAR) or a ref without from/nonce/asset | `untouched` (by hand) |
    | `untouched` or `rpc_error` and open more than 24 h | `needs_review`, logged |

  - The `settled` it writes is the one `Sale::confirm()` writes: the receipt `{success, transaction, network, payer}` normalised by `Settlement::settled`, `delivered` when a response was kept, `handler_ran: false` for an upfront payment, and `Sale::ttl` (until validBefore, at most 600 s).
  - `eth_getLogs` looks at the last 10,000 blocks (`Reconciler::LOG_BLOCKS`): the range public nodes and most providers accept in one call, about 5.5 h on Base and 33 h on Ethereum. The event is mined before validBefore, so a cron that runs every 5 minutes finds it.
- Consequences:
  - `PaymentLedger` has three more methods: any ledger written outside this repo must implement them (a breaking change of the library's interface).
  - A default-flow payment the reconciler settles answers `already_settled` to a retry: it cannot reproduce an answer that was not kept. An upfront one runs its handler on a retry while the signature is valid; after `validBefore` the offline verify refuses the retry, as it does after `confirm()`.
  - `needs_review` is not resolved by anything: a person reads the log or the table and checks the explorer. A key the cron does not reach for longer than the window above is `needs_review` too.
  - The re-read before the write is not atomic: a retry that writes between it and ours is overwritten (a lost replay copy, never a second charge), and the next run sees the result.
  - Each `settling` or `pending` write costs one more ledger write (two Redis calls, one more file).
  - Tested against a scripted node only; no real RPC was asked. Tests: `FileLedgerTest` and `RedisLedgerTest` (open, openKeys, close, release, expiry), `LedgerReplayTest` (open with the transaction after a crash, closed once delivered, pending opened, an open that fails sends nothing), `ReconcilerTest` (each row of the table), `X402ReconcileCommandTest`.

## D21 — The buyer's cap is reserved before signing; a payment that fails before it is sent gives its amount back (2026-11-05)

- Status: accepted (Leo, through the docs session: "que Budget reserve el monto de forma atómica antes de firmar, y que libere la reserva si el pago falla")
- Context: the buyer ran choose (allows) → sign → spend. Two payments concurrent in one process could both pass allows() before either spent, so the total could exceed the cap by up to N payments. Reachable only with a Fiber-aware PSR-18 client: Solana and NEAR make RPC calls between choose and spend, and the address screening can block on a lookup. Separately, two concurrent reclaim() calls could free the same unsettled signature twice.
- Decision:
  - `Budget::reserve()` checks the cap and adds the amount in one synchronous step, and returns false without changing anything when it does not fit. Nothing is signed or sent for a refused reservation.
  - `Budget::release()` gives the amount back when signing or encoding fails before the request leaves. A payment that may have reached the seller keeps its reservation, as before (`unsettled()` and `reclaim()`).
  - `reclaim()` removes an entry before asking the chain, skips entries another reclaim has taken, and restores the entry if the lookup throws.
  - The budget lives in one process. Separate processes, workers or hosts each have their own cap; a shared limit needs the application to store the spent totals.
- Consequences: no public method of the library is removed (tests/CompatibilityTest.php). Tests: `BudgetTest` (reserve, release, concurrent reclaims), `PayingHttpClientTest` (a payment that cannot be sent gives its reservation back; five concurrent payments never pass the cap).

## D22 — Long-running paid work: job mode, opt-in per tool (debate of 2026-11-03, built and released in v0.4.0)
- Number: written as D15 on main while D15 to D21 were taken on feat/job-mode-foundations; numbered D22 at the merge, text unchanged. The protocol as built is in docs/job-mode.md.
- Context: `analyze_terms` calls a model for 5 to 40 s, and the signed authorization lives 60 s. MCP progress notifications cannot ride on the paid response: the PHP SDK streams it after our middleware returned, so the middleware cannot settle what it has not seen (it fails closed, a test pins it). The official x402 specs (core v2, transports-v2/mcp.md) say nothing about progress, long-running or async paid work; `paymentFlow=upfront` and `upto`/`escrow` are the closest. Five experts (Symfony, x402, Drupal/Ombú, architect, process) debated it in two rounds and converged; Leo approved ("si, aprueba").
- Decision:
  - Job mode is opt-in per tool, by a flag (`#[PaidJob(role: start|result)]`), never by name. A job is two ordinary paid tools: `<tool>_start` (a deposit with `paymentFlow=upfront`) and `<tool>_result(jobId)` (the rest, normal settle-after-success, signed only when the result exists). Fast tools keep the single-payment flow, untouched (a regression test pins it).
  - The deposit is declared non-refundable. When the failure is ours by a fixed list (provider error, non-JSON reply, our timeout, our daily cap) `_result` costs zero. No credit ledger (the 402 is priced before the payer is known), no on-chain refund from a hot wallet, no LLM "expert agent" as code. A payment settled with no job is refunded by hand from the `failed_after_settle` record.
  - One progress channel: a free `<tool>_status(jobId)` MCP tool. No progress-token URL.
  - Background work with `kernel.terminate` after a durable job row exists (files or SQLite, never `cache.app`), a janitor for stale `running` jobs, and a concurrency cap before the deposit settles. The Job, its stores, the runner, the reconciler and the janitor moved into the library (`Quebracho\X402\Job`, v0.4.0); the app keeps the paid-tool wiring.
  - Milestones are a spec and a `workflowId`/`milestone` field: a milestone is one more ordinary paid step on the same job.
  - Ombú: Stage 1a (the pre-flight) and a written spec only. A third-party seller could keep deposits and Ombú could not claw them back.
  - Library hooks (all optional, additive): `validate(params)` before the payment is read, `payer(params, payer)` before screening and the claim, `duration(params)` for the pre-flight (refuse when `validBefore - now < duration + 6 s`, before the claim, after the offline verify and again before the claim). Requirements: `_start` idempotent on the claim key, `_result` bound to the payer of the deposit, atomic `ready > delivering > delivered`, pricing fails closed (a config typo never leaves a tool free), arguments validated against the tool's input schema before any payment.
- Consequences: built and released. The Job store, `analyze_terms_start/_result/_status`, the reconciler and the janitor exist (`docs/job-mode.md`); two rounds of x402 review closed with the limits W1–W3 documented, not built (README, "Known limits (job mode)"). The job runs on one host (a file store). Not done: the proposal to the x402 foundation. `auth-capture` (authorize, capture when ready, void on failure) is written on a library branch and waits for the next version; it works on Base only.

## D23 — The library is the main repository; the app takes it from Packagist (2026-11-08)

- Status: accepted (Leo: "el repo de la libreria deberia ser el principal y referente, el otro es solo para el hackathon")
- Context: the library lived in `packages/x402` of this repo and was split out to `quebracho-digital/x402` at each release. Outside contributions (`CasperExact`, from the Casper Network CTO) arrived at the split repo, so two histories had to be kept in step.
- Decision: `quebracho-digital/x402` is where the library is developed, tested (CI there) and tagged. This repo requires `quebracho/x402` from Packagist (`^0.4.1`); `packages/x402` is gone. A library fix is a PR there, then a bump here.
- Consequences: the app's tests and the audit run against the released library; `bin/audit.sh` and CI no longer test the library. `.gitleaksignore` still lists the old monorepo hashes of the public test keys. Do not fetch the split repo's refs into this repo: gitleaks would scan them under other hashes and flag those keys again.

## D24 — OFAC screening is in the library, on by default (2026-11-06)

- Status: accepted (Leo: "deberia estar activada por defecto, no quiero tener ningun problema con el state department")
- Decision: `quebracho/x402` ships OFAC's SDN list (the official Sanctions List Service, not a mirror) and checks both parties by default. An option turns it off and another adds more lists (`AddressList`, with an example format); PSR-14 events let third parties plug in their own. Keeping the lists fresh is the job of each application; the library's bundled list is refreshed before each release. Only OFAC is on by default (it needs no network); Chainalysis and the token's own blacklist are wired by the app. The allowlist never skips OFAC.
- Consequences: the app's `App\X402\Screening` is built on the library (since D26, around the library's own `Screening`); `app:x402:check` warns when the list in use is older than 14 days. Upgrade notes: the library's `UPGRADE.md`.

## D25 — Library v0.5.0: extension points, builders, and what the example app had grown (2026-11-10)

- Status: accepted (Leo, after a review of the library: "haz las interfaces opcionales y que el nucleo siempre use scheme para decidir", "mueve todo lo que sugieres a la libreria")
- Context: the core asked "is this EVM?" in several places, so a new chain family meant touching it; the constructors had grown to fifteen positional arguments; and the app held classes any seller needs (prices per tool, the rules for sensitive answers, the screening lists, the choice of a facilitator, job mode's wiring).
- Decision (in `quebracho-digital/x402`, released as v0.5.0):
  - What the core needs to know about a chain it asks the scheme, through optional interfaces: `SpendsOnce` (a payment's identity), `Expires`, `AddressForm`, `VetsOffers`, `ShapesOffers`.
  - `Seller` and `Buyer` name the options; the constructors keep their order (no break).
  - Into the library: `PriceList`, `SensitiveRules`, `Screening`, `Facilitators` and `MockFacilitator`, `Job\JobDesk`, `JobDeposit`, `JobDelivery`, `JobIdOnRefusal`, `JobSlots` with `FileJobSlots`, `Stop`, and PSR-20 clocks.
  - `Networks` as an injectable instance waits for 1.0 (Leo: "4. ok, estoy de acuerdo").
- Consequences: the library's `CONTRIBUTING.md` holds the rules, and its `ArchitectureTest` and `DocblockTest` enforce them. Its public API is checked against the inventories of 0.3.0 and 0.4.1 (`tests/CompatibilityTest.php`). New dependencies: `psr/cache`, `psr/clock` (interfaces only).

## D26 — The app keeps no copy of a library class (2026-11-11)

- Status: accepted (Leo: "migrar lo que falte de la libreria")
- Context: a first migration to v0.5.0 wrapped the library's job classes in app classes of the same name, and lost the `#[AsEventListener]` of `App\X402\Screening`: the deny list and the sanctions API stopped being asked on payments while every test passed, because the test added the listener by hand.
- Decision:
  - The app uses the library's classes directly, wired in `app/config/services.yaml`: `Job\JobDesk`, `JobDeposit`, `JobDelivery`, `JobIdOnRefusal`, `PriceList`, `SensitiveRules`, and the `Facilitator` that `Facilitators::from` builds, given to the middleware and to `JobDesk` as it is.
  - `App\X402` holds only what belongs to an application: factories that read env and name the env variable in an error (`PriceListFactory`, `FacilitatorFactory`, `LedgerFactory`, `SchemeFactory`, `BuyerBudget`, `BlacklistFactory`, `PaymentLogFactory`), the `#[PaidJob]` attribute, `ToolInput` (the MCP SDK's schema check, as the middleware's `$validate`), `JobSlots` (Symfony Lock), `Screening` (env, HTTP timeouts and the OFAC sync around the library's `Screening`) and the deploy checks.
  - A kernel test asserts that `App\X402\Screening` listens to `AddressCheck` in the real container (`AppKernelTest::testTheAppsScreeningListensToEveryAddressCheck`).
- Consequences: `App\X402\Pricing`, `SensitiveRules`, `Facilitator`, `JobDesk`, `JobDeposit`, `JobDelivery` and `JobIdOnRefusal` are gone. The app's own log lines for a mock settlement and a failed settle are gone with `App\X402\Facilitator`: the library reports every outcome and fault as an event, and `PaymentLog` writes it. `symfony/monolog-bundle` stays optional (`Kernel::optionalBundles`), never in `config/bundles.php`.

## D27 — A stop switch for payments, by hand (2026-11-09, in the app 2026-11-11)

- Status: accepted (Leo: "el global stop lo implementamos ahora o despues del hackathon? yo digo ahora"; "no debería ir a la librería?")
- Decision: `Quebracho\X402\Stop` is a flag file. The seller's middleware and the buyer's client are given the same instance; while the file exists a paid call is answered `payments_paused` before anything is verified, claimed, run or charged, and the buyer reserves and signs nothing. `app:x402:stop on|off|status` sets it; `touch` does the same. The file is `X402_STOP_FILE`, by default `app/var/x402-stop/paused`.
- Why a file: every process sees it at once, nothing has to be running to set it, and nothing sets it by itself. It fails closed where it can tell (anything at the path counts as engaged; a directory it cannot search does too), and `engage()` refuses a link, a file somebody else owns and a directory others can write to.
- Consequences: it does not recall a payment already signed or a settle already sent; a payment the ledger already holds is still delivered, and jobs already paid for keep running. Free tools still answer. Several hosts need a shared path or the command on each. `app:x402:check` warns when it is engaged. Tests: `X402StopCommandTest`, `AppKernelTest::testOneStopSwitchPausesTheSellerAndTheBuyer`.
