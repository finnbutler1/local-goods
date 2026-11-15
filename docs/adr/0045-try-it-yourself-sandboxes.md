# 0045. "Try it yourself": a private SQLite copy of the demo per visitor

**Status:** Accepted, implemented (2026-11-14). Built and tested; switched off by default (`SANDBOX_ENABLED`).

## Context

Judges can only look: their login is read-only (ADR 0008, 0010), so they never
see money move. Finn wanted them, and his demo video, to buy credits as the
employer, give them to an employee, pay at a merchant, see the payout and the
BID's impact, and decide a payment the fraud check held, switching roles with
one click and no passwords. Two days before the code freeze, so the live site,
its database, Stripe and its totals must be impossible to touch.

What the code already gave us:

- One database connection everywhere: no model sets `$connection`, nothing
  names a connection. Moving the default connection moves every model.
- In production, sessions are cookies, the cache is files and the queue is
  sync, so nothing shared lives in the live database. Nothing is queued during
  a purchase, an allocation or a redemption.
- Every Stripe caller checks `StripeMode::active()`, which reads the keys on
  every call; with no keys every path is simulated (a purchase is paid at once
  by `CreditLedger::payPurchase`, payouts are simulated).
- Redemption Fraud Detection already fails closed: any exception from the
  Claude client is a hold for a person to review.
- The seed needs no outside service and takes about half a second on SQLite
  (0.8 MB).

## Decision

### One SQLite file per sandbox

`storage/app/sandboxes/` (never under `public/`) holds today's template
(`template-<DC date>.sqlite`) and one `sb-<sha256 of token>.sqlite` per
sandbox with a small JSON note of when it was made. The folder is the list:
**no migration, no table in the live database.** `sandbox:build-template`
builds the template from every migration, `DatabaseSeeder` and five sandbox
logins (`employer@`, `employee@`, `merchant@`, `bid_admin@`, `reviewer@`
`sandbox.invalid`, with no usable password). It points the default
connection at the new file and the cache at an in-memory store before
anything runs, so it makes **no query on the live connection**, and it never
touches `DemoResetter` or the Reset demo gate. It is never built during a web
request: the scheduler builds it (every five minutes, a no-op once today's
exists), so after a deploy or switching it on the template appears within
five minutes; until then Start says it is being prepared. There is no
postdeploy hook (decided 2026-11-14: `DemoActivityScheduleTest` keeps
asserting there are none). `scripts/build-sandbox-template.sh` runs the same
build by hand as webapp if ever needed.
Without today's template, Start says "The sandbox is being prepared — try
again in a minute."

### How a request becomes a sandbox request

`UseSandbox` (web group, sorted after `EncryptCookies` and before
`StartSession`) does nothing while sandboxes are off. With the `lg_sandbox`
cookie (encrypted and signed by Laravel, HTTP-only, SameSite=Lax, Secure in
production) naming a live sandbox, `Sandbox::activate`:

- points the default connection at that file (`sandbox`), and pins the
  session and the main cache's database store to the live connection by name;
- gives the sandbox its own cache store inside its file (throttles, the
  solvency guard's baseline and the AI count would otherwise be shared between
  sandboxes, whose user ids are the same, and with live);
- uses the sandbox's own session cookie (`lg_sandbox_session`): the live
  login's session is never read or written, so a judge's login comes back
  untouched on Exit;
- switches to the `sandbox` guard, whose provider (`SandboxUserProvider`)
  returns a user only while a sandbox is active, only from the sandbox
  connection, only with a `@sandbox.invalid` email, and never by password;
- blanks the Stripe keys, so every Stripe caller takes its simulated path;
  `StripeNotices::quietly`, which every Stripe call passes through, also
  refuses outright while a sandbox is active;
- wraps the Claude client in `CappedClaudeClient`.

Everything is put back when the request ends, whatever happens. Only the
money loop opens (`SandboxRoutes::ALLOWED`); every other page, including all
of `/admin`, Your account, sign-in, feedback and the evals, is a 404 page
that links back. `/sandbox` pages before a sandbox exists also use the
sandbox session and guard, so no live login is read there either. The role
bar (`POST /sandbox/role`) is behind `sandbox:active`: on the live site, with
no sandbox, it is a 404.

