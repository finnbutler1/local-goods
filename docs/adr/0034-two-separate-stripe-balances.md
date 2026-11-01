# 0034. Two separate Stripe balances: customer funds and Local Goods Operating

**Status:** Accepted, implemented. Builds on ADR 0033 (customer funds and the
solvency rule).

## Context

An advisor asked where an employer's money sits between buying credits and
staff spending them, and how we guarantee it never mixes with Local Goods'
own money. Under ADR 0033 all of it sat in one Stripe test-mode balance, kept
apart only by the ledger. We want two genuinely separate balances.

## What Stripe offers in test mode (checked 2026-10-30)

- **Treasury / Financial Accounts for platforms:** US is a private preview,
  and access is by contacting Stripe sales
  ([Treasury for platforms](https://docs.stripe.com/treasury/connect)). We
  don't have that approval.
- **Funds segregation (`allocated_funds`):** holds payment money in a
  protected state that can't pay Stripe fees or other platform debits, with
  fees moved out by a balance transfer. The closest Stripe answer, but a
  private preview needing a preview API header and an alpha SDK
  ([Funds segregation](https://docs.stripe.com/connect/funds-segregation)).
  It is the production path to ask Stripe about.
- **The platform balance as customer funds, plus a connected account Local
  Goods owns as operating funds:** works today with the v2 no-dashboard
  accounts and v1 transfers we already use. Chosen.
- The reverse (operating on the platform, customer funds in a connected
  account) doesn't work: merchant payouts and card refunds must come from
  the platform balance.

## Decision

- The Stripe platform balance is the **customer funds account**: employer
  money only. Merchant payouts and employer refunds come out of it as before.
- A connected account Local Goods owns, **Local Goods Operating**, is the
  operating account, one per environment. It has no bank account, so Stripe
  never pays it out.
- Fees leave customer funds only by a **sweep**: a recorded Stripe transfer
  with a date, amount and reason, decided by the platform admin,
  append-only, and refused by the solvency guard if it would leave customer
  funds short.

## How it works

**The operating account.** `OperatingAccountSetup` makes it when the platform
admin presses "Create the Local Goods Operating account (test mode)" on
Reconciliation: a v2 account with no Stripe dashboard, Stripe's test
identity values, display name "Local Goods Operating (test mode)", tagged
`lg_operating_account` and with this environment's tag. Its id is kept in
`operating_accounts` (one row, not a demo table, so Reset demo keeps it).
Pressing again creates nothing: the row and its idempotency key exist
before Stripe is asked. Test mode only. The merchant webhook ignores it.

**The sweep.** `OperatingSweeps` is the only way earned money leaves the
customer funds account:

- The platform admin only (`sweep-operating-funds`), with a reason of 5 to
  500 characters, in two steps on Where the money is: Review writes
  nothing; Confirm (a tick box) is refused if the amount that can be swept
  changed since the review.
- Reserve, then send. The `operating_sweeps` row (append-only: only
  Stripe's outcome is filled in later) and an `operating_sweep` ledger line
  on the platform account are written inside `SolvencyGuard::transaction`.
  A sweep of more than was earned takes the platform account below zero,
  which is a shortfall in customer funds, so the guard refuses it and
  nothing is written. A Stripe sweep counts as Stripe money out from that
  moment, so the Stripe floor is checked there too.
- Then, after commit, a Stripe transfer from the available balance to Local
  Goods Operating. The destination comes only from
  `OperatingSweeps::destination()`, which refuses any account a merchant
  uses (`FraudGateTest` allows this one other transfer caller and checks
  that). A definite refusal writes an `operating_sweep_returned` line, so
  the money is unswept again. A lost answer stays pending with the same
  idempotency key and blocks another sweep (unique `open_key`); "Retry owed
  transfers" sends it again.
- In Stripe test mode only money earned on Stripe-paid activity can be
  swept. Money earned on simulated activity is never swept with real
  money. With Stripe off, sweeps are simulated (ledger only).

**The solvency rule.** Money in hand now means the money in the customer
funds account. A sweep takes the same amount off it and off the platform
account, so the rule still balances to the cent; swept money is Local
Goods' own and nobody is promised it. Both new types are in
`LedgerTranches::ACCOUNT_OF_TYPE` (platform) and `MONEY_IN_HAND_TYPES`; the
full replay and `recordsAgree()` cover them. With no sweeps every figure is
as before.

**Reconciliation.** The customer funds balance it expects is net of sweeps.
Each sweep is matched to Stripe's transfer by id, amount and destination,
both ways; a sweep reversed in Stripe is a mismatch for a person and
nothing is written. Local Goods Operating's own Stripe balance must equal
the sweeps sent to it, unless a Reset demo since it was made cleared
earlier sweeps from the ledger (then a note).

**Where it shows.**

- Where the money is: both accounts side by side, what each may be used
  for, both real Stripe balances (staff only, read at most once a minute),
  where earned money came from (from the ledger and the fee audit log, and
  whether any fee is on now, so a one-off is never shown as ongoing
  income), the sweep form and the sweep history. Judges see words and
  counts, never an amount or a balance.
- The employer's Buy credits screen, its paying and confirming states, each
  paid purchase row, and Stripe Checkout under the Pay button: the money is
  held in the customer funds account, stays theirs until staff spend it,
  and is never used for Local Goods' own expenses.
- Merchant payout rows: which account paid them.

## What it still is not

Two separate Stripe test-mode balances are a real separation of balances.
But the customer funds account is not a bank FBO ("for benefit of") or trust
account. Both accounts are controlled by Local Goods through Stripe: the
platform can pull money back from Local Goods Operating by transfer
reversal. In test mode Stripe takes its own card fees from the customer
funds balance; a real deployment would pay them from operating money. A real
deployment would hold customer funds at a bank or a regulated payments
partner (or ask Stripe about funds segregation), and money-transmission
rules may apply. What exactly is required needs legal advice, which we have
not had. We have not asked Stripe whether a platform-owned connected account
would be acceptable in live mode; in live mode, operating money would go to
Local Goods' own bank account instead.

Not done: Treasury, funds segregation, automatic sweeps, and paying Stripe's
card fees back from operating money into customer funds (disclosed instead).

## Assumptions checked against Stripe before building

`scripts/stripe-trial-operating-account.php` ran once on 2026-10-30 (test
key only, objects tagged `lg_env=scratch`, everything reversed, refunded and
deleted afterwards; scrubbed responses in `tests/Fixtures/stripe/operating/`):

- A v2 no-dashboard recipient account with **no bank account** gets
  `stripe_transfers` active at once. Only payouts are restricted (Stripe
  asks for an external account), which is what we want.
- Transfers to it work both with `source_transaction` and from the
  available balance.
- v1 `/balance` with `Stripe-Account` reads its balance exactly (150 cents
  after a $1.00 and a $0.50 transfer), with no payouts.
- Embedded Checkout accepts `custom_text.submit.message` and echoes it back.
- The platform can pull money back from the account by transfer reversal:
  both balances are controlled by Local Goods.

`scripts/stripe-trial-small-sweep.php` ran once on 2026-11-01 under the same
conditions, because the live sweep is $0.16: Stripe accepted a 16-cent and a
1-cent transfer from the available balance (no `source_transaction`, as a
sweep is sent) to an account with no bank account, and its balance then read
exactly 17 cents.

## Live: where the operating balance comes from

Local Goods charges nothing: the platform fee and the merchant fee are both
off. The one exception is a three-minute check that the merchant fee
mechanism worked. The fee audit log on live shows F.B. set the merchant
fee to 2.5% on 2026-10-28 at 12:08 (reason "reflecting fee") and back to
none at 12:11 (reason "no fee now"). One sale cleared inside that window:
redemption #1841, $6.33, of which $6.17 was transferred to the merchant and
$0.16 was kept (Stripe transfer metadata `lg_merchant_fee_cents=16`, found
read-only by the scratch trial). No real person was charged: it is Stripe
test mode with fictional people.

We considered giving the $0.16 back. The existing merchant adjustment can't
(it only re-sends a payout that never went out), so it would need a new
decided money path. We judged that building one for sixteen cents, twelve
days before the freeze, was worse than recording exactly what happened, so
the $0.16 is treated as earned income. After this is deployed, the platform
admin sweeps it to Local Goods Operating, so the operating account holds
real test-mode money that came from this one sale and nothing else. The
Where the money is page says so, and says that Local Goods otherwise charges
nothing; it never presents this as ongoing fee income.

The sweep itself is also proven by tests and by the scratch trial, not
only by this one live sweep. No fee will be switched on before judging.

## Tests

`OperatingAccountMigrationSafetyTest`, `OperatingAccountSetupTest`,
`SolvencySweepTest`, `OperatingSweepTest`, `OperatingSweepReconciliationTest`,
`WhereTheMoneyIsAccountsTest`, `CustomerFundsNoticeTest`, plus updates to
`LedgerTranchesTest`, `FraudGateTest`, `ResetDemoTest`, `StripeCheckoutTest`
and `WhereTheMoneyIsPageTest`.
