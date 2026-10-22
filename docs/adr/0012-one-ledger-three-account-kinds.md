# 0012. One ledger with three kinds of account

**Status:** Accepted, implemented (Stripe transfers not yet built). Extended by
[ADR 0021](0021-one-step-buy-credits-and-a-platform-fee-account.md): a fourth,
platform account holds the platform fee (off by default).

## Context

The credit ledger only had employee accounts. Credits appeared in a wallet
from nowhere, and nothing recorded what a merchant was owed. Merchant payouts,
and the fraud gate's promise that "no money moves until a redemption is
cleared", need the money on both sides of the wallet.

## Decision

- **One append-only ledger, three kinds of account.** Each entry in
  `credit_ledger_entries` belongs to exactly one of an employee, the employer
  or a merchant (checked when the entry is created). Every balance is the sum
  of that account's entries; none is stored.
- **Movements.** Each has its own record, and its ledger entries are written
  in the same transaction (`App\Services\CreditLedger`):
  - *Purchase* (`credit_purchases`): employer +X.
  - *Allocation* (`credit_allocations`): employer −X and employee +X (an
    `allowance` entry).
  - *Merchant earning*: merchant +amount, written **only when a redemption
    clears**, either automatically or when a person approves it. A held
    redemption earns the merchant nothing, and a rejected one never does.
  - *Payout* (`merchant_payouts`): merchant −X, never more than it is owed.
    Until Stripe Connect is built, demo payouts have status `simulated`.
- **What has to add up.** Purchases − payouts − redemptions under review = the
  sum of every ledger entry. Held credits are reserved in the employee's
  wallet but credited to no one, so the fraud gate shows up in the books.
- **Safe on live data.** The migration only adds nullable columns and lets
  `employee_id` be empty. Existing entries keep their employee and amount, and
  `LedgerMigrationSafetyTest` checks balances before and after on both SQLite
  and MySQL. A production rollback refuses to run if employer or merchant
  entries exist.

## Consequences

- Stripe transfers will hang off `merchant_payouts`, where
  `stripe_transfer_id` is already in place.
- Two entries for one allocation means the employee's wallet history and the
  employer's spending both come from the same ledger.
- Code that sums the whole ledger table must say which kind of account it
  means.