### Limits

| Limit | Value |
|---|---|
| New sandboxes per visitor IP per hour (counted in the live cache store) | 10 |
| Sandboxes per browser | 1 (Start over replaces it) |
| Sandboxes at once | 50 (about 0.8 MB each) |
| Lifetime | 24 hours from creation |
| Employer purchase | $5,000 each, as the shared demo employer |
| Redemption Fraud Detection calls per sandbox | 10 |
| Calls per day across all sandboxes (DC date, a locked file) | 100 |

Past either AI cap the capped client throws, so the unchanged detector holds
the payment with its usual fallback explanation. The prompt, the signals and
the schema are passed through untouched (a test compares the input with what
the live signal code computes). `sandbox:prune` (hourly, and on every Start)
deletes ended sandboxes and old templates without opening them. A redeploy
deleting the folder is fine. `scripts/run-scheduler.sh` now starts the
scheduler when `DEMO_ACTIVITY_ENABLED` **or** `SANDBOX_ENABLED` is true (the
generator still refuses on its own switch).

### Inside a sandbox the visitor is the public, for platform figures only

`MerchantFee::showsFiguresTo` is false in a sandbox, as for judges. It hides
platform-level figures only: merchant and platform fee amounts (the merchant's
Sale / Local Goods fee / You receive lines, fees given back on corrections,
fees earned on Where the Money Is), money in hand, Local Goods Operating,
sweeps and Stripe balances. Each role's own money stays visible, because
seeing it move is the point: the employer's credit balance and purchases, the
employee's balance and payments, the merchant's sales, what it is owed and
its payouts, the BID's impact totals, and customer funds on Where the Money
Is (`SandboxFiguresVisibleTest`). The sweep and solvency-run gates
refuse in a sandbox (their routes aren't part of it anyway). Every sandbox
page carries the banner "Sandbox: your own copy. Nothing here touches the live
demo or Stripe. Deleted after 24 hours." with Start over and Exit, and the
employer's Buy credits says "Simulated payment: no card is charged and Stripe
is not contacted."

## Consequences

- Judges' read-only rules on the live site are unchanged (`BlockJudgeWrites`'
  allowlist and `JudgeReadOnlyTest` untouched).
- With `SANDBOX_ENABLED` off: no button, every `/sandbox` address is a 404,
  `UseSandbox` does nothing. Also off when PHP has no SQLite driver. Confirm
  it on the instance first: `php -r 'var_dump(in_array("sqlite", PDO::getAvailableDrivers()));'`.
- Sandboxes live on one instance's disk; a second server would need shared
  storage (not planned).
- On a local machine with database sessions, the sandbox's session rows go in
  the local database's `sessions` table, as the plan asked; in production
  sessions are cookies.
- Proved by `SandboxWalkthroughTest` (the whole checklist with a listener that
  fails on any write to the live connection, and a control write; no Stripe
  call), `SandboxIsolationTest`, `SandboxLimitsTest`, `SandboxTemplateTest`,
  `SandboxScheduleScriptsTest` and `PublicClaimsSandboxOnTest`.

## Addendum (2026-11-14): real Stripe Checkout for Buy credits, on a separate Stripe test account

Status: code done, not deployed; switched off.

