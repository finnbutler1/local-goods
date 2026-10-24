# 0026. Pay in person with one-time pay codes

**Status:** Accepted, implemented.

## Context

Step 4 of the October 23 plan. Until now an employee redeemed on their own
phone and showed the confirmation at the counter ("Order to go"). Standing
at a till, it is quicker and safer if the merchant confirms the payment on
her own dashboard: the employee gets a short code, the cashier types it in,
checks the amount and confirms. The goal is under 15 seconds.

It had to reuse the existing money path exactly: the same balance check,
the same Redemption Fraud Detection call with the same inputs (the prompt
`8d5e1e70`, signals, model and eval data are frozen), the same
`TransferGate` and the same Stripe test-mode transfer.

## Decision

- **Two wallet tabs.** "Order to go" is the old flow, unchanged and still
  the default. "Pay in person": pick a merchant, enter an amount or pick
  from its menu (ADR 0023), "Get pay code". Real tab semantics
  (`role="tablist"`, arrow keys, Home/End); without JavaScript the tabs
  are plain `?tab=` links.
- **Pay codes** (`pay_codes`, `App\Services\PayCodes`):
  - 6 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O, 1/I/L),
    from `random_int`. Valid for 10 minutes.
  - Tied to one employee, one merchant and one amount (and its item
    snapshot). One live code per employee: a new one cancels the old.
  - Single use. Confirming first claims the code with one conditional
    UPDATE (still active, this merchant's, not expired), so two presses can
    never make two redemptions.
  - Stored as an HMAC-SHA256 keyed with `APP_KEY` (for lookup) plus an
    encrypted copy (so the employee's phone can show it again after a
    reload). Never in a URL, never logged, never flashed back as old input
    (`dontFlash`), never in the status poll.
  - Every miss a merchant makes (unknown, another merchant's, expired,
    used, cancelled) gets the same "Code not valid." and counts towards 5
    misses a minute per merchant account, on top of the route throttles.
  - Expiry is lazy: a code past its time is saved as expired whenever
    codes are read. No scheduler.
- **Nothing moves until the merchant confirms.** Getting a code writes no
  ledger entry. Confirm calls `RedemptionService::redeem` with the code's
  amount and items; the balance is checked again there. If it no longer
  covers the amount, the code is cancelled and nothing is charged.
- **The one change to `RedemptionService`:** `redeem()` takes an optional
  `$channel` (`to_go` by default) and writes it on the row in the same
  transaction. It is display only: it never reaches `SignalCalculator` or
  the AI. A test checks the AI input is identical for to go and in person.
  Setting it afterwards would have left the row briefly wrong, after the
  transfer was already sent. `redemptions.channel` defaults to `to_go`, so
  every existing row is to go.
- **The merchant's side** (`/merchant`, "Take a payment"): Enter pay code,
  then the amount, items and the customer's initials only, then Confirm
  payment or Decline. After a lookup only the pay code's id is kept in
  the session, and Confirm and Decline only work on that id.
- **Held at the counter.** If Redemption Fraud Detection holds it, both
  screens say so in neutral words (no fraud words) with the existing
  "Under review" badge. The merchant: "Payment not completed yet…". The
  employee: their credits are set aside and come back if it isn't
  approved.
- **Cancel this payment.** If the customer pays another way, the merchant
  or the employee can cancel a held in-person payment, so it can never be
  approved later. It goes through `RedemptionReviewService`'s existing
  `decide()` path, like a "no purchase happened" denial: one
  `review_rejected` entry gives the credits back, status `rejected`, no
  transfer ever. It is recorded as `RedemptionDecision::CANCEL` with the
  merchant or employee role, not as a fraud denial. Fraud review shows
  "Cancelled by merchant" or "Cancelled by employee", and it leaves the
  pending queue. `RedemptionDecision` now accepts a cancel from that
  payment's own merchant or employee login only; approve and deny stay BID
  and platform admins only. Cancels are not eval or adjudication data.
- **The channel is shown** on the wallet's receipt, recent list and
  history, the merchant's list, Fraud review ("not sent to Redemption
  Fraud Detection") and as a split of sent transfers on reconciliation,
  which the check itself ignores.
- **Judges** see both tabs, a sample code screen and a disabled code field
  with a sample review, and can't get, confirm, decline or cancel
  anything.
- **Demo.** The seed adds one completed in-person $10.00 payment at
  Farragut Grind with its used pay code, for a history employee who isn't
  Jordan or in a planted pattern. `pay_codes` is in `DEMO_TABLES` and may
  be empty after a seed, so a demo seeded before this keeps working.

## Consequences

- One additive migration (`pay_codes`, `redemptions.channel`). Take an RDS
  snapshot before deploying.
- The employee's screen polls every 3 seconds; no websockets.
- A merchant who mistypes 5 times waits a minute, even for a right code.
- Anyone who sees a code over the shoulder can't use it at another shop,
  and at the right shop it only pays exactly the amount it was made for,
  once.
- Using the channel to catch abuse would be a new signal: a new prompt
  version and a fresh unseen holdout, with Finn's say-so.
