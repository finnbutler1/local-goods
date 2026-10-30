# 0033. Customer funds, operating funds and the solvency rule

**Status:** Accepted, implemented. Supersedes part of ADR 0028 (refunds of
simulated sales a merchant was already paid for).

## Context

Local Goods sells prepaid credits. Employers pay before anyone spends, so
until a credit is spent and the merchant is paid, the money is a promise.
That only works if:

- the money promised is always there;
- the business never pays old promises with new money;
- this is enforced in code, checkable over the whole history, and visible.

The ledger already balanced in tests (`MakesCorrections::assertLedgerInvariant`),
but only at the end of a test, with net totals, and nothing in the app
enforced it.

This had to leave alone:

- the fraud prompt (`8d5e1e70`), its signals and the AI input;
- eval data, holdouts, locks and adjudication;
- the append-only ledger.

Changes to the money services had to be minimal.

## Decision

### Two tranches

Every ledger line is in exactly one tranche:

- **Customer funds held**, a promise to pay: employer credits bought and not
  yet given out, employee balances, amounts owed to merchants and not yet
  transferred, and anything held pending a decision (held redemptions,
  employer refund holds).
- **Operating funds:** what Local Goods has earned and may spend. That is the
  platform account, only fee lines, all 0 today.

A line's tranche is its account. `CreditLedgerEntry` already refuses a
line without exactly one account, so no cent can be in two tranches or in
none. `LedgerTranches::ACCOUNT_OF_TYPE` names the one account of every
entry type; `LedgerTranchesTest` fails on a type left out, and checks
every line of the seeded and generated history.

