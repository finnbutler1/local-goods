# 0029. A merchant fee on each sale, ready but at 0

**Status:** Accepted, implemented.

## Context

Step 8 of the October 23 plan. Judges should be able to see how Local
Goods would earn money, built the way a payments company would build it:
a fee on each merchant sale, fixed when the sale is made, held back from
the merchant's transfer, given back in proportion on refunds, and checked
by reconciliation. We have not talked to merchants about pricing yet, so
the fee charges nothing by default and stays at 0 on live and in the seed
until customer discovery. Public pages and the README carry no fee or
revenue figures.

It had to leave the rest alone: the fraud prompt (`8d5e1e70`), its
signals, model and AI input, eval data, holdouts, locks, adjudication data
and `TransferGate`. The employer-side platform fee on credit purchases
(ADR 0021, `platform_fee_cents`) is separate and untouched.

## Decision

**The setting.** A percent in basis points (0 to 1000, at most 10%) plus
an optional fixed amount (0 to 100 cents, at most $1.00) per sale. It
lives in `merchant_fee_changes`, an append-only table: the newest row is
the current fee, no row means no fee, and the table is the audit log
(who, when, old → new, reason). Only `MerchantFeeSettings::change`
writes it, only for the platform admin (`change-merchant-fee`), with a
reason, within the cap, and only against the setting the admin reviewed.
The page `/bid-admin/fees` has two steps: Review (writes nothing; shows
old → new and the fee on a $6.33 sale) and Confirm (a ticked box; refused
if the setting changed since the review). The BID admin can look; judges
see it without amounts (below). It is not a demo table: Reset demo keeps
the history.

**The math** (`App\Support\MerchantFee`, integer cents only):

    fee = min(total, intdiv(total × bps + 5000, 10000) + fixed)

The percent rounds half up to a cent. The total includes any DC sales tax
(ADR 0027). The fee is never more than the sale.

