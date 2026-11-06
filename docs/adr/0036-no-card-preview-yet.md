# 0036. No card preview yet: Issuing switches on in test mode, but funding cards needs identity verification

**Status:** Accepted. Not built. Research and one scratch trial only: no app
code, migration, money, ledger, reconciliation, solvency, fraud prompt, AI
input, eval data or lock change.

## Context

We looked at showing Stripe Issuing as a clearly labelled preview of a future
Local Goods card, next to pay codes (never replacing them). The idea was a
wallet card backed by a real test-mode virtual card, plus a "simulate a swipe"
that a rule (not the AI) approves at a Golden Triangle merchant and declines
outside it, with no credits moving.

The condition was set in advance: if Issuing can't be used in test mode
without approval, stop and say so, and don't build a fake version.

## What we did (2026-11-04 and 2026-11-05)

- **Where:** a throwaway Stripe sandbox, never the test account the live demo
  uses. Stripe says card fees come from the Stripe balance, and the docs don't
  say whether test mode does the same. A fee in the shared account would be a
  permanent reconciliation mismatch.
- **Script:** `scripts/stripe-trial-issuing.php`, with the local real-time
  listener `scripts/stripe-trial-issuing-listener.php`. It used test keys only
  and refused to run if both keys reached the same account. People and
  merchants were made up, and objects were tagged `lg_env=scratch`.
- **Existing test account:** two read-only calls, nothing created.

## What we found

- **Turning Issuing on is instant.** In the new sandbox, Issuing → Get Started
  switched it on with no approval or form. The existing test account answered
  "Your account is not set up to use Issuing"; we didn't switch it on there.
- **Cards can't be funded through the API in this sandbox:**
  - A top-up with `destination_balance=issuing`, as shown in Stripe's
    Issuing balance guide, was refused: "Received unknown parameter:
    destination_balance". The same happened with a test bank token as the
    source.
  - The `fund_balance` test helper was refused: "A Funding Instruction is not
    currently supported for US."
- **Cards can't be funded through the Dashboard without identity
  verification:**
  - Issuing → Add funds offered only one destination, the "Refunds and
    disputes balance". Three tries put $150 of test money there. That
    balance can't pay for card purchases.
  - The Balances page showed a "Business account" asking for more
    information. This appears to be what Issuing cards draw from here (our
    reading; Stripe didn't say so).
  - The form checked the details like a real identity check. It rejected
    the test SSN "0000" with "The response you provided is not allowed".
    We stopped, rather than enter invented details that look like a real
    person's SSN.
- **The trial stopped at funding, as designed.** No cardholder, card,
  physical card or authorization was created, and no fixtures were saved.
  Simulated swipes, merchant data, simulated shipping and real-time decisions
  were **not tested**.

Two points come from Stripe's docs only and were not tested here. They matter
for any later card:

- A real-time authorization reply must reach Stripe within **2 seconds**, or
  the account's timeout setting decides. Redemption Fraud Detection can't be
  relied on to answer that fast, so a card swipe can't wait for it.
- Spending controls can limit category, country, card presence and amount,
  but not postal code or district. Limiting by merchant ID is private preview.
  "Only in the BID" would need the real-time webhook.

## Decision

Don't build the card preview. Funding a test card here needs a business
account behind a real identity check. We chose not to complete that check
with invented details, so a working preview isn't possible this way, and we
won't show a fake one. Pay codes remain how employees pay in person, and the
MVP still has no card program.

## Consequences

- No route, page, config or Stripe call for Issuing exists in the app. The
  `CLAUDE.md` rule "No Visa/Mastercard card program in the MVP" stands
  unchanged.
- The trial scripts stay in `scripts/` as the record of what was tried. CI,
  deploys and the app never run them.
- The sandbox holds only the refused attempts and $150 of test money, and is
  deleted by hand.
- The README's roadmap paragraph says a real card program would likely need
  a bank or regulated partner holding the funds, and legal advice on prepaid
  card rules. That is our understanding, not something we tested or checked
  with Stripe. Likewise, "funding cards requires a verified business account"
  is what we saw in one sandbox. Only the 2-second reply limit comes from
  Stripe's docs.
- To revisit: a Stripe test-mode way to fund the Issuing balance without
  identity verification, or verification of a real business. In either case,
  a fresh trial goes through the remaining questions (cards, swipes, shipping,
  the 2-second reply) before anything is built.

## Sources (read 2026-11-04)

- [Test your Issuing integration](https://docs.stripe.com/issuing/testing)
- [Issuing balance](https://docs.stripe.com/issuing/funding/balance)
- [How to apply for Issuing](https://support.stripe.com/questions/how-to-apply-for-issuing)
- [Real-time authorizations](https://docs.stripe.com/issuing/controls/real-time-authorizations)
- [Spending controls](https://docs.stripe.com/issuing/controls/spending-controls)
- [Ship cards](https://docs.stripe.com/issuing/cards/physical/ship-cards)