**What.** Inside a sandbox, with `SANDBOX_STRIPE_ENABLED` exactly `true` and
`SANDBOX_STRIPE_SECRET` an `sk_test_` key, the employer's Buy credits opens
Stripe's **hosted** Checkout (a redirect to Stripe's page; no publishable key
and no Stripe.js). It uses a **separate Stripe test account**, never the live
site's, so nothing reaches the live Stripe balance, reconciliation, solvency
or sweeps. The lines are the live purchase's (`StripeCheckout::lineItems`):
credits, then the employer platform fee line when it applies ("Platform fee
for November 2026: N participating employees × $6.00"), then the percent fee
if `PLATFORM_FEE_PERCENT` were set; the same text under the Pay button.
Metadata: `lg_sandbox` (a hash of the sandbox id, so the file name never goes
to Stripe), `lg_purchase_token`, `lg_purchase_reference`; deliberately no
`lg_env`/`lg_instance`.

**No webhook.** Stripe sends the employer back to
`employer.purchases.sandbox-return` (a 404 outside a sandbox). `SandboxCheckout::confirm`
reads the Checkout Session **saved on the purchase** (the id in the address
must equal it) and records the purchase in the sandbox's own file only if
`payment_status` is `paid`, `amount_total` equals `totalCents()`, the
currency is USD and the metadata matches this sandbox and this purchase. It
records through the existing `CreditLedger::markPurchasePaidByStripe`
(status `paid`, once: a reload or the back button records nothing more).
Anything else records nothing and says why. Continue paying reopens the open
Checkout (or a fresh one once expired); Cancel expires it first; if Stripe
already has the money, both record it instead.

**Keys.** `App\Services\Sandbox\SandboxStripe` is the only reader of the
sandbox key, and only while a sandbox is active; it builds
`SandboxStripeClient`, which has only create / retrieve / expire Checkout
(not a `StripeGateway`, so the key can't reach payouts, transfers, refunds,
balances or sweeps). Its calls go through `StripeNotices::quietlyInSandbox`,
which refuses outside a sandbox; `StripeNotices::quietly` still refuses every
live-key call inside one. The sandbox still blanks the live `STRIPE_*` keys,
so `StripeMode` stays simulated there and everything else is exactly as
before (payouts simulated, Connect, reconciliation, balances and sweeps
refused). `app:check-production-config` refuses a sandbox key equal to
`STRIPE_SECRET`; that the account really is a different one is checked by
Finn in the Stripe Dashboard.

**Fallback.** Flag off, key missing or not `sk_test_`, Stripe unreachable when
opening, or the sandbox's 10 Checkouts used up: the purchase is simulated
with its label, as before, never a 500. The $5,000 cap still applies first.

**Refunds.** An employer refund of a card purchase inside a sandbox is
simulated (`CorrectionDecisions`: `Sandbox::active()`), marked refunded in the
sandbox only; the test charge on the sandbox Stripe account is not refunded.

**Wording.** With it on, the sandbox bar says "Sandbox: your own copy.
Payments use a separate Stripe test account: no real money, and nothing
reaches the live demo. Deleted after 24 hours."; Buy credits says "Stripe
test mode (separate sandbox): use card 4242 4242 4242 4242. No real money;
nothing reaches the live site."; checklist step 1 names the test card
(`SandboxRoutes::checklist()`); `/sandbox` says buying uses a separate Stripe
test account. Fee figures on our pages stay hidden as before. The CSP's
`form-action` gains `https://checkout.stripe.com` (still report-only).

**Never** add a webhook endpoint in the sandbox Stripe account, and never use
the live account's key for it.

Tests: `SandboxStripeCheckoutTest` (with a fake client; live and sandbox keys
both ways, record once, refresh, forged id, every mismatch, fallbacks, the
10-session limit, the cap, Cancel/Continue, simulated refund, zero writes to
the live database and zero live Stripe calls), `PublicClaimsSandboxStripeOnTest`.

### Update (2026-11-14): embedded Checkout first, as on the live site

The sandbox's Buy credits now shows **Stripe's embedded card form on our
page**, the same view and `resources/js/checkout.js` as the live purchase, so
it looks the same. Everything above is unchanged unless said here.

- **Key.** `SANDBOX_STRIPE_PUBLISHABLE`, the sandbox account's `pk_test_` key
  (Beanstalk only). Only `SandboxStripe::publishableKey()` reads it, and only
  when `available()` (inside a sandbox, switched on, good secret). The live
  page's `data-stripe-key` stays `STRIPE_KEY`, which a sandbox blanks; the
  sandbox one is null outside a sandbox. `app:check-production-config` also
  refuses it when it equals `STRIPE_KEY` (`SandboxStripe::publishableProblem`).
- **Session.** Buy credits / Continue paying open it (`SandboxCheckout::start($p,
  embedded: true)`: `ui_mode` `embedded_page`, `return_url` to
  `sandbox-return?session_id={CHECKOUT_SESSION_ID}`, same lines, metadata and
  text) and show the card form at `?pay=<ref>#buy`. `employer.purchases.checkout`
  (now on `SandboxRoutes::ALLOWED`) only reads the saved open session's client
  secret (`clientSecret()`), never opens one, so reloads don't count towards the
  10. An open session of the other kind is expired before a new one.
- **Recording** is exactly as above: Stripe sends the page to `sandbox-return`;
  `confirm()` unchanged.
- **Fallback order:** embedded → hosted → simulated. Hosted when the
  publishable key is missing or not `pk_test_`, or when Stripe.js doesn't load
  or can't mount (`checkout.js` submits the sandbox-only "Pay on Stripe's page
  instead" form, `hosted=1`; also offered under `<noscript>`). A failed request
  for our own client secret shows Try again, as on the live page. Simulated as
  before (off, bad secret, Stripe down when opening, 10 used). Never a 500.
- **Wording.** One sentence, `SandboxStripe::TEST_CARD_HINT`: "Use card 4242
  4242 4242 4242 with any future expiry date (e.g. 12/34), any 3-digit CVC, any ZIP code
  and any name. No real money." On Buy credits (form and card form), checklist
  step 1 and `/sandbox`. Every sandbox session sets `customer_email` to
  `employer@example.com` (reserved domain), which Stripe fills in and locks,
  so a visitor never types their own; the live Checkout doesn't set it.
- **Button.** The sandbox's Buy credits button says "Buy credits (Stripe test
  mode, no real money)" while a press would open Stripe (on, Checkouts left),
  otherwise "Buy credits (simulated, no real money)". Stripe being unreachable
  can't be known before the press; then the toast says it was simulated.
