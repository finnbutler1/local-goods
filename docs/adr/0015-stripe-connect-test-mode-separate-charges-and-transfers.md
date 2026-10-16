# 0015. Stripe Connect in test mode: separate charges and transfers

**Status:** Accepted, implemented. Builds on [0001](0001-stripe-connect-instead-of-self-built-escrow.md).

## Context

The money loop (ADR 0014) moved the ledger but no money: "Pay now" and
merchant payouts were simulated. ADR 0001 chose Stripe Connect so Local
Goods never holds customer funds (that would be money transmission). The
demo has to keep working with no keys (CI, tests), must never present a
simulated payment as a real one, and must not let seeded or simulated
history leak into Stripe.

## Decision

- **Separate charges and transfers.** The employer pays the platform
  through Stripe Checkout; the PaymentIntent's `transfer_group` is the
  purchase reference. Each Stripe-funded cleared redemption becomes its own
  Transfer to the merchant's Express account, with the same
  `transfer_group` and `source_transaction` = the purchase's charge.
  Destination charges don't fit: at payment time we don't know which
  merchants the credits will be spent at.
- **The ledger is written only from verified facts.** A purchase reaches
  the ledger only from a signed `payment_intent.succeeded`; a payout only
  when Stripe returns a transfer id (or a signed `transfer.created`).
- **Two channels, never mixed.** A redemption is Stripe-funded only if it
  cleared in Stripe test mode and a Stripe-paid purchase still has room
  (oldest first). Everything else stays simulated. Owed money is shown
  split: "Owed via Stripe test mode" and "Owed, simulated".
- **One named gate.** `TransferGate::allows()` is the only rule for "may
  this redemption be paid out" and is where Prompt 8's fraud gate will sit.
- **Transfers after commit.** Clearing a redemption never waits on, or is
  undone by, Stripe. Failures are recorded on the payout row and retried;
  timeouts reuse the idempotency key.
- **The webhook has no session.** It is outside the `web` group, so CSRF
  and the judge write-block don't apply and no user exists there; the
  signature is the only authentication. Events are de-duplicated by id in
  the same transaction as their changes.
- **Test mode only.** `StripeMode` accepts only `pk_test_`/`sk_test_` keys
  and refuses live events. Missing, partial or live keys mean simulated mode.
- **Express accounts** for the fictional demo merchants only; real merchant
  candidates are never Merchant rows. Reset demo keeps their account ids.

## Consequences

- Every money page shows a "Stripe test mode" or "Simulated" badge, and
  every purchase and payout row says how it was actually paid.
- `/admin/reconciliation` compares Stripe-funded activity one by one with
  Stripe. The balance never matches exactly (Stripe keeps fees; a reset
  clears our rows but not Stripe's), so it is shown for information.
- Per-redemption transfers cost one Stripe call each. Fine for a demo; a
  real program would batch daily per merchant.
- Stripe-funded matching is pool-level, not per credit: a redemption by an
  employee whose allowance came from a simulated purchase can still be paid
  from Stripe money. The cap (never more out than came in) holds.
- Reversed transfers are owed again but need a person; there is no screen
  for that yet.
- Two webhook endpoints in production (account events and connected-account
  events) because Stripe signs them with different secrets.
