# 0042. An employer platform fee per participating employee, built and switched off

**Status:** Accepted, implemented. Built and tested; switched on in Stripe test mode on 2026-11-12 (no real money) on the live demo (see "Switched on" below).

## Context

The business model (ADR 0030) earns most of its revenue from an employer
platform fee: a monthly amount per participating employee, paid on top of
the credits (`employer_platform_fee` in `config/projections.php`, base
case $6). The live product only had the percent fee from ADR 0021
(`PLATFORM_FEE_PERCENT`, off). Finn wanted the per-employee fee built and
fully tested now, switched off by default, so turning it on later is a
decision about a setting and not new money code.

It is money code, so it had to leave the solvency rule (ADR 0033), the
append-only ledger, the fraud gate (ADR 0016), the merchant fee (ADR 0029)
and the percent fee's own code as strict as they were. No BID payout or
BID initiation fee is part of it.

## Decision

**Off by default.** `EMPLOYER_PLATFORM_FEE_ENABLED` turns it on only when
it is exactly `true`; unset (the default, in every environment including
production) means off. No deploy file, workflow or seeder sets it
(`EmployerPlatformFeeTest`). Off, nothing changes anywhere: purchases,
Checkout, the ledger, reconciliation and every page are as before, apart
from one display-only box on the employer dashboard (below).

**Price.** The base `employer_platform_fee` from `config/projections.php`,
in cents ($6.00), so the product and the business model use one figure.
It is copied onto the purchase when the purchase is requested, so changing
the projection never changes a purchase already made. A price that is
missing, 0 or above $50, or this fee on together with `PLATFORM_FEE_PERCENT`
(an employer would see two platform fees), stops a production deploy
(`app:check-production-config`) and charges nothing.

**Who counts.** `App\Support\ParticipatingEmployees` is the only
definition. MODEL.md defines participating employees as "employees who
receive credits each month". The fee is charged at the first purchase of a
month, usually before that month's credits go out, so it counts the
previous calendar month, in arrears: the different employees of that
employer who received credits (any occasion) that month, in DC time. An
employer's first month has no fee. No participants, no fee.

**When it is charged.** In `CreditLedger::requestPurchase`, after the
employer lock the method already takes first (lock order: employer, then
everything else). If the fee is on, no other pending or paid purchase
holds this month's `employer_fee_key` (`<employer id>:<YYYY-MM>`), and
last month had participants, the purchase gets `employer_fee_cents` =
participants × price, with the count, price, month and key stored beside
it. The key is unique, so an employer pays at most one fee a month even if
two requests race; on MySQL the employer lock already makes the second
request wait (`MysqlEmployerFeeLockTest`). Cancelling a purchase
(`CreditPurchase::cancelIfPending`, the only way one is cancelled) clears
its key, so the next purchase that month carries the fee instead.

Known gap, which can only undercharge: if the purchase carrying the fee is
still pending when a later one is paid, and the first is then cancelled,
the fee moves to the next purchase that month. If there is none, that
month goes unbilled. It never charges twice.

**Checkout.** A separate line item, "Platform fee for November 2026: 38
participating employees × $6.00", after the credits line, and one more
sentence in Checkout's message: the fee is Local Goods' own revenue, not
credits, and can't be refunded in this version. Card, Apple Pay and Google
Pay stay a Stripe Dashboard setting. `totalCents()` includes the fee, so
the webhook refuses a payment that doesn't cover credits + fee.

**Ledger.** A new entry type, `employer_platform_fee`, on the platform
account, written by `CreditLedger` in the same transaction as the purchase
entry, and only when the purchase is paid: `payPurchase` (simulated) or
`markPurchasePaidByStripe` (a verified `payment_intent.succeeded`). A
failed payment, an expired Checkout or a cancelled purchase writes
nothing. `CreditLedgerEntry::PURCHASE_FEE_TYPES` lists both purchase fees
for the few places that sum them.

