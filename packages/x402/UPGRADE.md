# Upgrading

## From 0.3 to 0.4

Every public class, method, constant and constructor parameter of 0.3.0 is still there, in the same
position (`tests/CompatibilityTest.php` checks it against the published tag). What changes is one
dependency and what some calls do. Read the five points below before deploying.

### 1. Update

```bash
composer require quebracho/x402:^0.4
```

`psr/log` ^3 is now required: `PaymentLog` logs the library's events through any PSR-3 logger. A project
pinned to `psr/log` 1 or 2 has to move to 3 first (Drupal 10+ and Symfony 6+ already require 3).

### 2. OFAC screening is on, with or without a dispatcher

In 0.3 an address was screened only when you passed a PSR-14 dispatcher and wrote a listener. In 0.4 every
check asks OFAC's SDN list first (`OfacSdn`): the payer when you sell, `payTo` when you buy. A listed address
is refused with `address_sanctioned` before any listener of yours runs, and your listeners cannot let it
through.

- **Nothing to do for it to work**: the list ships with the library (`resources/ofac-sdn.json`), so it runs
  offline from the first request. It is as old as the release you installed.
- **Keep it fresh** (recommended): write your own snapshot from a daily cron and pass it as `ofac:` to the
  middlewares and paying clients. The newer of your copy and the shipped one is used.

  ```php
  // cron, daily:
  (new OfacSdnSync($psr18, $psr17))->sync('/var/lib/x402/ofac-sdn.json');

  // wiring:
  $ofac = new OfacSdn('/var/lib/x402/ofac-sdn.json');
  new X402McpMiddleware(..., ofac: $ofac);
  new PayingMcpClient(..., ofac: $ofac);
  ```

- **Turning it off** is possible only by name, `ofac: OfacSdn::off()`, and is on you.
- **If you ran your own OFAC listener** (a copy of a third-party mirror, for example), it now runs after the
  official list. You can drop it, or keep it as a second opinion.
- An address check with no address at all is now refused with `invalid_payload`; in 0.3 it passed.
- A snapshot that cannot be read refuses every address: a wrong path or a permission error stops payments.

### 3. What your `$verify` closure returns is read strictly

`$verify` (the online verify of both middlewares) approves only with `NULL`, and refuses with a non-empty
string, which becomes the reason. Any other return (`false`, `true`, `''`, an array) refuses with
`unexpected_verify_error`; 0.3 used it as the reason as it was. An exception other than
`FacilitatorException` used to escape as a 500; it is now a 402 with `unexpected_verify_error`, and
`PaymentOutcome::$error` carries it.

If your closure returns `NULL` or a reason string, nothing changes.

### 4. The buyer reserves before signing

`PayingHttpClient` and `PayingMcpClient` call `Budget::reserve()`, which checks the cap and spends in one
step, before they sign, and `release()` when the payment never left (signing or sending failed). In 0.3 they
checked, signed, then spent, so two payments at once could both pass the cap. Nothing to change in your
code: `allows()` and `spend()` keep their meaning for your own calls.

### 5. Circle's specifics moved to `CircleFacilitator` (deprecations)

`HttpFacilitator` is the generic x402 facilitator client. Circle's keyless proof header and its `/status`
polling live in `CircleFacilitator`. 0.3's way still works and warns:

| 0.3 | 0.4 | Removed in |
|---|---|---|
| `HttpFacilitator::CIRCLE` | `CircleFacilitator::URL` | 1.0 |
| `new HttpFacilitator(..., seller: $signer)` (triggers `E_USER_DEPRECATED`) | `new CircleFacilitator(...)` | 1.0 |
| `$pollDelaySeconds`, `$pollAttempts` on `HttpFacilitator` | the same on `CircleFacilitator` | 1.0 |

### New, all optional

Nothing below needs a change to keep working; each is opt-in.

- **Events**: `PaymentFault` (the ledger failed and the sale went on), `AddressRejected`, `ScreeningSkipped`,
  `PurchaseIntent`, `PurchaseOutcome`, `BudgetReclaim`, and `PaymentIntent` before each risky step. A
  listener of yours that takes `object` sees them too. `PaymentLog` turns them all into log lines.
- **Payment ledger** (`$ledger`): `FileLedger` for one host, `RedisLedger` (ext-redis) for several. A retry of
  a paid request gets what it already bought; `ReceiptLookup` settles or frees `settlement_pending`;
  `Reconciler` checks open payments against the chain from a cron. `$sensitive` keeps a request's answer out
  of the ledger.
- **Screening lists**: `AddressList` (your own, or one another site publishes, with `AddressListSync`),
  `ChainalysisApi`, `ChainalysisOracle`, `PayeeAllowlist` (the buyer pays only listed wallets). `ListSync`
  is the interface for keeping any of them fresh.
- **Seller hooks** on `X402McpMiddleware`: `$validate`, `$payer` and `$duration` (refuse before the payment
  is read, check who pays, refuse a signature that would expire before the settle).
- **Job mode** (`Quebracho\X402\Job`): the state machine, store and reconciler for paid work that takes
  longer than one request. See [Job mode](README.md#job-mode).
- **Networks**: Base mainnet (`eip155:8453`) and Solana mainnet are built in. `Networks::add()` takes the
  token's blacklist function (`$blacklist`).
- `PaymentRecord`: one JSON line per sale and failed settlement, for your books.
