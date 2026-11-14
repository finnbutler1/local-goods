# 0044. The BID annual program fee: no free pilot year, and charged in Stripe test mode

**Status:** Accepted, implemented (2026-11-13). Built and tested; switched off by default (`BID_PROGRAM_FEE_ENABLED`). Supersedes the "free first year" part of ADR 0043.

## Context

ADR 0043 put an annual BID program fee into the projection only: Small
$1,000, Medium $2,500, Large $5,000 a year by member businesses, with the
Golden Triangle (Large) free for its first 12 months. Two days before the
code freeze Finn made two changes:

1. **No free pilot year.** Every district, the Golden Triangle included,
   pays its tier fee from its program start date.
2. **Real charging in Stripe test mode**, built the way the employer
   platform fee was (ADR 0042): behind a switch, off by default, priced on
   the server, recorded only from a verified webhook, kept apart from
   customer funds, and checked by solvency, the sweep and reconciliation.

## Decision

### Projection

`bid_program_free_months` is removed from `config/projections.php`. Each
district's fee is a twelfth a month from its launch month, the pilot
included. `bid_fee_tiers` gains a numeric `min_businesses` (0, 1,000,
4,000) so the live charge reads its price from the same list.

| Base case | Before (12 free months) | After |
|---|---|---|
| Golden Triangle only, break-even | not within 72 months | not within 72 months |
| DC-wide, break-even | month 54 | month 54 |
| National, break-even | month 49 | month 49 |
| Golden Triangle year 1: BID fees / revenue | $0 / $25,886 | $5,000 / $30,886 |
| Golden Triangle year 2: BID fees / revenue | $5,000 / $78,676 | unchanged |
| Golden Triangle years 3 and 4: BID fees / revenue | $5,000 / $100,580 each | unchanged |
| Peak cash needed, base: Golden Triangle / DC-wide / national | $693k / $1.09M / $3.35M | $688k / $1.09M / $3.35M |
| What it takes, one BID: employees / break-even employer fee | 5,261 / $13.16 | unchanged |

The break-even months don't move: the extra $5,000 lands in year 1, long
before monthly EBITDA turns positive. Projection, not results.

### Charging

- **Switch.** `BID_PROGRAM_FEE_ENABLED` must be exactly `true`; unset means
  off everywhere. Never set in a deploy file, workflow or seeder
  (`BidProgramFeeTest`). It charges only in Stripe test mode
  (`BidProgramFee::charges()`); unusable tiers stop a deploy
  (`ProductionConfig`). Off: no button, the pay route answers 404, and the
  pages say "planned, not charged in the app".
- **District details.** One district exists in the app (Golden Triangle).
  Two new nullable columns, `districts.member_businesses` and
  `districts.program_starts_on`, are empty until the platform admin sets
  them on the Program Fee page, with a reason
  (`DistrictProgramSettings`). Each change writes an append-only
  `district_program_changes` row: who, when, old → new, why. The BID never
  picks its tier. The start date is fixed once any year is paid.
- **Program years.** Year 1 runs from the start date for a year, in DC time;
  nothing is due before it (`BidProgramFee::programYear`).
- **Price.** The tier's fee for the district's member count, copied onto the
  payment when it is made (`BidProgramFees::request`). Nothing the browser
  sends decides it.
- **Once per district per program year.** `bid_program_fee_payments.fee_key`
  (`"<district id>:<year>"`) is unique. The request locks the district row
  first (a new lock, held alone, so the lock order elsewhere is unaffected),
  then reuses the year's row or makes it. A double click, a retry or a
  second tab gets the same row and the same open Checkout. An expired
  Checkout gets a new token and a fresh Checkout on the same row, so an
  abandoned attempt never blocks the year; a complete one (a payment on its
  way) refuses a second attempt.
- **Checkout.** Stripe's hosted Checkout (a redirect, no new JavaScript),
  `mode: payment`, no `payment_method_types` (card, Apple Pay and Google
  Pay come from the Stripe Dashboard, as for credits). Metadata
  `lg_bid_fee_token`, `lg_bid_fee_id`, `lg_district_id`, `lg_program_year`
  and the environment tag, on the session and the PaymentIntent.
- **Webhook.** Same controller (signature, live-mode refusal), same
  other-environment check and `stripe_events` dedupe. A
  `payment_intent.succeeded` carrying `lg_bid_fee_token` is recorded only
  for the row's current token and only if amount, currency, payment id,
  district and program year all match; otherwise nothing is written. The
  row flips pending → paid once (a replay or a second success writes
  nothing) and one ledger line is written.
- **Books.** The line is `bid_program_fee` on the platform account, linked by
  the new nullable `credit_ledger_entries.bid_program_fee_payment_id`. It is
  Local Goods' own money: it is on no employer, employee or merchant account,
  so customer funds are unchanged. The solvency rule counts it in money in
  hand and Stripe money in (as it is already in operating funds), so the
  rule still balances exactly; `recordsAgree` checks paid years against
  their lines. It is sweepable to Local Goods Operating like the other fees.
  Reconciliation matches fee payments both ways, adds them to the expected
  platform balance, and checks the ledger lines.
- **Not refundable in the app.** There is no refund path; a platform admin
  can only write a note on a payment. A refund made by hand in the Stripe
  dashboard is recorded nowhere and flagged by reconciliation.
- **Who.** A BID admin pays (gate `pay-bid-program-fee`). The shared demo
  BID admin may pay in test mode, capped at
  `demo.bid_program_fee_cap_cents` ($5,000, the Large tier); once a year is
  paid, nobody can pay it again until the next year. The platform admin
  sets the district's details (`set-district-program`) and writes notes
  (`note-bid-program-fee`). Judges and the public judge link see the page in
  words, without amounts or buttons, and every POST is refused.

### Migration

`2026_11_15_000001_add_bid_program_fee` is additive only: the two district
columns (empty), the two tables (empty) and the one ledger column (empty).
It writes no row (`BidProgramFeeMigrationSafetyTest`). Snapshot before
deploying: `before-bid-program-fee`.

## Consequences

- Where the Money Is names the fee as a source of earned money (figures for
  staff only). The "How Local Goods makes money" box says "planned, not
  charged in the app" until the switch is on with Stripe test keys, then
  "charged in Stripe test mode: no real money".
- `bid_program_fee_payments` is a demo table (it has ledger lines); the
  districts and their change log are not.
- Tests: `BidProgramFeeTest`, `BidProgramFeeChargeTest`,
  `DistrictProgramSettingsTest`, `BidProgramFeeBooksTest`,
  `MysqlBidProgramFeeLockTest` (MySQL CI job), `BidProgramFeeMigrationSafetyTest`,
  `AdminHomeLiveDataTest`, `BusinessModelTest`, `BusinessModelPageTest`.
- Before switching it on live: Finn sets Golden Triangle's member count and
  program start date on the Program Fee page, then sets the variable in
  Beanstalk. Switch off by unsetting it.
