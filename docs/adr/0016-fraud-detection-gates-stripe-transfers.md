# 0016. Redemption Fraud Detection gates Stripe transfers

**Status:** Accepted, implemented. Builds on [0003](0003-fail-closed-fraud-detection.md) and [0015](0015-stripe-connect-test-mode-separate-charges-and-transfers.md).

## Context

ADR 0015 sent a Stripe test-mode transfer for every Stripe-funded cleared
redemption and left `TransferGate::allows()` as the place a fraud gate
would sit. It only checked "approved and earned", so anything that
flipped a row to approved would have been paid. A person's approval or
rejection was recorded on the redemption, but without a reason or a copy of
what the AI had said, and a rejection always handed the credit back to the
employee, even when the reviewer thought it was collusion.

The fraud prompt, signals, model and eval data are frozen (prompt
`8d5e1e70`, holdout_3 locked). This decision only builds the gate around
the AI; it changes nothing the AI sees or decides.

## Decision

- **One gate for all money.** `TransferGate::allows()` re-reads the row and
  says yes only if it is approved, its merchant earning is in the ledger,
  and either Redemption Fraud Detection cleared it itself (a real AI
  answer, no person involved) or a person recorded an Approve after a hold.
  Held, denied, unreviewed and seeded rows never pass. An AI failure is
  always a hold, so it passes only after a person approves it.
  `StripeFunding::claim` asks before making a payout row and
  `MerchantPayouts::sendOne`, the only caller of `createTransfer`, asks
  again just before calling Stripe. `FraudGateTest` checks that by source
  scan and by running every sending flow with the gate shut.
- **Decisions are their own append-only record.** `redemption_decisions`:
  approve or deny, a required reason (5 to 500 characters), who (user,
  name, role), when, where denied credit went, and the AI's original
  decision, score and `scored_by`. A unique index allows one per
  redemption, so a double click or two tabs can't decide twice. Only a BID
  or platform admin can be the decider (model hook and `RedemptionPolicy`).
  The migration backfills one row for each redemption already decided
  ("Decided before reasons were recorded.").
- **Reviewer decisions are not eval labels.** Nothing in the fraud eval,
  `/eval` or adjudication reads `redemption_decisions`, and
  `fraud_eval_cases.csv` is never written. A decision is made with
  different information (the merchant, the receipt) and for a different
  purpose; mixing them in would let operations quietly change the
  headline.
- **Where denied credit goes.** Deny asks why:
  - *Suspected abuse*: the credit goes back to the **employer's** paid
    pool (`review_denied_to_employer`, one employer-account entry). Giving
    it back to a suspected colluder would let them try again.
  - *No purchase happened* (a mistaken or duplicate tap): it goes back to
    the **employee** (`review_rejected`, as before).
  Either way it's one new ledger entry; the original debit stays. The
  ledger invariant is unchanged.
- **One idempotency key per redemption.**
  `lg-transfer-<instance>-r<redemption>-<row created>-<attempt>`. The
  instance id keeps local and production apart in a shared sandbox; the
  row's time keeps ids reused after a Reset demo apart; the attempt number
  only goes up after Stripe definitely refused (so no transfer exists). A
  timeout retries with the same key.
- **Audit trail on screen.** `Redemption::auditTrail()` gives each
  redemption's path, e.g. "AI held (risk 88) → approved by BID admin S.T. ·
  Oct 17 → transferred tr_…", on the review page and on the merchant's
  dashboard (redemptions received and payout history). Employees and staff
  are shown by initials.
- **A collusion example for admins.** "Run a collusion example" makes one
  synthetic redemption at Farragut Grind with the credit-to-cash shape (a
  round amount, most of a seeded synthetic employee's balance, far above a
  café's usual ticket) and runs it through the normal path and the real AI
  check. Never Jordan Rivera; nobody new is created. Judges never see it and
  can't post to it (role middleware, `BlockJudgeWrites`, a gate).

## Consequences

- With the AI faked, the tests prove what a hold does to money, not that
  the real AI catches a pattern. The seed's planted collusion patterns
  look ordinary one redemption at a time, and pattern B (three people at
  one salon within minutes) isn't visible in the per-redemption signals at
  all. Catching it needs a cross-redemption check: a signal change, so a
  new prompt version and a fresh holdout, not part of this decision.
- The collusion example uses the real AI, so it can clear. The page then
  says so ("an honest miss") and the gate lets it pay like any cleared
  redemption.
- Every collusion example spends one API call; the route is rate-limited.
- One migration (new table, one column with a default, backfill). Take an
  RDS snapshot before deploying it.
