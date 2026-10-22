# 0022. Platform-set-up Stripe test accounts for the fictional demo merchants

**Status:** Accepted, implemented.

## Context

Only Farragut Grind could receive Stripe test transfers. It is the only
demo merchant with a login, and Stripe onboarding (ADR 0015) is started by
the merchant's own login on Stripe's Express pages. The other seven
fictional merchants have no login, so their Stripe-funded payouts waited
for onboarding forever. That hid half of the money loop from the demo.

The first plan was to create Express accounts prefilled with Stripe's
test values. A real test-mode call (2026-10-22) showed Stripe refuses
this: "You cannot accept the Terms of Service on behalf of accounts where
requirement collection is owned by Stripe"
(`tos_acceptance_on_behalf_not_allowed`). An Express account therefore
always waits for someone to click through Stripe's form.

## Decision

- A platform-admin action on `/admin/reconciliation`, **Connect demo
  merchants to Stripe (test mode)** (`admin.stripe.demo-merchants`,
  throttled, `App\Services\Payments\DemoMerchantStripeSetup`).
- It creates Accounts v2 accounts with **no Stripe dashboard**
  (`dashboard: none`), so the platform may fill in the details and accept
  the terms. It uses Stripe's test values (DOB 1901-01-01, SSN 000000000,
  `address_full_match`) and adds Stripe's test bank account
  (`btok_us_verified`). It can receive transfers at once; payouts follow
  within about 30 seconds.
- It is only for **fictional demo merchants with no login**: names in
  `DatabaseSeeder::DEMO_MERCHANTS`, and never a name that also appears in
  `data/merchant_candidates.csv` (it fails closed if that list can't be
  read). A merchant with a login keeps **Start Stripe onboarding** on
  Stripe's Express pages. Real businesses never get a Stripe account.
- It works only in Stripe test mode (`StripeMode` refuses live keys). It
  is idempotent: it skips a merchant that already has an account, and
  reuses the same idempotency keys. Every account carries the environment
  labels plus `lg_demo_setup=1`.
- Honest labelling: `merchants.stripe_set_up_by_platform` (one additive
  migration, kept across Reset demo) makes the status read **"Demo account
  set up by Local Goods in test mode"** on the merchant dashboard, the BID
  roster and reconciliation. The merchant dashboard adds that real
  merchants always onboard themselves through Stripe's Express pages.

## Consequences

- A cleared, Stripe-funded redemption at any demo merchant sends a test
  transfer to that merchant's own account, through the same fraud gate.
  Reconciliation is unchanged and still matches.
- Reset demo keeps the accounts (matched by name), so the action is needed
  once per environment, and again only if a merchant shows "Not
  connected".
- These seven accounts have no Stripe dashboard to log in to. That is
  acceptable: nobody runs these fictional businesses.
