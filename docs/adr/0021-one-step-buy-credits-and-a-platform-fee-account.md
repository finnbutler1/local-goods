# 0021. One-step Buy credits with embedded Checkout, and a platform fee on its own account

**Status:** Accepted, implemented. The fee is built but off (0%).

## Context

Buying credits took three steps: "Request purchase", find the pending row
in Purchases, then "Pay with Stripe (test mode)", which left for Stripe's
hosted Checkout page. HR is the buyer that gets the most polish, and this
was the clumsiest screen they had. Separately, a business model needs room
for a platform fee, but the contest demo charges none, and the ledger
(ADR 0012) only had employee, employer and merchant accounts, none of which
can hold Local Goods' own money.

## Decision

- **One step.** HR enters an amount and presses **Buy credits**. In Stripe
  test mode the pending purchase is written (still no ledger entry) and the
  page comes back with Stripe's **embedded Checkout** inside the Buy credits
  card (`ui_mode: embedded_page`, Stripe.js `endive` to match the API
  version, `createEmbeddedCheckoutPage`). The card form asks
  `employer.purchases.checkout` for the client secret; that POST makes the
  Checkout Session with the same idempotency key, environment tags and
  `transfer_group` as before. In simulated mode the same press pays at once.
- **Money rules unchanged.** Only the signed `payment_intent.succeeded`
  webhook writes the purchase to the ledger, once (`stripe_events` and the
  pending-only update). Pending credits can't be given out.
- **Clear states.** Paying (the card form), confirming ("Confirming with
  Stripe…", which asks `employer.purchases.status` every 2 seconds for up
  to 2 minutes and reloads when it changes), paid (the green toast),
  cancelled or failed (a message and **Try again**).
- **Cancel only when nothing can still be paid.** "Cancel payment" asks
  Stripe for the Checkout's status: open → expire it, then the purchase is
  `cancelled` (a new status value, never in the ledger); already expired →
  cancelled; complete → not cancelled, we wait for the webhook. "Try
  again" / "Continue paying" closes the old Checkout the same way before a
  new token opens a fresh one, so an old Checkout is never left payable
  against a token we have forgotten. A payment that still arrives for a
  cancelled purchase is refused by the webhook and shows up in
  reconciliation ("check before refunding").
- **Return address from APP_URL.** In production the `return_url` is built
  from `APP_URL` (the CloudFront address), never the request's host:
  Beanstalk refuses direct visitors (ADR 0018).
- **Platform fee, off by default.** `PLATFORM_FEE_PERCENT` (unset, blank or
  0 = no fee; up to 20, two decimals). It is fixed on the purchase
  (`credit_purchases.platform_fee_cents`) when the purchase is requested, so
  a later setting change never changes a purchase on its way. With the fee
  on: "Credits $X + platform fee $Y = total $Z" before paying, a second
  Checkout line item, and Stripe must receive X + Y. At 0 nothing mentions
  fees anywhere.
- **A fourth ledger account: the platform.** The fee is its own ledger line
  (`platform_fee`) on the **platform account**: `credit_ledger_entries.platform`
  is true on those lines and empty on every other one, and the rule
  "exactly one account per entry" still holds. The employer's balance stays
  credits only, and fees never fund merchant transfers.
- **What has to add up** (extends ADR 0012): paid purchases' credits +
  fees − payouts − redemptions under review = the sum of every ledger entry.
- **Reconciliation** compares each Stripe payment with credits + fee,
  counts fees in the ledger total, and checks that the platform account's
  fee lines equal the fees those purchases charged.
- **Safe on live data.** One migration adds `platform_fee_cents` (default
  0) and the nullable `platform` column. Nothing existing changes
  (`FeeMigrationSafetyTest`). An unset `PLATFORM_FEE_PERCENT` passes the
  deploy check; only a bad value stops a deploy.

## Consequences

- Paying by card needs JavaScript (Stripe's form is Stripe.js); without it
  the card says so.
- Stripe.js is loaded from `js.stripe.com` only in the paying state. The app
  sends no Content-Security-Policy today; if one is added (in the app or as
  a CloudFront response-headers policy), it must allow what embedded
  Checkout needs (see docs/STRIPE.md).
- Turning the fee on is a configuration change, not a code change, and
  needs no migration.

Tests: `BuyCreditsFlowTest` (the whole flow against the fake Stripe, with
the fee off and on, ending with reconciliation matching), `PlatformFeeTest`,
`StripeCheckoutTest`, `ProductionConfigTest`, `FeeMigrationSafetyTest`.
