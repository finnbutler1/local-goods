# 0028. Refunds and corrections

**Status:** Accepted, implemented.

## Context

Step 7 of the October 23 plan, and the most delicate money step so far.
Until now nothing could fix a money mistake once a redemption had cleared
or credits had been bought: a wrong amount at a café, a transfer that never
reached a merchant, or credits an employer bought and no longer needs.

It had to work the way a payments company would do it: a request, a
person's decision with a written reason, an append-only ledger, a real
Stripe test-mode action behind every fix, idempotent retries, and a
reconciliation that still matches afterwards. It also had to leave the
rest alone: the fraud prompt (`8d5e1e70`), its signals, model, eval data,
holdouts and locks, adjudication data, `TransferGate` and `RedemptionService`.

## What Stripe actually does (trial of 2026-10-24)

Before writing the webhook code, a one-off script
(`scripts/stripe-trial-corrections.php`) asked Stripe's test mode directly.
It refused anything but a test secret key, tagged every object
`lg_env=scratch, lg_instance=step7-trial` (other environment for both local
and live: the webhook ignores them and reconciliation never counts them),
wrote no database, and cleaned up afterwards: both charges refunded in
full, the transfer fully reversed, the throwaway account deleted. Its real
webhook payloads, scrubbed, are test fixtures in
`tests/Fixtures/stripe/corrections/`. What it showed:

- **Card refunds come back `succeeded` at once**, even on a payment whose
  funds are still pending; `refund.created` already carries that status, so
  it completes the refund. `refund.updated` follows (a card reference
  changes, status still succeeded).
- **`transfer.reversed` lists every reversal on the transfer, with its
  metadata** (newest first), so no extra Stripe call is needed to tell our
  refund reversals apart. **`amount_reversed` adds up** (500, then 1500);
  `reversed` turns true only when the whole transfer is reversed.
- The same idempotency key returns the same reversal or refund.
- This API version (2026-09-30.endive) refuses `payment_method_types` on a
  PaymentIntent, as it does for Checkout.

## Decision

### Requests

Employees ("Request a correction" on a cleared redemption), merchants
("Customer was charged wrongly" on a received redemption, "I wasn't paid"
on a Stripe payout) and HR ("Refund unspent credits") ask with a reason
category and an optional note. Asking moves no money. Only your own items
(anything else is a 404), 5 a minute per route, and **at most one open
request per item, enforced by the database**: `corrections.open_key`
(`redemption:42`, `payout:7`, `employer:1`) is unique and set only while a
request is open.

### Who decides

**The platform admin decides; the BID admin and judges view.** Moving money
is the operator's job; the BID is the channel partner. Every decision
(approve, reject, close) needs a written reason, which the requester sees
on the item they asked about. Personal contacts are refused automatically.

Statuses: requested → approved → processing → completed or failed, or
rejected. **Closed** ("Closed without a refund") was added so a failed
correction can never be stuck: the admin retries it (safe) or closes it.
Close waits while any step's outcome at Stripe is unknown.

### The three types

1. **Redemption refund (to the employee).** Cleared redemptions only. Menu
   orders are refunded by item; the tax comes from the redemption's own
   snapshot, never today's config, and is worked out cumulatively per
   category (tax on everything refunded so far minus tax already refunded,
   the stored line exactly once a category is fully refunded), so rounding
   across partial refunds can never give back more tax than was charged.
   Typed-in amounts are refunded by amount, up to what is left.
   - **Its transfer was sent:** a Stripe Transfer Reversal on that transfer
     for exactly the refund. When `transfer.reversed` confirms it, three
     ledger lines: employee `+r` (`refund`), merchant `-r`
     (`merchant_refunded`, the earning taken back), merchant `+r`
     (`refund_reversal`, the money came back). The merchant nets to zero.
   - **Simulated** (no Stripe transfer behind it, as for all seeded
     history): refunded at once, employee `+r` and merchant `-r`, "deducted
     from the merchant's next payout". A merchant already paid out can dip
     below zero until its next simulated payout covers it, which is how
     real processors net refunds.
   - **Its Stripe transfer hasn't gone out yet** (owed, sending, failed):
     refused until it is sent, so Stripe can never later send the full
     amount after a refund. **Reversed in the Stripe dashboard:** refused
     with "This payment was reversed in the Stripe dashboard. Sort it out
     by hand in Stripe, then record a note."
2. **Merchant adjustment ("I wasn't paid").** It **reuses the existing
   payout path**: approving sends the merchant's owed, sending or failed
   payout row through `MerchantPayouts::send`, exactly what Reconciliation
   → Retry owed transfers does. So the amount is the row's own (the
   shortfall the ledger shows, never more), the `TransferGate` still
   decides, the row's own idempotency key is used, and the ledger line is
   written on Stripe's reply with `transfer.created` as backup, like every
   transfer. The payout line carries the correction's id. A sent or
   simulated payout has nothing owed; a reversed one is refused (as above).
   No new kind of transfer and no change to `MerchantPayouts`.
