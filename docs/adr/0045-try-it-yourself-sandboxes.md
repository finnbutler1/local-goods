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
