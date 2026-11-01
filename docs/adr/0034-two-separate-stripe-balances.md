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

## Live: the operating balance is $0.00

Local Goods charges nothing (the platform fee and the merchant fee are both
off), so on live it has earned nothing to sweep, and the Local Goods
Operating balance is $0.00. That is the true figure, not a gap. The sweep
is proven by tests and by the scratch trial, not by live money; no fee will
be switched on to make a live balance before judging.

One exception was found while planning this: on 2026-10-28 the merchant fee
was switched on live for three minutes (12:08 to 12:11) to check the
mechanism worked, and one sale, redemption #1841, was charged in that
window. It was a test, not a pricing decision, and we say we charge
nothing, so we decided to **give it back to the merchant rather than keep
or sweep it**. The existing merchant adjustment can't do that (it only
re-sends a payout that never went out), so how it is returned is part of
this work.
