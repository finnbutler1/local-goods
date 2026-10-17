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

- The employer pays **Stripe** by card (Stripe Checkout). The funds land in
  the Local Goods *platform* balance at Stripe, which Stripe holds.
- Each merchant has its **own Stripe Express account** (created with Accounts v2). Stripe collects and
  checks the merchant's details on its own pages; we store only the account
  id (`acct_…`) and whether Stripe says it can receive transfers.
- When a redemption clears, Local Goods asks Stripe to **transfer** the
  amount from the platform balance to the merchant's account.

Local Goods' job is the **entitlement ledger** (an append-only list of who
is owed what, [ADR 0012](adr/0012-one-ledger-three-account-kinds.md)) and the
rules: credits only at independent BID merchants, Redemption Fraud Detection
before any payout, and a person deciding anything the AI holds.

## How money moves

| Step | In Stripe | In the Local Goods ledger |
|---|---|---|
| HR requests a purchase | nothing | a **pending** purchase, no entry: the credits can't be given out |
| HR presses **Pay with Stripe (test mode)** | a Checkout Session; the PaymentIntent's `transfer_group` is the purchase reference (e.g. `MPG-2026-10-003`) | still pending, still no entry |
| Stripe sends `payment_intent.succeeded` (signed) | the charge (`ch_…`) is in the platform balance | purchase becomes **paid**, one purchase entry, once |
| HR gives credits; an employee redeems | nothing | allowance and redemption entries, as before |
| Redemption Fraud Detection clears it, or a BID admin approves it | nothing yet | merchant earns it; if a Stripe-paid purchase can fund it, a Stripe payout row is **owed** |
| Merchant has finished onboarding | a **Transfer** to the merchant's account, same `transfer_group`, `source_transaction` = the purchase's charge | payout row **sent** with its `tr_…` id, one payout entry |
| A held or rejected redemption | nothing, ever | no payout row |

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
anything paid with "Pay now (simulated)" while Stripe was off, and any
redemption beyond the Stripe-paid money. Simulated money owed stays on the
simulated path and is never sent through Stripe. The merchant dashboard and
the BID roster show the two separately: **Owed via Stripe test mode** and
**Owed, simulated**.

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
  `transfer.reversed`. Anything else gets 200 and is ignored.
- An event about an object tagged for **another environment** (see
  below) gets 200 `"ignored: other environment"` before anything is
  written: no ledger entry, no `stripe_events` row. Untagged objects (made
  before tagging) go through, because each handler only finds records by
  this database's own tokens and ids.
- Works behind CloudFront: CloudFront passes the body unchanged; its origin
  request policy must forward the `Stripe-Signature` header (see setup).

## Simulated fallback

If the keys are missing (CI, tests, a server without them), incomplete, or
not test keys, `App\Support\StripeMode` turns Stripe off and everything
works exactly as before: "Pay now (simulated, no real money)", simulated
payouts, nothing sent to Stripe. Every page where money moves shows a
**Stripe test mode** or **Simulated** badge, and every purchase and payout
row is labeled by how it was actually paid. A simulated payment is never
shown as a Stripe one.

## Reconciliation

`/admin/reconciliation` (platform admin) puts the ledger next to Stripe for
Stripe-funded activity since the last Reset demo: purchases against
Stripe's successful payments, Stripe payouts against Stripe's transfers,
one by one, and the platform balance for information (Stripe keeps card
fees, so it never matches exactly). Each difference is a sentence, for
example "Stripe has a $250.00 payment (pi_…) that Local Goods has no ledger
record of". Simulated activity is counted but not checked.

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

Leave all of them blank for simulated mode.

### Local

1. Install the Stripe CLI (`brew install stripe/stripe-cli/stripe`) and run
   `stripe login`.
2. In one terminal:
   `stripe listen --forward-to localhost:8000/stripe/webhook --forward-connect-to localhost:8000/stripe/webhook`
   It prints a `whsec_…` secret: put it in `.env` as `STRIPE_WEBHOOK_SECRET`
   (one secret covers both kinds of events locally).
3. Put the test keys from Stripe → Developers → API keys in `.env` as
   `STRIPE_KEY` and `STRIPE_SECRET`, then restart the app.
4. As HR, request and pay a purchase with 4000 0000 0000 0077. The terminal
   running `stripe listen` shows `payment_intent.succeeded` and the purchase
   turns paid.

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
   Production keeps the existing sandbox and changes nothing.

### Production webhook (Stripe dashboard, test mode)

**Two event destinations, both "snapshot" events, same URL:**
`https://d4ghrpm72uczx.cloudfront.net/stripe/webhook`. Create them in the
same test-mode environment (or sandbox) your `sk_test_…` key comes from.
In Stripe: Developers → Webhooks (in Workbench: Event destinations) → Add
destination.

1. **Events from: Your account.** Payload style **Snapshot**. Destination
   type **Webhook endpoint**, the URL above. Events, exactly these five:
   - `payment_intent.succeeded` (a purchase is paid: the only thing that
     writes its ledger entry)
   - `payment_intent.payment_failed` (shows the decline reason; stays pending)
   - `checkout.session.expired` (lets HR start a fresh Checkout)
   - `transfer.created` (records a transfer whose reply was lost)
   - `transfer.reversed` (marks a transfer reversed and owed again)

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

Without the first three, production stays in simulated mode and says so.
Saving environment properties restarts the app; the config hook re-runs
migrations safely and never caches config.
