# Roadmap and tasks

Built for the Symfony AI & MCP Hackathon (deadline 2026-11-22; we submit before 11-08, D10).
Decisions are recorded in [DECISIONS.md](DECISIONS.md).

## Plan

| Dates | Step | Status |
|---|---|---|
| 10-28 → 11-01 | `packages/x402`: signing, verification, tests | done |
| 11-02 → 11-03 | 402 middleware, MCP in band (D2), claim once (D5) | done |
| 11-04 → 11-05 | Paying clients, budget (D6), `app:ask` agent | done |
| — | Address screening: OFAC, own lists, Chainalysis (D7) | done |
| — | Deferred screening, budget released by the chain, NEAR payer proven (D8) | done |
| 10-29 | Settle on testnet through a real facilitator (x402.org, Base Sepolia) | done |
| 10-29 | Facilitator clients in the library: any URL, Circle, on chain (G7) | done |
| 10-29 | Online verify, claim release, 502 when the facilitator is down, upfront flow, resource, outcome event (G2–G5, G8, G10) | done |
| 10-30 → 11-03 | Solana (G6), network catalogue (G1), G9, G11–G12, Packagist (G13), NEAR strict screening | done (G13: v0.1.0 on Packagist; v0.2.0 pending) |
| 11-04 → 11-07 | `demo.sh`, README, 90 s video, minimal CI | |
| **before 11-08** | **Submit** | |

## Tasks (hackathon and app)

The gaps x402_pay found refactoring onto the library (G1–G13) all go in before submitting (D10).

Tasks for the library are in [packages/x402/ROADMAP.md](packages/x402/ROADMAP.md).

- [x] Settle on testnet (Base Sepolia) through a facilitator.
- [ ] Screening in the app: warn when the OFAC list is stale (mtime older than N days).
- [ ] Try the server with MCP Inspector.

## After the hackathon

- phay.io: a hosted registry of trusted sellers and reported addresses, behind the `AddressCheck` hook.
- The Drupal module `x402_pay` uses this library.
