# Roadmap and tasks

Built for the Symfony AI & MCP Hackathon (deadline 2026-11-22; we submit before then, D10).
Decisions are recorded in [DECISIONS.md](DECISIONS.md).

## Plan

| Dates | Step | Status |
|---|---|---|
| 10-28 → 11-01 | `quebracho/x402`: signing, verification, tests | done |
| 11-02 → 11-03 | 402 middleware, MCP in band (D2), claim once (D5) | done |
| 11-04 → 11-05 | Paying clients, budget (D6), `app:ask` agent | done |
| — | Address screening: OFAC, own lists, Chainalysis (D7) | done |
| — | Deferred screening, budget released by the chain, NEAR payer proven (D8) | done |
| 10-29 | Settle on testnet through a real facilitator (x402.org, Base Sepolia) | done |
| 10-29 | Facilitator clients in the library: any URL, Circle, on chain (G7) | done |
| 10-29 | Online verify, claim release, 502 when the facilitator is down, upfront flow, resource, outcome event (G2–G5, G8, G10) | done |
| 10-30 → 11-03 | Solana (G6), network catalogue (G1), G9, G11–G12, Packagist (G13), NEAR strict screening | done (v0.3.0 on Packagist, 11-03) |
| 11-03 | Mainnet: the library runs in production in Ombú (Arc); blacklist of mainnet USDC; Ethereum built in (read only); Concordium removed (D14) | done |
| 11-04 → 11-08 | Job mode (D22): deposit, free status, paid result, reconciler and janitor; the engine moved into the library | done (library v0.4.0, 11-08) |
| 11-06 → 11-08 | OFAC screening in the library, on by default, with events for third-party lists; the app uses it | done (v0.4.0) |
| 11-08 | `CasperExact` merged in the library repo (v0.4.1); the app now takes the library from Packagist (`^0.4.1`) | done |
| 11-09 | Docs brought up to date; `analyze_terms` stays at 0.02 USDC | done |
| 11-09 → 11-10 | Library v0.5.0: the stop switch, optional scheme interfaces, the `Seller` and `Buyer` builders, job mode's wiring, `PriceList`, `SensitiveRules`, `Screening`, `Facilitators` (D25) | done (v0.5.0, 11-10) |
| 11-11 | The app on v0.5.0 with no copies of library classes left (D26); `app:x402:stop` (D27); a one-file quick start (`bin/quickstart.php`) | done |
| before 11-18 | A real purchase through Ombú's MCP with the hackathon wallets (needs a facilitator for the network it pays on) | pending, waits for Leo |
| before 11-20 | New 90 s video (the library, the Symfony app, `x402_pay`, Ombú) | pending |
| **before 11-22 18:00 CET** | **Submit** (form, public repo, video) | |

## Tasks (hackathon and app)

The gaps x402_pay found refactoring onto the library (G1–G13) all go in before submitting (D10).

Tasks for the library are in [https://github.com/quebracho-digital/x402/blob/main/ROADMAP.md](https://github.com/quebracho-digital/x402/blob/main/ROADMAP.md).

- [x] Settle on testnet (Base Sepolia) through a facilitator.
- [x] Screening in the app: `app:x402:check` warns when the OFAC list in use is older than 14 days.
- [ ] Try the server with MCP Inspector.
- [ ] Real purchase on Ombú with the hackathon wallets, a cents-sized cap, the key only in `app/.env.local`.
- [x] Global stop switch: `Quebracho\X402\Stop` (library v0.5.0), wired into the seller's middleware and the buyer's client, with `app:x402:stop on|off|status` (D27).
- [ ] Mainnet test of each supported network, and a testnet smoke, once, when the maintainer orders it.

## After the hackathon

- phay.io: a hosted registry of trusted sellers and reported addresses, behind the `AddressCheck` hook.
- `auth-capture` for job mode (authorize at the start, capture when the job is ready, void if it fails): written in the library on a branch, not released; Base and Base Sepolia only.
- Circle Gateway as a payment scheme, for buyers and sellers.
- The Drupal module `x402_pay` uses this library (`^0.4`); moving it to `^0.5` and the library's `PriceList`, `SensitiveRules` and `Screening` is its own task.
- Human approval for large payments: above a threshold the buyer asks the user, through the MCP client, to confirm the exact amount, payee and network before signing. Needs a client that supports elicitation (not all do), so it waits for real users spending from their own wallets.
- Signed receipts for what the buyer received: a record that commits to the exact bytes of the result, checkable offline and tied to the payment transaction. Waits for a stable format.