| Type | Account → tranche | Money in hand |
|---|---|---|
| purchase | employer → customer | in |
| allocation_out, review_denied_to_employer, employer_refund_hold, employer_refund_released | employer → customer | — |
| employer_refunded (amount 0) | employer → customer | out (its correction action's amount) |
| allowance, redemption, review_approved (0), review_rejected, refund | employee → customer | — |
| merchant_earned, merchant_fee, merchant_fee_returned, merchant_refunded | merchant → customer | — |
| payout | merchant → customer | out |
| payout_reversed, refund_reversal | merchant → customer | back in |
| platform_fee | platform → operating | in |
| platform_merchant_fee, platform_merchant_fee_returned | platform → operating | — |

Held positions are worked out from the lines:

- **A held redemption** is its `redemption` line with no `merchant_earned`,
  `review_rejected` or `review_denied_to_employer` line yet.
- **An open employer refund hold** is its `employer_refund_hold` less any
  release and anything refunded.

### The rule

**Money in hand:**

- credits plus platform fees on paid purchases (simulated or Stripe);
- − payouts to merchants;
- + money pulled back from merchants (reversed transfers, refund reversals,
  simulated pull-backs);
- − refunds to employers.

**Promised:**

- **Customer funds held,** with each account counted only above zero: every
  employer's unspent credits, every employee's balance, every merchant's
  amount owed, every held redemption and every open refund hold.
- **Operating funds,** counted only above zero.

**The rule: money in hand − promised ≥ 0, at every moment.**

- We expect exactly 0.
- Above 0 means a promise is missing from the books. It is reported as
  "not every cent accounted for" and fails the check too.
- Separately, the **Stripe floor**: Stripe money out (sent transfers less
  their reversals, plus card refunds) never above Stripe money in
  (Stripe-paid purchases). So simulated money never pays for a real
  test-mode transfer.
- `recordsAgree()` also checks the ledger against the purchase, payout and
  refund records it was written from.

**Why each account above zero, not net totals:** with net totals, a
merchant below zero offsets an employee's credits. That hides a promise
with no money behind it, which is how the problem below was found.

**What it makes sure of:**

- at every recorded moment, every promise had money behind it and no
  account was below zero;
- no promise is created without money;
- simulated money never funds a Stripe transfer;
- a past failure stays visible, because the ledger is append-only and the
  check replays it.

**What it doesn't cover:**

- Stripe's real balance. That is reconciliation, in Stripe test mode only.
- Stripe's processing costs, which Local Goods pays outside the ledger.
- Money in flight between Stripe acting and its webhook.
- Which employer's money backs which redemption. It is one pool, and the
  MVP has one employer.
- Order within one second. Lines with the same timestamp are one moment.
- Two operations racing each other. The guard is a last line; the existing
  locks are the first.
- That simulated money is real.

### Checking it

- **`SolvencyCheck::now()`:** the current position, in about seven
  aggregate queries.
- **`SolvencyCheck::history()`:** one ordered pass over every ledger line
  (`created_at`, then `id`), checking the rule after each moment. It
  returns each failing window: from, to, the worst shortfall and the
  accounts below zero.
- **`php artisan ledger:check-solvency`:** prints that, stores it on
  `solvency_checks`, and exits 1 on any failure. It runs from the deploy
  hook too, non-blocking.
- **Measured on 2026-10-30, in-memory SQLite:**
  - the seed is about 1,150 lines; with 21 generated weekdays to judging
    week, about 1,970 (about 40 a weekday);
  - the full history takes about 10 ms there, and `now()` about 2 ms;
  - an index on `(created_at, id)` made no measurable difference, so none
    was added.

### Enforcing it

`SolvencyGuard` is the last line of defence behind the existing guards.

**Operations we decide** run through `SolvencyGuard::transaction`:
buying, paying for and giving out credits, redemptions, review decisions,
refund approvals, closing a correction and simulated payouts.

- The position is read **once, at the end, inside the transaction.**
  Reading before the operation's own locks would start a MySQL snapshot
  early and make its balance checks stale.
- It refuses (rolls back, `SolvencyRefused`, shown as an error toast) if
  the shortfall is bigger than the one already known.
- "Already known" is what the last stored full check found. So a problem
  already in the ledger shows as Fail on the page without stopping the
  site, but nothing may make it worse. It only ever tightens.
- Measured: about 1.3 ms and 11 small queries per redemption.

**Before a Stripe transfer**, `MerchantPayouts::sendOne` asks
`beforeTransfer`. If refused, the row stays owed, with the reason.

**Money Stripe reports** (payments, transfers, reversals, card refunds)
has already moved. It is recorded, then checked; a failure is logged and
stored, never refused.

**The demo seed** writes history out of time order. It pauses the guard and
must pass a full history check at the end, or it throws.

**The demo activity generator** (ADR 0032) checks before acting and after
each tick, and a failure stops the day. `config('solvency.tick_check')` is
`history` (the whole history every tick, the default) or `current` (the
cheap check, plus the whole history once a day after
`history_check_daily_at`). `SOLVENCY_GUARD_ENABLED=false` is the kill switch
for the guard only; the checks still report.

### Two existing paths broke the rule

Both were found by the history check before anything was changed.

1. **A simulated refund on a sale already paid out in the weekly simulated
   payout.**
   - ADR 0028 took the earning back from the merchant and let it go below
     zero, "deducted from the next payout". On the live site that payout
     never comes.
   - **Now (Finn's decision):** what the merchant is still owed *through
     simulated payouts* covers the refund first. Never what waits for a
     Stripe transfer, which would later send the full amount.
   - Any part it was already paid comes back as a **simulated pull-back**: a
     `refund_reversal` line on its latest simulated payout, labelled
     "simulated, no real money". The merchant never goes below zero.
   - Stripe reconciliation counts only reversals on sent transfers, so it
     ignores these (`SolvencySimulatedModeTest`). If the merchant has no
     simulated payout to pull from, the refund is refused
     (`CorrectionDecisions::NOTHING_TO_PULL_BACK`).
   - There was no separate merchant-facing "owed back" wording, so nothing
     there needed removing. The merchant page's "Owed, simulated" can no
     longer show a negative. The corrections page, `docs/STRIPE.md` and
     the seeder's description were reworded.
2. **The planted Pattern B ring in the demo seed.**
   - On 27 of 91 seed dates between 1 Sep and 30 Nov, the 8-week window
     gives the ring only two $150 allowances. Its five planted $60/$70
     redemptions then overdrew its three members by $80–$110 in total.
   - **Now (Finn's decision):** a planted $70 that would overdraw is made $60.
     One would be left out only if even $60 didn't fit; on none of the 91
     dates is one left out.
   - Same three people, same Thursdays, same minutes, still round amounts in
     the salon's typical range. Seeded ring redemptions are never scored by
     the AI.
   - A seed on the fixed test date (24 Oct) is byte-identical to before
     (pay codes, application tokens and the districts row's timestamp are
     random on every seed and were left out of the comparison).

### The page

`/where-the-money-is` is for judges, the BID admin and the platform admin.
It shows:

- the tranches, with "Unspent credits always remain the employer's money,
  never ours.";
- the last check: pass or fail, when, by whom, entries, moments and date
  range, and every failing window;
- the position right now.

Judges count as the public for money Local Goods earns: they see no
operating funds or money-in-hand figures (`MerchantFee::showsFiguresTo`).
Only the platform admin can press "Run the check now". It is linked from
reconciliation and the platform admin, BID admin and judge homes.

## Consequences

- One additive migration (`solvency_checks`, in `DEMO_TABLES` and
  `EMPTY_AFTER_SEED`). Snapshot `before-solvency`.
- A new money operation must go through `SolvencyGuard::transaction` (if we
  decide it) or call `afterRecorded` (if Stripe reports it). A new entry
  type must be added to `LedgerTranches`.
- If the live site already has a failing window (a refund that took a
  merchant below zero, or a seed made on one of the bad dates), the page
  will honestly say Fail for that window forever. Nothing is written to
  hide it; Finn decides whether to append a dated simulated pull-back.
- Tests: `LedgerTranchesTest`, `SolvencyRuleTest`, `SolvencyHistoryTest`,
  `SolvencyGuardTest`, `SolvencySimulatedModeTest`,
  `DemoActivitySolvencyTest`, `DemoSeedSolvencyTest`,
  `WhereTheMoneyIsPageTest`, `SolvencyMigrationSafetyTest`.
