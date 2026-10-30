# 0032. Live demo activity in Stripe test mode

**Status:** Accepted, implemented. Off until Finn switches it on.

## Context

By judging week (Nov 23, 2026) a judge should be able to see weeks of
genuine activity without anyone explaining it:

- busy lunch hours at several merchants;
- both ways of paying;
- tax on itemised orders;
- a held payment that a person decided;
- a refund approved with a reason;
- merchant payouts arriving in Stripe.

The seeded history can't show this honestly. It is simulated, it is dated
in the past, and Stripe has never seen it.

We would not backdate anything or invent Stripe objects. The only honest
way to get dated Stripe history is to make it, a little each weekday,
from now until judging.

This had to leave alone the fraud prompt (`8d5e1e70`), its signals, the AI
input, eval data, holdouts, locks, adjudication and the money logic.

## Decision

### A generator that only uses the normal services

`App\Services\DemoActivity\ActivityGenerator` runs one tick every five
minutes on weekdays, from 07:00 to 19:00 DC time (`demo-activity:tick`).
A tick carries out the slots of today's plan that are due, through the
same services a person's actions go through:

- **Buying credits:** `CreditLedger::requestPurchase`, then
  `StripeTestPayments`. The signed `payment_intent.succeeded` webhook
  marks the purchase paid.
- **Monthly allowances and a weekly Recognition:**
  `CreditLedger::allocateMany`.
- **Orders to go:** `RedemptionService::redeem`.
- **Payments in person:** `PayCodes` issue, then lookup, then confirm.
- **About one refund request a week:** `CorrectionRequests::forRedemption`,
  filed as the Farragut Grind merchant login.

Every redemption is scored by the real Redemption Fraud Detection on the
usual anonymous signals. Payouts go out as the usual Stripe test
transfers through `TransferGate`.

The generator never writes a money row itself. It never approves, denies
or decides anything; a person does that, with a reason.

### The plan

`DayPlanner` is pure: the same date always draws the same plan.

- **Volume:** 10–14 redemptions Monday to Thursday, 6–9 on Friday.
- **Timing:** about 70% at lunch, peaking 12–1, with some morning coffee
  and some afternoon visits.
- **Merchants:** chosen by time of day.
- **Baskets:** about 75% menu baskets, with tax added. The rest are
  non-round typed-in amounts.
- **Payment:** about 40% in person.

About every other day, one slot is **planned as unusual**: a round $40–$60
at the café, at an odd hour, by someone for whom it is most of their
balance. This is honest behaviour the AI should question, and it is how
holds happen at all. The AI is never told the slot was planned that way.
The action log records it, and the status page shows how often such
slots were actually held.

Jordan Rivera and the planted collusion patterns are never picked.

### Paying for credits without a browser

Embedded Checkout needs a person at a browser. A new gateway method,
`createTestPayment`, creates and confirms a PaymentIntent with
`pm_card_visa`. It carries exactly the metadata Checkout's payment carries,
plus `lg_demo_activity=1`, so the existing webhook and reconciliation
handle it unchanged. This is the only change near the money code:

- one interface method;
- its SDK, notice-safe and fake implementations.

It refuses unless the secret key is a test key.

### Safety

- **Kill switch.** `DEMO_ACTIVITY_ENABLED` defaults to false. No deploy
  file sets it, and a test checks that. It also stops by itself after
  `DEMO_ACTIVITY_ENDS_ON` (2026-11-30).
- **Test mode only.** The tick refuses unless `StripeMode::active()` is
  true with a test secret.
- **Daily caps** (config):
  - actions per day: 25;
  - spend per day: $400;
  - purchases per day: $7,500, enough for one month's allowances, as HR
    would buy them;
  - refund requests per day: worth $50 at most.
- **Queue caps.** With more than 5 held payments waiting, no new
  redemptions that day. With more than 3 refund requests open, no new
  request. The reason is recorded either way.
- **The day stops** after 3 failed actions, or after two reconciliation
  mismatches in a row. Reconciliation is checked at the start of a tick
  once something new was done or a transfer sent, and again at the end of
  a tick that sent transfers, so the recorded result is never stale.
