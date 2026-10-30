# Stripe Connect in test mode

Local Goods moves money with **Stripe Connect in test mode**, using the
**separate charges and transfers** pattern. Stripe holds the money; Local
Goods keeps the ledger of who is owed what and enforces the rules. No real
money ever moves: only test keys are accepted. Decision record:
[ADR 0015](adr/0015-stripe-connect-test-mode-separate-charges-and-transfers.md).

## Why custody sits with Stripe

Taking an employer's money, holding it, and later paying it out to
merchants is, legally, **money transmission**. Doing that ourselves would
need state money-transmitter licences, a bank partner, identity checks on
every merchant (KYC) and our own reconciliation with that bank. A student
MVP can't responsibly take that on ([ADR 0001](adr/0001-stripe-connect-instead-of-self-built-escrow.md)).

With Stripe Connect the money never sits in a Local Goods account:

- The employer pays **Stripe** by card (Stripe's embedded Checkout, inside
  the Local Goods page). The funds land in
  the Local Goods *platform* balance at Stripe, which Stripe holds.
- Each merchant has its **own Stripe Express account** (created with Accounts v2). Stripe collects and
  checks the merchant's details on its own pages; we store only the account
  id (`acct_…`) and whether Stripe says it can receive transfers.
  (The fictional demo merchants with no login are the one exception: see
  [Demo merchants set up by the platform](#demo-merchants-set-up-by-the-platform).)
- When a redemption clears, Local Goods asks Stripe to **transfer** the
  amount from the platform balance to the merchant's account.

Local Goods' job is the **entitlement ledger** (an append-only list of who
is owed what, [ADR 0012](adr/0012-one-ledger-three-account-kinds.md)) and the
rules: credits only at independent BID merchants, Redemption Fraud Detection
before any payout, and a person deciding anything the AI holds.

## How money moves

| Step | In Stripe | In the Local Goods ledger |
|---|---|---|
| HR enters an amount and presses **Buy credits** | nothing yet | a **pending** purchase, no entry: the credits can't be given out |
| Stripe's card form opens on the same page | an embedded Checkout Session; the PaymentIntent's `transfer_group` is the purchase reference (e.g. `MPG-20261021-1`) | still pending, still no entry |
| HR pays; the page says **Confirming with Stripe…** | the payment succeeds | still pending until the webhook |
| Stripe sends `payment_intent.succeeded` (signed) | the charge (`ch_…`) is in the platform balance | purchase becomes **paid**, one purchase entry (plus one platform fee entry if the fee is on), once |
| HR gives credits; an employee redeems | nothing | allowance and redemption entries, as before |
| Redemption Fraud Detection clears it, or a BID admin approves it | nothing yet | merchant earns it; if a Stripe-paid purchase can fund it, a Stripe payout row is **owed** |
| Merchant has finished onboarding | a **Transfer** to the merchant's account, same `transfer_group`, `source_transaction` = the purchase's charge | payout row **sent** with its `tr_…` id, one payout entry |
| A held or rejected redemption | nothing, ever | no payout row |

### Buying credits, step by step

One step for HR ([ADR 0021](adr/0021-one-step-buy-credits-and-a-platform-fee-account.md)):

1. **Buy credits** (`employer.purchases.store`) writes the pending purchase
   and comes back to `/employer?pay=<reference>#buy`. That page shows the
   amount, the test card and Stripe's form; showing it creates nothing.
2. Stripe.js (`https://js.stripe.com/endive/stripe.js`, matching the
   `2026-09-30.endive` API version of stripe-php 22) calls
   `createEmbeddedCheckoutPage`. In this version `initEmbeddedCheckout`
   throws "has been removed". Its `fetchClientSecret` posts to
   `employer.purchases.checkout`, which makes the Checkout Session
   (`ui_mode: embedded_page`, open for one hour, idempotency key
   `lg-checkout-<payment token>`, so asking twice gets the same one).
3. Declined cards are handled inside Stripe's form. Once the payment
   succeeds Stripe sends the browser to the `return_url`,
   `/employer?checkout=returned&purchase=<reference>#buy`, built from
   `APP_URL` in production (never the request's host, since Beanstalk
   refuses direct visitors).
4. If the webhook is already in, the page shows the green toast ("$400.00
   in credits added (Stripe test mode, MPG-…). Ready to give."). If not, the
   Buy credits card says **Confirming with Stripe…** and asks
   `employer.purchases.status` every 2 seconds for up to 2 minutes, then
   reloads into the green toast. After 2 minutes it says it is still
   waiting.
5. **Cancel payment** (`employer.purchases.cancel`) asks Stripe for the
   Checkout's status first. Open: it is expired, then the purchase becomes
   **cancelled** (never in the ledger, no longer counted as pending).
   Already expired: cancelled. Complete: *not* cancelled ("Stripe already
   has the payment"), and the page waits for the webhook. The form comes
   back filled in with **Try again**.
6. **Try again** (the error panel) and **Continue paying** (a pending row in
   Purchases, for example after closing the tab) post to
   `employer.purchases.pay`, which closes the old Checkout the same way and
   only then gives the purchase a new token, so the next form is a fresh
   Checkout. An old Checkout is never left payable against a token Local
   Goods has forgotten.

If a payment still arrives for a cancelled purchase (it shouldn't: we only
cancel once Stripe says the Checkout can't take one), the webhook refuses
it, writes nothing, and reconciliation lists it with "check before
refunding".

**Content-Security-Policy.** The app sends none today (checked: nothing in
the app, `.platform`, `.ebextensions` or `public/`). If one is ever added,
in the app or as a CloudFront response-headers policy, it must allow what
embedded Checkout loads: start from Stripe's own list ("Content Security
Policy" in Stripe's docs). In the local trial on 2026-10-21 the paying state
loaded scripts and frames from `js.stripe.com`, frames from
`m.stripe.network`, `b.stripecdn.com` and `newassets.hcaptcha.com` (frame
addresses only; network calls weren't recorded, but Stripe.js talks to
`api.stripe.com`). After adding one, check that the paying state still
shows Stripe's form.

### Platform fee (off)

`PLATFORM_FEE_PERCENT` adds a fee on top of the credits
([ADR 0021](adr/0021-one-step-buy-credits-and-a-platform-fee-account.md)).
Unset, blank or 0 (the default) means no fee, and nothing about fees
appears anywhere. A number from 0 to 20 with up to two decimals (e.g.
`2.5`) turns it on; any other value stops a production deploy
(`app:check-production-config`) and is treated as 0 elsewhere.

With the fee on:

- HR sees "Credits $400.00 + platform fee $10.00 = total $410.00" as they
  type and again above Stripe's form; Checkout has a second line item.
- The fee is fixed on the purchase (`platform_fee_cents`) when it is
  requested. Stripe must receive credits + fee, or the webhook refuses the
  payment.
- The fee is its own ledger line (`platform_fee`) on the **platform
  account** (`platform = true`), written with the purchase entry. The
  employer's "Ready to give" is credits only, and fees never fund merchant
  transfers.
- The ledger check becomes: paid credits + fees − payouts − held = sum of
  all entries.

### Merchant fee (at 0)

A separate fee, on the merchant's side: a percent of each sale plus an
optional fixed amount, kept by Local Goods out of the merchant's transfer
([ADR 0029](adr/0029-merchant-fee-ready-but-at-zero.md)). It is set on
`/bid-admin/fees` by the platform admin only (two steps, audit log, at most
10% + $1.00) and is 0 until someone sets it; live and the seed stay at 0.

- The fee is snapshotted on each redemption when it is made
  (`merchant_fee_bps`, `merchant_fee_fixed_cents`, `merchant_fee_cents`),
  on the total including tax, rounded half up, never more than the sale.
- When the sale clears, `merchant_earned +total` is followed by
  `merchant_fee −fee` (merchant) and `platform_merchant_fee +fee`
  (platform account).
- The Stripe payout row is `total − fee` (`fee_cents` on the row), the
  transfer is for that and carries `lg_merchant_fee_cents`, and the fee
  stays in the platform balance. The purchase is claimed for the whole
  sale, so the fee never funds another transfer or an employer refund.
- On a refund the employee gets the whole refund. The fee's share (worked
  out cumulatively, never more than the fee in all, stored as
  `corrections.fee_cents`) goes back as `merchant_fee_returned +share` and
  `platform_merchant_fee_returned −share`, and Stripe reverses
  `refund − share`. A refund that is all fee needs no reversal
  (`fee_only`).

Worked example, 2.5% on $6.33: fee 16¢, the transfer is $6.17. Refund
$2.11: 5¢ fee back, $2.06 reversed. Refund the other $4.22: 11¢ fee back,
$4.11 reversed.

The test card **4000 0000 0000 0077** (any future date, any CVC) makes the
money *available* in the test balance straight away, so transfers can run
in the demo without waiting for Stripe's payout schedule.

### Two channels that never mix

Only **Stripe-funded** redemptions are ever sent to Stripe: cleared while
Stripe test mode is on *and* covered by a purchase that was paid through
Stripe. The employer's credits are one pool, so Stripe-paid purchases are
matched oldest first (`App\Services\Payments\StripeFunding`): the total of
Stripe transfers can never exceed what Stripe actually received.

Everything else is **simulated**, and labeled so: the seeded demo history,
anything bought (simulated) while Stripe was off, and any
redemption beyond the Stripe-paid money. Simulated money owed stays on the
simulated path and is never sent through Stripe. The merchant dashboard and
the BID roster show the two separately: **Owed via Stripe test mode** and
**Owed, simulated**.

### Demo merchants set up by the platform

Only a merchant with a login can onboard itself, and in the demo only
Farragut Grind has one. So that a cleared redemption at **any** demo
merchant sends a test transfer, the platform admin can press **Connect demo
merchants to Stripe (test mode)** on `/admin/reconciliation`
([ADR 0022](adr/0022-platform-set-up-stripe-accounts-for-demo-merchants.md),
`App\Services\Payments\DemoMerchantStripeSetup`).

- **Why not Express.** We tried first: Stripe refuses to let a platform
  accept its terms for an Express account (`tos_acceptance_on_behalf_not_allowed`,
  real test-mode call, 2026-10-22). These accounts are therefore Accounts v2
  with **no Stripe dashboard**, filled in by Local Goods with Stripe's test
  values: a fictional "Demo Owner", DOB 1901-01-01, SSN 000000000, address
  `address_full_match`, and Stripe's test bank account `btok_us_verified`. They can
  receive transfers at once; Stripe enables payouts about 30 seconds later
  (press again, or wait for `account.updated`, to see it).
- **Who gets one.** Only the fictional merchants in
  `DatabaseSeeder::DEMO_MERCHANTS` that have **no login**, and never a name
  that is also a real candidate in `data/merchant_candidates.csv`. Any
  merchant with a login (Farragut Grind) keeps **Start Stripe onboarding**.
  **Real merchants always onboard themselves through Stripe's Express
  pages**, and real DC businesses never get a Stripe account.
- **Honest label.** Their status reads **"Demo account set up by Local
  Goods in test mode"** (`merchants.stripe_set_up_by_platform`) on the
  merchant dashboard, the BID roster and this page. The merchant dashboard
  also says the platform set it up for the demo.
- **One merchant per request.** CloudFront waits only 30 seconds for the
  server, and doing all seven in one request took about 49 (each takes
  about 7 seconds in real test mode). It timed out on the first live press
  (504) and locally (PHP's 30-second limit). Each press now does one
  merchant: create its account and **save the id at once**, add the test
  bank account, and read its status once. It never waits for Stripe (for
  example, for payouts to turn on); `account.updated` brings that later.
  The page then shows "3 of 7 demo merchants connected, continuing…" and
  presses again by itself (`data-auto-submit`) until all are done. Without
  JavaScript, the button reads **Continue connecting (3 of 7 done)**: press
  again to continue. The automatic presses stop after twice the number of
  merchants, so a merchant Stripe is slow to confirm can't make the page
  loop forever.
- **Safe to press twice, and safe to cut off.** It skips merchants that are
  already set up, and every Stripe call reuses the merchant's idempotency
  keys (`lg-demo-account-v2-…`, `lg-demo-bank-…`). A request cut off after
  Stripe made the account gets that same account back on the next press,
  and one cut off before the bank account just adds it. Stripe keeps
  idempotency keys for 24 hours, so finish within a day. It refuses unless
  Stripe test mode is on, so never with live keys. Each account carries
  `lg_env`, `lg_instance` and `lg_demo_setup=1`. If Stripe refuses a
  merchant, the message names it, and the next press tries that merchant
  again.
- **After Reset demo.** The accounts are kept (matched by name, like
  Farragut Grind's), so you only need to press it again if a demo merchant
  shows "Not connected to Stripe".

Checked against real Stripe test mode on 2026-10-22, in a scratch database
labelled `trial`: one step took 6.9 seconds and finished its merchant;
7 accounts created, the second press created nothing,
a cleared $12 redemption at Press Room Deli became a transfer to Press
Room Deli's own account, and reconciliation matched.

### The transfer gate

`App\Services\Payments\TransferGate::allows()` is the fraud gate on
transfers ([ADR 0016](adr/0016-fraud-detection-gates-stripe-transfers.md)).
It allows a transfer only when the redemption is approved, its merchant
earning is in the ledger, and either:

- Redemption Fraud Detection cleared it itself (a real AI answer, no person
  involved), or
- the AI held it and a BID or platform admin then recorded an **Approve**
  (a row in `redemption_decisions`).

Held, denied, unreviewed and seeded redemptions never pass. If the AI call
failed or returned something invalid, the redemption was held (it fails
closed), so it can only pass after a person approves it.

The gate is asked twice: by `StripeFunding::claim` before a payout row is
made, and by `MerchantPayouts::sendOne`, the only code that calls
`createTransfer`, just before Stripe is called. `FraudGateTest` checks
this both by scanning the source and by running every sending flow (a new
clear, an approval, onboarding finishing, the webhook, Retry) with the gate
shut: no transfer.

**Approve** sends the transfer: through Stripe if Stripe-paid money funds
it, otherwise it stays simulated, same rules as above. **Deny** never
sends one, and asks why: *suspected abuse* puts the credit back in the
employer's paid pool; *no purchase happened* gives it back to the employee.
Both need a short reason. Each decision records who, when, the reason and
what the AI originally said, once per redemption.

**One idempotency key per redemption:**
`lg-transfer-<instance>-r<redemption id>-<payout row created>-<attempt>`.
A double click, a second tab or a retry after a timeout asks Stripe with
the same key, so Stripe can't create a second transfer. The attempt number
only goes up after Stripe definitely refused (no transfer exists). The
instance id and the row's creation time keep keys apart between local and
production, and after a Reset demo reuses redemption ids.

Every redemption shows its path on the review page and on the merchant's
dashboard, e.g. *AI held (risk 88) → approved by BID admin S.T. · Oct 17 →
transferred tr_…*.

### When things go wrong

- **Merchant not onboarded:** the payout row stays *owed*. It is sent when
  Stripe says onboarding is complete (`account.updated`, the return page, or
  "Check Stripe status").
- **Stripe refuses the transfer** (e.g. not enough available balance): the
  row is *failed* with the reason, still owed, and retried on the next
  clearance or with **Retry owed transfers** on the reconciliation page, with
  the next attempt's key.
- **No answer from Stripe** (a timeout): the row stays *pending* and is
  retried with the **same** idempotency key, so Stripe can't pay twice. A
  `transfer.created` webhook also records it.
- **A transfer is reversed in Stripe:** the merchant is owed it again
  (*reversed*), and a person decides; it is never re-sent automatically.
  Refunds and merchant adjustments (ADR 0028) refuse such a payout: it is
  sorted out by hand in Stripe. A refund's own reversal is not this case
  (see Refunds and corrections).
- **Stripe is down when a redemption clears:** the redemption still clears.
  Transfers are sent after the database transaction commits, never inside
  it, and failures are written down rather than thrown.

### Stripe notices never crash a request

stripe-php 22 turns Stripe's informational `Stripe-Notice` response header
(for example "We recommend building your integration using Accounts v2")
into a PHP warning, which Laravel would turn into a 500, after Stripe has
already done the work. Every Stripe call goes through
`NoticeSafeStripeGateway`, which logs such notices (redacted) and carries
on; genuine PHP errors still reach Laravel. `STRIPE_SUPPRESS_NOTICES=true`
(the default) is Stripe's own switch, but the library ignores it when it
detects an AI coding agent in the environment, so the code guard is what
actually protects the request.

**Accounts v2.** Stripe's newer API for connected accounts. It first
showed up as a notice, then Stripe refused new v1 Express accounts for this
platform outright ("Create connected accounts with POST /v2/core/accounts
instead"). So merchants' accounts are created, linked and checked with
Accounts v2, with `dashboard: express` (the merchant still gets Stripe's
Express onboarding and dashboard), a `recipient` configuration requesting
`stripe_transfers`, and a contact email (the merchant login's, fictional
in the demo), which v2 requires. Transfers stay on v1 `/v1/transfers`.
Stripe's reason for a refusal is shown on the merchant dashboard (first
sentence and any missing-field lines, keys removed; never for key errors).

## Webhooks

`POST /stripe/webhook` (`App\Http\Controllers\Stripe\StripeWebhookController`):

- Lives in `routes/stripe.php`, **outside the `web` middleware group**: no
  session, no cookies, no CSRF token, so nobody is ever signed in there and
  the judge write-block has nothing to judge. The Stripe signature is the
  only authentication. `JudgeReadOnlyTest` still sweeps every other write
  route and checks this one has no session and refuses unsigned requests.
- Verifies `Stripe-Signature` on the raw body with `STRIPE_WEBHOOK_SECRET`
  (and the optional `STRIPE_CONNECT_WEBHOOK_SECRET`), five-minute tolerance.
  Refuses live-mode events, and refuses everything in simulated mode.
- Every event id is stored in `stripe_events` in the same transaction as
  its changes. A replay (or Stripe retrying) finds it and changes nothing.
  Each handler is also guarded by state: only a pending purchase can be
  paid, only a pending payout recorded, only a sent one reversed.
- Handled: `payment_intent.succeeded`, `payment_intent.payment_failed`,
  `checkout.session.expired`, `account.updated`, `transfer.created`,
  `transfer.reversed`, and for corrections (ADR 0028) `refund.created`,
  `refund.updated` and `refund.failed`. Anything else gets 200 and is
  ignored.
- An event about an object tagged for **another environment** (see
  below) gets 200 `"ignored: other environment"` before anything is
  written: no ledger entry, no `stripe_events` row. Untagged objects (made
  before tagging) go through, because each handler only finds records by
  this database's own tokens and ids.
- Works behind CloudFront: CloudFront passes the body unchanged; its origin
  request policy must forward the `Stripe-Signature` header (see setup).

## Refunds and corrections

[ADR 0028](adr/0028-refunds-and-corrections.md). A platform admin approves
a correction; Stripe is called after the approval commits, each action with
its own idempotency key
(`lg-correction-{instance}-c{correction}-a{action}-{created}-{attempts}`,
attempts up only after a definite refusal) and metadata `lg_correction_key`,
`lg_correction_id` plus the environment tag.

| Correction | Stripe call | Confirmed by | Ledger lines (when confirmed) |
|---|---|---|---|
| Refund of a redemption whose transfer was sent | `POST /v1/transfers/{tr}/reversals` for exactly the refund (less its merchant fee share, ADR 0029) | `transfer.reversed` (lists every reversal with its metadata; `amount_reversed` adds up) | employee `+r` `refund`, merchant `-r` `merchant_refunded`, merchant `+r` `refund_reversal` |
| Refund of a simulated redemption | none | at once | employee `+r`, merchant `-r` (deducted from the next payout) |
| Refund of unspent credits, Stripe payment | `POST /v1/refunds` on the purchase's PaymentIntent, credits only | `refund.created` / `refund.updated` (succeeded at once in test mode, even on pending funds), `refund.failed` | `employer_refund_hold -R` at approval, `employer_refunded 0` per payment when confirmed, `employer_refund_released +R` if closed |
| Refund of unspent credits, simulated purchase | none | at once | hold, then `employer_refunded 0` |
| Merchant adjustment ("I wasn't paid") | the existing `MerchantPayouts::send` for the owed row (the `TransferGate`, the row's own key) | Stripe's reply, `transfer.created` as backup | the payout's own `payout` line, with the correction's id |

`transfer.reversed` records our refund reversals first; the old "reversed,
owed again" path runs only for reversed money they don't explain, so with
no refunds it behaves as before. A refund waits until the merchant's
transfer has been sent. Until Stripe's webhook arrives a Stripe step stays
*processing*; reconciliation names it ("the webhook has not arrived").

The behaviour above was checked against Stripe's test mode on 2026-10-24
with `scripts/stripe-trial-corrections.php` (a one-off: test keys only,
objects tagged `lg_env=scratch`, everything refunded and reversed and the
account deleted afterwards). Its scrubbed payloads are the fixtures in
`tests/Fixtures/stripe/corrections/`.

## Simulated fallback

If the keys are missing (CI, tests, a server without them), incomplete, or
not test keys, `App\Support\StripeMode` turns Stripe off and everything
works exactly as before: "Buy credits (simulated, no real money)" adds the
credits at once, simulated
payouts, nothing sent to Stripe. Every page where money moves shows a
**Stripe test mode** or **Simulated** badge, and every purchase and payout
row is labeled by how it was actually paid. A simulated payment is never
shown as a Stripe one.

## Generated demo activity (ADR 0032)

The demo activity generator pays for credits without a browser:
`StripeTestPayments` creates and confirms a PaymentIntent with the test card
`pm_card_visa` (`StripeGateway::createTestPayment`). It carries Checkout's
metadata (`lg_purchase_token`, reference, id, environment tag) plus
`lg_demo_activity=1` and the description "Local Goods demo activity", so the
same signed `payment_intent.succeeded` webhook marks the purchase paid and
reconciliation counts it like any other. It refuses unless the secret key is
a test key (`sk_test_` or `rk_test_`). A restricted key needs
**PaymentIntents: Write** for this. Transfers, reversals and refunds go
through the existing paths unchanged.

## Reconciliation

`/admin/reconciliation` (platform admin) puts the ledger next to Stripe for
Stripe-funded activity since the last Reset demo: purchases (credits plus
any platform fee) against Stripe's successful payments, Stripe payouts against Stripe's transfers,
one by one, and the platform balance for information (Stripe keeps card
fees, so it never matches exactly). Corrections (ADR 0028): refund
reversals are taken off the transfers the ledger counts, every reversal and
card refund is matched by id and amount both ways, and refunds to
employers' cards get their own row once there are any. Each difference is a sentence, for
example "Stripe has a $250.00 payment (pi_…) that Local Goods has no ledger
record of". Simulated activity is counted but not checked. With the platform fee on it
also checks that the platform account's fee lines add up to the fees the
Stripe-paid purchases charged, and the purchases row says "credits $X +
platform fees $Y"; with the fee off none of this shows. With the merchant
fee on (ADR 0029) it checks that each sent transfer plus the fee held back
is its redemption's total, and that the platform account's merchant fee
lines are the fees on cleared sales less what refunds gave back; the
balance row says how much of the expected balance is fees kept.

### One Stripe sandbox, several environments

Local development and production may share one Stripe sandbox, so every
object Local Goods creates carries its environment in Stripe metadata
(`App\Support\StripeEnvironment`):

| Object | Metadata |
|---|---|
| Checkout Session and its PaymentIntent | `lg_env`, `lg_instance`, `lg_purchase_id`, `lg_purchase_token`, `lg_purchase_reference` |
| Transfer | `lg_env`, `lg_instance`, `lg_redemption_id`, `lg_payout_key` |
| Connected account (v2) | `lg_env`, `lg_instance`, `lg_merchant_id`, `lg_merchant` |

`lg_env` is `APP_ENV` (`local`, `production`). `lg_instance` is 8 hex
characters derived from the server's `APP_KEY` (an HMAC, so it reveals
nothing about the key): two laptops both called `local` still differ. The
reconciliation page shows this environment's tag at the top.

Reconciliation counts an object only if its tag matches this environment,
or if it has no tag (made before tagging) but this database already knows
its id or token. Everything else goes in a separate **"From another
environment, not counted"** note with its totals and one line per object,
and never in the mismatches or the totals. The words "check before
refunding" appear only for a payment with no ledger record that is either
this environment's own (a mismatch) or untagged (in the note). Nothing in
the app ever refunds.

If `APP_KEY` is ever rotated, the instance id changes and older objects
from that server look like another environment's. Objects this database
knows by id are still counted.

**Trials run outside the app get their own label.** A one-off script or
scratch database run against real Stripe test mode must use its own
`APP_ENV` (e.g. `trial`) and a fresh `APP_KEY`, so its objects are never
mistaken for the local app's. One early Buy-credits trial (2026-10-21)
reused the local `APP_KEY`. Its two payments, `pi_3UTC951FdzFRuuh61hYEltse`
($256.25, MPG-20261021-12) and `pi_3UTBzi1FdzFRuuh61aqLJwJ4` ($250.00,
MPG-20261021-5), then showed as mismatches on the local reconciliation
page. On 2026-10-22 their metadata was corrected in Stripe test mode to
`lg_env=trial`, with an `lg_retagged` note saying what they were. They now
appear under "From another environment, not counted". No refund was made,
no ledger was changed, and production reconciliation is unchanged.

## Privacy

Nothing sent to Stripe names an employee: Checkout carries the purchase
reference and amount; transfers carry the redemption number and amount.
`StripePayoutTest` checks no employee name or label appears in any call.

## Setup

### Settings (names only; never commit values)

| Name | What | Where |
|---|---|---|
| `STRIPE_KEY` | Publishable key, `pk_test_…` | local `.env`, Beanstalk environment properties |
| `STRIPE_SECRET` | Secret key, `sk_test_…` (or a restricted `rk_test_…`) | local `.env`, Beanstalk |
| `STRIPE_WEBHOOK_SECRET` | Signing secret of the endpoint for your account's events, `whsec_…` | local `.env` (from `stripe listen`), Beanstalk (from the dashboard endpoint) |
| `STRIPE_CONNECT_WEBHOOK_SECRET` | Signing secret of the destination for connected accounts' events (`account.updated`); recommended in production | Beanstalk only (locally `stripe listen` uses one secret for both) |
| `STRIPE_SUPPRESS_NOTICES` | Optional, default `true`: hide Stripe's informational notices (logged instead) | not needed anywhere; the code guard works without it |
| `PLATFORM_FEE_PERCENT` | Optional, default none (no fee). See "Platform fee (off)" | leave unset |

Leave all of them blank for simulated mode.

### Local

1. Install the Stripe CLI (`brew install stripe/stripe-cli/stripe`) and run
   `stripe login`.
2. In one terminal:
   `stripe listen --events payment_intent.succeeded,payment_intent.payment_failed,checkout.session.expired,transfer.created,transfer.reversed,account.updated,refund.created,refund.updated,refund.failed --forward-to localhost:8000/stripe/webhook --forward-connect-to localhost:8000/stripe/webhook`
   (newer versions of the Stripe CLI refuse to start without `--events`). It prints a `whsec_…` secret: put it in `.env` as `STRIPE_WEBHOOK_SECRET`
   (one secret covers both kinds of events locally).
3. Put the test keys from Stripe → Developers → API keys in `.env` as
   `STRIPE_KEY` and `STRIPE_SECRET`, then restart the app.
4. As HR, buy credits and pay in the form on the page with
   4000 0000 0000 0077. The terminal running `stripe listen` shows
   `payment_intent.succeeded`, and the page turns from "Confirming with
   Stripe…" (if you see it at all) into the green confirmation.

**Recommended: a separate sandbox for local development.** Tagging keeps
the environments apart, but with one shared sandbox `stripe listen` still
forwards production's events to your laptop (they are ignored), and
Stripe's dashboard mixes both environments' payments. A second sandbox
keeps them fully separate and is free:

1. Stripe dashboard → account menu (top left) → **Sandboxes** → **Create
   sandbox**, named for example "Local Goods – local dev".
2. Switch to it, then **Settings → Connect** and finish the Connect
   platform setup there (each sandbox has its own Connect settings).
3. Developers → API keys in that sandbox: put its `pk_test_…` and
   `sk_test_…` in your local `.env` as `STRIPE_KEY` and `STRIPE_SECRET`.
4. `stripe login` again and pick the new sandbox (or pass `--api-key` with
   its secret key), then run `stripe listen` as above and put the new
   `whsec_…` in `.env` as `STRIPE_WEBHOOK_SECRET`.
5. Restart the app. The Farragut Grind account in your local database was
   made in the old sandbox, so clear its Stripe account in a local scratch
   database (or start from a fresh scratchpad SQLite) and onboard again.
   The same goes for the demo merchants the platform set up: clear them and
   press **Connect demo merchants to Stripe (test mode)** again.
   Production keeps the existing sandbox and changes nothing.

### Production webhook (Stripe dashboard, test mode)

**Two event destinations, both "snapshot" events, same URL:**
`https://d4ghrpm72uczx.cloudfront.net/stripe/webhook`. Always the CloudFront address: production refuses requests sent straight to Beanstalk ([ADR 0018](adr/0018-only-cloudfront-reaches-beanstalk.md)). Create them in the
same test-mode environment (or sandbox) your `sk_test_…` key comes from.
In Stripe: Developers → Webhooks (in Workbench: Event destinations) → Add
destination.

1. **Events from: Your account.** Payload style **Snapshot**. Destination
   type **Webhook endpoint**, the URL above. Events, exactly these eight:
   - `payment_intent.succeeded` (a purchase is paid: the only thing that
     writes its ledger entry)
   - `payment_intent.payment_failed` (shows the decline reason; stays pending)
   - `checkout.session.expired` (lets HR start a fresh Checkout)
   - `transfer.created` (records a transfer whose reply was lost)
   - `transfer.reversed` (records a refund's reversal, or marks a transfer
     reversed in the dashboard owed again)
   - `refund.created`, `refund.updated`, `refund.failed` (a refund of
     unspent credits to the employer's card went through, or failed;
     ADR 0028)

   Copy this destination's signing secret into Beanstalk as
   `STRIPE_WEBHOOK_SECRET`.
2. **Events from: Connected accounts.** Payload style **Snapshot**,
   Webhook endpoint, the same URL. One event: `account.updated` (a
   merchant finished or changed onboarding; when they become able to
   receive transfers, what's owed through Stripe is sent). Copy its signing
   secret into Beanstalk as `STRIPE_CONNECT_WEBHOOK_SECRET`. Stripe gives
   each destination its own secret; the app tries both.

**Accounts v2 does not need thin events or a third destination.** Stripe
still sends the classic `account.updated` snapshot for our Accounts v2
merchants: the local trial on 2026-10-16 received six, including the one
that marked Farragut Grind onboarded. Do **not** add a "Thin" (v2)
destination pointing at this URL: the app accepts only snapshot events,
answers a thin one with 400, and Stripe would keep retrying it until it
disabled the destination. Even if `account.updated` were missed, status
still updates when the merchant comes back from Stripe or presses
**Check Stripe status**.

3. **CloudFront.** The behavior that serves the site must allow POST
   (it already does: logins work) and forward the `Stripe-Signature`
   header, for example with origin request policy
   `AllViewerExceptHostHeader`. CloudFront passes the body unchanged.
4. **Test it.** On each destination use "Send test event" (pick one of its
   events). Expect a 200 response with `"received": true`. A test event
   for something Local Goods doesn't know answers 200 with an
   "ignored: …" outcome and changes nothing. A 400 "Invalid signature" means
   the secret in Beanstalk doesn't match that destination; a 400 "Stripe is
   not configured" means the keys aren't set on Beanstalk.

Connect must be enabled on the Stripe account (Connect → Get started, as a
platform, Express accounts).

### Beanstalk environment properties

Configuration → Updates, monitoring and logging → Environment properties.
Names only; never commit or share the values:

| Name | Needed | Value comes from |
|---|---|---|
| `STRIPE_KEY` | yes | Developers → API keys, publishable key (`pk_test_…`) |
| `STRIPE_SECRET` | yes | Developers → API keys, secret key (`sk_test_…`) |
| `STRIPE_WEBHOOK_SECRET` | yes | destination 1 ("Your account") signing secret |
| `STRIPE_CONNECT_WEBHOOK_SECRET` | yes, for `account.updated` | destination 2 ("Connected accounts") signing secret |
| `STRIPE_SUPPRESS_NOTICES` | no | leave unset; the code already handles Stripe's notices |
| `PLATFORM_FEE_PERCENT` | no | leave unset (no fee); a bad value stops the deploy |

Without the first three, production stays in simulated mode and says so.
Saving environment properties restarts the app; the config hook re-runs
migrations safely and never caches config.
