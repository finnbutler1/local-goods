# 0043. An annual BID program fee and an unspent-credit estimate, in the projection only

**Status:** Accepted, implemented (projection and wording only, 2026-11-13). No charging code.

## Context

The business model (ADR 0030) assumed a one-time $2,500 start-up fee for
each new district, borrowed from a 2013 reference model. Finn changed the
revenue model close to the code freeze:

1. Employers: the per-participating-employee platform fee stays exactly as
   built (ADR 0042, switched on in Stripe test mode on 2026-11-12). It is
   the main revenue line.
2. BIDs: an annual program fee instead of the start-up fee, set by
   district size, free during the pilot. Planned, not charged in the app.
3. Merchants: no fee during the pilot. Later, a small fee per sale only
   once the program shows it brings them new sales (1.5% kept as the
   assumption). Independent merchants run on thin margins and already pay
   card processing.
4. Unspent credits: shown as an estimate, never counted.

What the product does today with credits that are never spent: they don't
expire, and nothing scheduled touches them. Credits an employer bought but
hasn't given out stay on its balance and can be refunded only if HR asks
and a platform admin approves (`CorrectionDecisions::approveEmployerRefund`,
ADR 0028). Credits given to an employee stay in that employee's wallet;
no code returns them to the employer (the only path back is a held
redemption denied as suspected abuse).

## Decision

**BID annual program fee** (`config/projections.php`):

| Tier | Member businesses | Fee a year |
|---|---|---|
| Small | under 1,000 | $1,000 |
| Medium | 1,000 to 3,999 | $2,500 |
| Large | 4,000 or more | $5,000 |

- The same tier in the low, base and high cases (no mapping to cases).
- The Golden Triangle (nearly 6,000 businesses, the source already cited
  for `merchants_per_bid`) is Large: `bid_program_fee_pilot` $5,000. It is
  the only district with a free first year (`bid_program_free_months` 12),
  so it pays from month 13.
- The model has no size for other districts, so each uses Medium
  (`bid_program_fee_other` $2,500), paid from its launch month.
- Each paid year is spread as a twelfth a month, so there are no lump
  months.
- The BID's share of the employer fee stays planned and display only.

**Unspent credits (estimate, not counted as revenue).**
`unspent_never_spent_share` = 8% of credits issued, capped at the share
not spent in the month (`1 − utilization`), so 5% in the high case. The
share spent (85% base) stays the share spent in the month the credits are
given; most of the rest is spent later. The estimate is shown by year next
to revenue (P&L, README table, MODEL.md, CSV) and is never added to
revenue, profit or EBITDA, and never moves the break-even month
(`BusinessModelTest`). Depending on state law, unspent balances may belong
to the employer, the employee or the state (unclaimed property), so Local
Goods doesn't rely on them. Local Goods charges no dormancy fees.

## Results (base case)

| | Before | After |
|---|---|---|
| Golden Triangle only, break-even | not within 72 months | not within 72 months |
| DC-wide, break-even | month 56 | month 54 |
| National, break-even | month 50 | month 49 |
| What it takes, one BID: participating employees | 5,341 | 5,261 |
| What it takes: break-even employer fee | $13.54 | $13.16 |

Projection, not results.

## Consequences

- Only the projection calculator, its config, pages and docs changed. No
  fee code, ledger, Stripe, migration or database change; no BID or
  merchant fee is charged in the app.
- Fee and estimate figures appear only where `PublicClaimsTest` already
  allows them: the signed-in Business model page and the README's
  Business model section, next to "Projection, not results".