**The snapshot.** `RedemptionService::redeem` writes `merchant_fee_bps`,
`merchant_fee_fixed_cents` and `merchant_fee_cents` on the row, in the same
transaction. A later change to the setting never touches an existing
redemption, held ones included. A pay code is charged the fee the merchant
was shown: the confirm form carries the change id from the look-up, and
confirm is refused ("The Local Goods fee changed since you looked this code
up…") if it no longer matches. Existing redemptions have 0.

**The ledger** (append-only, one account per line). `CreditLedger::creditMerchant`
is where every clearing path goes (the AI clears it, a person approves it, the seed).
After `merchant_earned` it writes a pair that adds up to zero:
`merchant_fee` (merchant, −fee) and `platform_merchant_fee` (platform
account, +fee). A held sale is charged nothing until it clears, and a
denied sale is never charged. With a fee of 0 nothing is written, so the
ledger is exactly as before. The invariant (paid purchases + purchase fees
− payouts net of refund reversals − held − employer refunds = sum of every
entry) is unchanged, because each pair adds up to zero and payouts are now
net of the fee.

**Stripe** (separate charges and transfers). `StripeFunding::claim` makes
the payout row for `total − fee`, with `fee_cents = fee`. That net is what
`MerchantPayouts` transfers (metadata `lg_merchant_fee_cents` when there is
a fee), and the fee stays in the platform's balance. The purchase is still
matched on the whole sale, and `CreditPurchase::unclaimedCents` subtracts
`amount_cents + fee_cents`. So the fee money can never fund another transfer
or go back to an employer's card. A sale whose fee is all of it has nothing
to transfer, so it gets no row. Simulated mode nets the same way: the
merchant is owed the sale less the fee.

**Refunds** (ADR 0028). `CorrectionLimits::feeShare` works out the
refund's share cumulatively, like the tax:

    returned(R) = R ≥ total ? fee : min(fee, half-up(fee × R / total))
    share = returned(refunded so far + this) − fee shares already given back by counted refunds

It is stored on the correction (`corrections.fee_cents`) at approval.
Across every refund exactly the fee comes back, never more, and no single
share is more than its refund (a property test checks 500 random
sequences).
- The employee gets the whole refund.
- Stripe reverses the refund less the share. The reversal action's amount
  is that net, and `confirmReversal` checks it and then writes the lines
  for the whole refund.
- `refundRedemption` adds `merchant_fee_returned` (merchant, +share) and
  `platform_merchant_fee_returned` (platform, −share), and the
  `refund_reversal` line is the net.

A refund that is all fee (for example 1¢ crossing a rounding step) has
nothing to reverse, so it completes at once with action kind `fee_only` and
no Stripe call.

**Reconciliation.** Transfers are already net, so the expected balance
(purchases − net transfers − refunds) already includes the fees kept. The
page now says how much of it is fees. Two new checks:
- every sent transfer plus its `fee_cents` is its redemption's total, at
  the redemption's own snapshot;
- the platform account's merchant fee lines equal the fees on cleared sales
  less the shares completed refunds gave back.

With no fee every number and sentence is as before.

**Where it shows.**
- Merchant dashboard: "Local Goods fee: none right now" (or the rate), and
  Sale / Local Goods fee / You receive on each cleared redemption, each
  Stripe payout row and the pay code review.
- One sentence in the Stripe section and on the onboarding refresh page.
- The CSV: `Local Goods fee` and `You receive` after `Total`. Refund rows
  are negative; held and denied rows are blank.
- The corrections queue notes a refund's fee share.
- Reconciliation shows the fees kept.
- "Cleared sales this month" on the merchant dashboard is now net of
  completed refunds (Sales, Refunds, Net when there is one), counted in the
  month the refund completed, like the BID impact figures.

**Judges count as the public.** `PublicClaimsTest` sweeps every page as
the public judge, so `MerchantFee::showsFiguresTo` keeps fee rates and
amounts from judges (read-only users). They see the fee as "none right now"
or "on", the cap in words, and the audit log without amounts. The CSV is a
download of records and carries the columns for everyone; with the fee at
0 they read 0.00.

## Worked example: 2.5% on a $6.33 sale, through Stripe

Fee = intdiv(633 × 250 + 5000, 10000) = 16¢ (15.825 rounds up). The
merchant receives $6.17.

| Line | Account | ¢ |
|---|---|---|
| redemption | employee | −633 |
| merchant_earned | merchant | +633 |
| merchant_fee | merchant | −16 |
| platform_merchant_fee | platform | +16 |
| payout (transfer of 617) | merchant | −617 |

**Refund $2.11:** share = half-up(16 × 211 / 633) = 5¢, and Stripe reverses 206.
- refund +211 (employee)
- merchant_refunded −211
- merchant_fee_returned +5
- platform_merchant_fee_returned −5
- refund_reversal +206

**Refund the other $4.22:** everything is now refunded, so the share is
16 − 5 = 11¢ and Stripe reverses 411.

Totals: fee given back 16, reversals 617. The merchant, the platform and
the employee are all back where they started.

## Consequences

- A judge can see a real, reconciled revenue mechanism without anything
  being charged: the platform admin can turn it on in a local or scratch
  environment, and it is visible end to end.
- Live and the seed stay at 0 because nobody sets a fee there. No seeder
  writes a fee change (`MerchantFeeSeedTest`).
- Interchange revenue would need issued cards (Stripe Issuing), which the
  MVP rules out. It is not built.
- Tests: `MerchantFeeTest` (unit), `MerchantFeeMigrationSafetyTest`,
  `MerchantFeeSettingsTest`, `MerchantFeeSaleTest`, `MerchantFeeRefundTest`,
  `MerchantFeeReconciliationTest`, `MerchantFeeDisplayTest`,
  `FraudInputMerchantFeeTest`, `MerchantFeeSeedTest`,
  `MerchantClearedNetOfRefundsTest`. Test helper
  `Tests\Concerns\SetsMerchantFee`.