- **CSP** unchanged: the policy already allows the live embedded form's
  origins, and the sandbox page gets the same header (tested).
- Tests: `SandboxStripeEmbeddedCheckoutTest`, `PublicClaimsSandboxStripeEmbeddedOnTest`.

## Addendum (2026-11-14): the Merchant role follows where the money went

The sandbox had one merchant login, always linked to Farragut Grind, so a
payment at any other merchant could not be seen from the merchant's side.

- **Merchant button.** Opens the merchant where this sandbox's employee most
  recently paid *since the sandbox started* (`SandboxFiles::createdAt`), else
  Farragut Grind. The cut-off matters: Jordan's seeded history ends at another
  merchant, which would otherwise open before the visitor has paid anywhere.
  Checklist step 4 goes the same way.
- **Picker.** Beside the Merchant button, a list of the merchants the sandbox
  employee can pay at (`Merchant::redeemableFor`) and a Switch button; the
  list has a screen-reader label ("Choose which merchant to be") and works
  without JS.
- **"See this as the merchant"** on the wallet's confirmation (both Order to
  go and a confirmed pay code), sandbox only, switches to that sale's merchant.
- **How.** No new users: `SandboxMerchant::linkTo` moves `merchants.user_id`
  of the one sandbox merchant login from one merchant row to another, in one
  transaction on the sandbox connection. The merchant pages already show only
  `$user->merchant`'s sales, so each merchant sees only its own. It refuses
  unless a sandbox is active and is the default connection, the user is the
  sandbox's merchant login (`SandboxUserProvider::isSandboxUser`, role
  Merchant) and the merchant is on the sandbox connection and pickable (no
  login, or the sandbox merchant's); a merchant run by any other login is
  never offered or re-linked. The live site's merchant logins and links are
  never read or written.
- **Same switch.** The existing POST `sandbox.role` (CSRF, `sandbox:active`,
  `throttle:60,1,sandbox.role`) takes an optional `merchant_id` (integer,
  only with `role=merchant`). The merchant is found before anyone is signed in,
  so an unknown or someone else's id is a 404 and changes nothing. The three
  locks stay: `sandbox:active`, `Rule::in(SandboxRoutes::ROLES)` and the
  sandbox-user check in `signInAs`. A pay code looked up as the previous
  merchant is dropped from the session.
- Test: `SandboxMerchantSwitchTest` (watches the live connection for writes).