3. **Employer refund of unspent credits.** Up to the employer's balance
   (credits bought and not given out). The credits are **held at once**
   (`employer_refund_hold`, like a held redemption), so they can't be given
   to employees while the refund goes through. The refund goes back to the
   payments that bought them, **newest first**: merchant transfers are
   funded oldest first, so the newest payments are the least likely to
   have been claimed. Each payment gives back at most what is left of it:
   credits only (never the platform fee), less what earlier refunds took,
   and for a Stripe payment less what merchant transfers claimed. A Stripe
   payment gets a Stripe Refund on its PaymentIntent; when
   `refund.created` / `refund.updated` confirms it, a 0-amount
   `employer_refunded` line (the hold already took the credits). A
   simulated purchase is returned at once, simulated. Closing a failed one
   writes `employer_refund_released` for the part that never went back.
   `CreditPurchase::unclaimedCents` now also subtracts refunded money, so
   refunded money can never fund a later transfer.

### The over-refund guard and idempotency

Approval is one transaction. It claims the request (only a requested one
can be decided, so two admins at once decide it once), **locks the item**
(the redemption row, or the employer row and its purchases), re-reads every
other approved, processing, completed or failed correction on it, and
refuses anything that would refund more than was paid (by amount, by item
quantity, by tax, by each payment). On MySQL the row locks make a second
approval wait (`MysqlCorrectionLockTest`, CI); on SQLite every write
transaction is IMMEDIATE, so writers already go one at a time.

Stripe is called **after** the commit, never inside it. Each action has its
own idempotency key, `lg-correction-{instance}-c{correction}-a{action}-{created}-{attempts}`;
attempts go up only after a definite refusal, so a lost answer is retried
with the same key and Stripe acts at most once. Every Stripe object carries
`lg_correction_key`, `lg_correction_id` and the environment tag.

### Webhooks

Ledger lines for Stripe refunds are written when Stripe's signed webhook
confirms, in the same idempotent handler (`stripe_events`) as before:

- `transfer.reversed` (changed): our refund reversals in the event are
  recorded first, each once. The old path, "reversed in Stripe, owed again",
  only runs for reversed money those refunds don't explain, so with no
  refunds it behaves exactly as before.
- `refund.created`, `refund.updated`, `refund.failed` (new): a succeeded
  refund writes its line once; a failed or cancelled one fails the action
  (credits stay held: retry or close).
- `transfer.created` (unchanged event): also completes a merchant
  adjustment whose row was sent.

### Reconciliation

Refund reversals are taken off the transfers the ledger counts (Stripe nets
them off too); every reversal and every card refund is matched by id and
amount both ways; refunds to employers' cards get their own row once there
are any; the expected balance becomes purchases minus transfers minus
refunds. A refund Stripe made that the webhook hasn't recorded yet is named
as such. Simulated corrections are counted, not checked. With no
corrections every number and line is as before.

### Tax

Tax collected is shown net of refunds: the tax on refunded items, from the
order's snapshot, comes off in the month the refund completed (DC time).
`MerchantTaxSummary`'s `tax_cents` still means tax added at checkout. The
CSV has a row per completed refund with negative amounts.

### Not the AI

Corrections are never sent to Redemption Fraud Detection and never change a
fraud decision or a redemption's stored AI answer. A refund raises the
employee's balance, which the AI already sees as `balance_before_cents`,
exactly as an allowance of the same amount would (a test checks they give
identical input).

## Known limitations

- **A payout reversed in the Stripe dashboard is sorted out by hand.** No
  adjustment or refund runs on it. Today's webhook also treats any manual
  dashboard reversal, even a partial one, as a full one ("owed again");
  that behaviour is unchanged.
- **Impact numbers count the original spend.** The BID impact and employer
  dashboards count cleared redemptions (`merchant_earned` lines); a later
  refund doesn't take the spend back out of them.
- **Locally, a Stripe refund stays "processing" until `stripe listen`
  forwards its event** (with the new events in `--events`).
- **Employees never paid by card,** so a refund to an employee is credits
  only; there is no card refund to an employee.
- A menu order is refunded by item only; mixing items and a free amount
  isn't offered.

## Consequences

One additive migration (`corrections`, `correction_items`,
`correction_actions`, a nullable `correction_id` on ledger entries), safe on
SQLite and MySQL (`CorrectionsMigrationSafetyTest`). Three webhook events
to add in the Stripe dashboard (`refund.created`, `refund.updated`,
`refund.failed`), on the "Your account" destination only. The demo seeds a
completed partial refund and a rejected request; the live demo, which is
never reset, starts with none and works without them.
