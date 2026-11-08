# Security policy

## Scope

This repository handles payments, so we take reports seriously. The library runs
in production on mainnet (Arc), through the Drupal module `x402_pay` at
https://ombu.quebracho.digital; this repository also holds a demo app that
defaults to testnets:

- Built-in networks are the demo testnets, Arc mainnet (where Ombú sells) and
  Ethereum mainnet, which is there to be read (the blacklist of its USDC and
  USDT, an `eth_call` with no gas). Every other mainnet is registered by the
  site with `Networks::add()`; nothing selects a mainnet unless a site
  configures it.
- `X402_FACILITATOR` is `mock` (nothing settles) or a facilitator URL; the demo
  settles real testnet USDC on Base Sepolia through https://x402.org/facilitator.
- The key in `app/.env` is the public example key from the web3.js docs, kept on
  purpose. It holds testnet USDC anyone can spend; secret scanners flag it, and
  that is a known false positive. Never put a key that holds real funds in an
  env file of this repository.

Do not send funds to addresses found in this repository other than through the
demo, and use your own keys for anything real.

In scope: the `quebracho/x402` library (`quebracho/x402`, in its own repository) and the demo app
(`app/`): payment verification, replay (claiming one payment twice), the
buyer's budget, address screening, and the MCP/HTTP transports.

## Reporting

Please report privately through GitHub's
[private vulnerability reporting](https://github.com/quebracho-digital/quebracho-x402-mcp/security/advisories/new),
not in a public issue. Include what you did, what you expected and what
happened. We aim to answer within a week.