- **A late webhook is not a mismatch.** The trial of 2026-10-30 showed a
  paid purchase still pending (its webhook not processed) halting the day.
  When every difference is only Stripe's webhook still on its way (a
  purchase Stripe was paid for but still pending, a refund reversal or card
  refund not recorded yet), the check records "waiting for Stripe's webhook"
  and is not counted until the oldest has waited `webhook_grace_minutes`
  (30). A webhook that never comes, or any other difference, counts as
  before.
- **Idempotent.**
  - `demo_activity_days.date` is unique.
  - Every slot is claimed with a unique (day, slot) row before it runs.
  - A second or overlapping run does nothing twice.
- **Never throws.** Each action's error is stored on its row and logged.
  The command always exits 0.
- **Hands off.** The generator never touches reviewers, users,
  applications, adjudication, eval files, locks or fee settings.
- **No email.** Nothing in the app sends any.

### Telling generated from person-made

- **The action log.** `demo_activity_actions` links every purchase,
  redemption and correction the generator made.
  `isGeneratedDemoActivity()` on `Redemption` and `CreditPurchase` reads
  that link.
- **The marker.** A subtle "Demo activity" badge (`<x-demo-activity-badge>`)
  appears on generated purchases on the employer page, and on generated
  redemptions in the BID admin review, merchant dashboard, wallet,
  employer returned-credit rows and the corrections queue.
- **What stays unmarked.** Decisions a person makes carry no marker.

### Waiting for a person

`WaitingForAPerson` counts held payments and undecided refund requests,
with the oldest one's age. It is shown on:

- the platform admin home;
- the BID admin's fraud review;
- the corrections queue;
- `/bid-admin/demo-activity`.

Past 3 days, an item is called out plainly as waiting for a person.
Nothing is ever decided automatically.

### Status

`/bid-admin/demo-activity` is read-only for the BID admin, the platform
admin and judges (via View as). `demo-activity:status` shows the same:

- the switch, test mode and end date;
- today's progress and why it stopped, if it did;
- totals by day: payments, in person, spend, credits bought, held,
  Stripe transfers, failures, reconciliation;
- the latest actions, with employees by initials;
- the hold rate actually seen.

### Scheduling

Cron on the instance runs Laravel's scheduler.

- **The hook.** `.platform/hooks/predeploy/02_scheduler.sh` (and its
  confighook) writes `/etc/cron.d/local-goods-scheduler`. That line runs
  `scripts/run-scheduler.sh` every minute.
- **The hook can never fail a deploy.** It has no `set -e`, every step may
  fail, and it always ends `exit 0`. A test runs it against an unwritable
  path.
- **The wrapper.** It reads Beanstalk's environment properties fresh each
  minute, so turning the switch off stops the next run. It starts no PHP
  unless `DEMO_ACTIVITY_ENABLED` is true, then runs `schedule:run` as
  `webapp`.
- **Never inside a web request.** A failure can't break a page, and the
  origin lock is untouched. There is one instance, so nothing runs twice.

We rejected a scheduled GitHub Action calling a protected endpoint, for
five reasons:

- it needs a new public write endpoint and a shared secret;
- each call must finish inside CloudFront's 30-second origin limit;
- the work would share the PHP workers that serve judges;
- GitHub's scheduled runs are often late or skipped, which blurs the
  lunch peak;
- scheduled workflows are disabled after 60 days of repository
  inactivity.

## Consequences

- **What appears without anyone acting:**
  - busy lunch hours, several merchants, both ways of paying and tax on
    itemised orders;
  - Stripe transfers, once the first generated purchase is paid;
  - a monthly allowance purchase in Stripe.
- **What appears only when a person acts:**
  - "A held payment that a person decided" appears only when Finn (or a
    BID admin) decides the holds.
  - "A refund approved with a reason" appears only when the platform admin
    approves one of the weekly requests.
  - The generator never fakes either.
- **The AI cost:** about 200 real fraud checks up to judging, one per
  generated payment.
- **Allowances are unmarked.** Allocations aren't linked to the action log,
  so the employer's "Recently given" list shows generated allowances
  without the marker. They are listed on the demo activity page.
- **Resets.** Reset demo (local only) clears the two new tables with the
  rest of the demo data.
- **Migration.** `2026_10_31_000001_add_demo_activity` is additive. Snapshot
  `before-demo-activity`.
