# 0001. Stripe Connect instead of self-built escrow

**Status:** Accepted, not yet implemented (planned)

## Context

In the planned design, employers pay for credits up front and merchants are
paid later when employees redeem (today no money moves; see Consequences). Someone has to hold that money in between and move it to
many small merchants. Holding other people's money ourselves would mean
money-transmitter licensing, bank partnerships, merchant identity checks
(KYC) and payout reconciliation. A student MVP cannot responsibly take that on.

## Decision

Use **Stripe Connect** (test mode for the MVP) to hold funds and pay merchants.
Each merchant gets a connected account. Local Goods keeps only the
**entitlement ledger**, which records who is owed what, and enforces the
program's rules. It never holds the cash itself.

## Consequences

- Stripe handles licensing, KYC, payouts and the bank side of things.
- The append-only credit ledger
  ([`CreditLedgerEntry`](../../app/Models/CreditLedgerEntry.php)) stays the
  source of truth for balances, and Stripe transfers will be reconciled
  against it.
- Redemption Fraud Detection will gate the Stripe transfer: a held redemption
  produces no transfer until a person clears it.
- Stripe takes fees on each transfer, and we depend on one vendor.
- **Today no Stripe code exists.** Redemptions update the ledger only, and no
  real or test money moves.
