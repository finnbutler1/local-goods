# 0017. BID impact numbers come from the ledger and roll up the HR dashboards

**Status:** Accepted, implemented. Builds on [0012](0012-one-ledger-three-account-kinds.md) and [0014](0014-employer-program-pending-purchases-and-initials.md).

## Context

The BID introduces us to employers, so it needs one page that shows what
the program did for the district. The employer/HR dashboard already counts
engagement (`EmployerMetrics`). If the BID page counted the same things a
second way, the two would drift and a BID admin and an HR lead could quote
different numbers for the same month. Impact claims like "every dollar
spent locally is worth 3x" are often repeated without a checkable source.

## Decision

- **The ledger is the source.** Spending is the sum of `merchant_earned`
  ledger entries: the one entry `CreditLedger::creditMerchant` writes when
  a redemption clears, never while it is held. Held and denied redemptions
  are never counted; held amounts are shown separately.
- **Same dates as HR.** Each entry is dated by when its redemption
  happened, in Washington, DC time, not when a person cleared it, so a
  held-then-approved redemption lands in the same week on both pages.
  Calendar month so far, and 8 Monday weeks (`EmployerMetrics::TREND_WEEKS`).
- **Same definitions, reused.** `App\Services\BidImpactMetrics` gives
  spent, merchants reached, employees participating and employers
  participating, for this month and since launch. The employer rollup is
  `EmployerMetrics::for()` for each employer, unchanged. Tests check that
  the BID totals equal the ledger and that this month's numbers and every
  week equal the employer dashboards added up.
- **Counts, never names.** Employees are only counted. The page and the
  landing strip show no merchant names either; real merchant candidates
  never appear.
- **Where it shows.** `/bid-admin/impact` for BID and platform admins
  (judges read-only), and a strip of since-launch totals on the public
  landing page labelled "Demo data: all people and transactions are
  fictional." Both have an empty state for when nothing has cleared.
- **No multiplier claim.** No "3x" anywhere. One clearly boxed estimate,
  labelled "Estimate from an outside study, not measured by Local Goods":
  Civic Economics, "Indie Impact Study Series: National Summary Report"
  (with the American Booksellers Association), data from 2011–2012, 134
  businesses in 10 U.S. communities, none in DC. Independents
  recirculated 52.3% of revenue locally, chains 15.8%. The page says "If
  the national study's rate held here, about $X of this spending would
  recirculate locally", with X = spending since launch × 52.3%. The rate
  and source live together in `config/impact.php`.

## Consequences

- No migration: everything is read from existing tables.
- Any new engagement number goes into `EmployerMetrics` first, so both
  pages get it with the same definition.
- The estimate is only as good as a 2012 national study; the page says so.
  Replacing it with a measured local figure would need real data, not demo
  data.
