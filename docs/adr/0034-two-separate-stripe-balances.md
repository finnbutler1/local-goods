# 0034. Two separate Stripe balances: customer funds and Local Goods Operating

**Status:** Accepted, being built. Builds on ADR 0033 (customer funds and the
solvency rule). This record is completed when the work is done; until then it
holds the research, the decision and what we already know about live.

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