**Solvency, reconciliation, sweeps.** The new type is a platform-account
(operating funds) line and a money-in-hand line (`LedgerTranches`), so
customer funds rise by the credits only, while money in hand and
operating funds both rise by the fee. The rule itself is unchanged.
`SolvencyCheck` counts it with the other purchase fee, and its cross-check
compares paid purchases' credits + both fees with their lines.
`StripeReconciliation` counts it among platform fees.
`OperatingSweeps::stripeEarnedUnswept` counts it on Stripe-paid purchases,
so it is swept to Local Goods Operating the way the other fees are
(ADR 0034). Where the Money Is names it ("N credit purchases paid the
monthly employer platform fee"), with figures for staff only.

**No refunds.** Not supported in this version. Refunds already cap at the
credits (`refundableCents`, `unclaimedCents` use `amount_cents`), and the
fee never funds a merchant transfer, with no change to that code. The
employer is told on the buy form, above Checkout, on the purchase row and
in Checkout itself.

**What the employer sees.** On: the buy form says whether this purchase
adds this month's fee and how much; the paying state and the purchase row
show "Credits $X + platform fee $Y (…) = total $Z"; the confirmation names
the fee and its month. Off: a display-only box, "Planned platform fee (not
charged during the pilot)", labelled "Projection, not results", with the
price and what last month's participants would come to. It links to
`/business-model#assumptions` only for viewers who may open that page
(judges viewing as the employer, BID and platform admins). The employer
login gets the same sentence without a link, because the business model
page stays closed to it. `PublicClaimsTest` removes only that labelled
element before its fee-figure check, and checks that it appears only on
the employer dashboard.

## Switched on (2026-11-12)

Finn switched it on on the live demo, in Stripe test mode, by setting
`EMPLOYER_PLATFORM_FEE_ENABLED=true` in Beanstalk (no deploy file or
seeder sets it; the default is still off). To switch it off, unset it: a
configuration change, no redeploy. Purchases already made keep the fee
they carried.

Before switching it on, the demo activity generator's own purchase was
checked with it on (`DemoActivityEmployerFeeTest`): it carries the
month's fee, Stripe's test card is charged credits + fee, the webhook
writes one platform line, reconciliation matches, and a refused payment
cancels the purchase and passes the fee to the next one. No money code
changed.

Every page that says whether the fee is on reads the setting
(`EmployerPlatformFee::status()`): on, "switched on in Stripe test mode:
no real money"; off, "built and tested; switched off during the pilot".
With it on, the employer dashboard's fee lines (Buy credits form, a
purchase's breakdown and its row) carry `data-employer-fee-live` and their
money mode ("Stripe test mode: no real money"); `PublicClaimsTest` skips
only those labelled lines, and `PublicClaimsEmployerFeeOnTest` runs the
whole sweep with the fee on. `/security` names no fee either way.

## Consequences

- One additive migration (five columns on `credit_purchases`, 0 or empty
  on every row). Deploy snapshot `before-employer-fee`.
- Turning it on is `EMPLOYER_PLATFORM_FEE_ENABLED=true` in Beanstalk, with
  `PLATFORM_FEE_PERCENT` unset. It needs Finn's say-so; nothing in the repo
  does it.
- The BID partnership's display-only revenue share (ADR 0031) already
  counts every platform line tied to an employer in the district, so with
  the fee on it would include it. Nothing pays a BID.
- Tests: `EmployerPlatformFeeTest`, `ParticipatingEmployeesTest`,
  `EmployerPlatformFeeChargeTest`, `EmployerPlatformFeeDisplayTest`,
  `MysqlEmployerFeeLockTest`, `EmployerFeeMigrationSafetyTest`; changed:
  `LedgerTranchesTest` (lists the new type), `PublicClaimsTest` (the
  scoped exception), `BuyCreditsFlowTest` (its "never says fee" check
  skips the planned-fee box), `MakesCorrections::assertLedgerInvariant`
  (counts the new column).
