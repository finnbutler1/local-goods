# Transparency

This page explains how Local Goods uses AI inside the product and how AI
(Claude Code) was used to build it. It describes what the code does today.
Anything not built yet is marked **planned**.

## AI inside the product

Local Goods has two named AI functions:

| Function | Status | What it does |
|---|---|---|
| **Redemption Fraud Detection** | Live | Scores every redemption for abuse, such as credit-to-cash collusion. |
| **Merchant Eligibility Matching** | Planned | Will decide whether a merchant counts as independent under the program's rule, with written reasoning and a confidence score. |

The rest of this section covers Redemption Fraud Detection, the function that runs today.

### What goes to Claude: anonymous signals only

When an employee redeems credits, the app calculates a small set of signals in
PHP ([`SignalCalculator`](app/Services/Fraud/SignalCalculator.php)) and sends
only those signals to Claude. **No names go to the AI. That includes the
employee, the employer and the merchant.** Claude sees:

- the amount and the balance before the redemption
- whether the amount is a round number, or uses nearly the whole balance
- how many times this employee visited this merchant in the last 7 and 30 days
- how many redemptions the employee has made before, and their median amount
- the merchant's category (cafe, restaurant, retail, salon, fitness) and the
  typical ticket range for that category
- the local hour, and whether it falls outside business hours

The full instructions Claude receives are in
[`resources/prompts/redemption-fraud-detection.md`](resources/prompts/redemption-fraud-detection.md).

### What Claude decides

Claude replies in a fixed JSON format with three things:

- a **risk score** from 0 to 100
- a **decision**: `clear` or `hold`
- a **plain-English explanation** that an HR manager can read

The app saves all three, along with the signals and the model that scored it.
The [BID admin view](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/demo/bid-admin)
shows them for every redemption, so the AI's reasoning is never hidden.

### The score-70 override

The app does not simply accept Claude's decision. If Claude says `clear` but
gives a risk score of **70 or higher**, the app holds the redemption anyway
([`config/fraud.php`](config/fraud.php), `hold_if_score_at_least`). This
means a high-risk score can never be approved because of an inconsistent reply.

### Failing closed

When the AI call fails, Local Goods holds the redemption instead of approving
it. Any of these counts as a failure:

- an API error or timeout
- a missing API key or a refusal
- malformed JSON
- a score out of range or an empty explanation

In each case the app records a hold with score 100, marks it `scored_by =
fallback`, and shows the explanation: *"Automatic hold: the AI check could not
be completed, so a person will review this redemption."* That review is only
partly built today (see below). See
[`RedemptionFraudDetector`](app/Services/Fraud/RedemptionFraudDetector.php)
and [ADR 0003](docs/adr/0003-fail-closed-fraud-detection.md).

### Human review of holds (partly planned)

A held redemption is never thrown away and never approved automatically. A
person can see it, but **approving or rejecting it in the app is not built
yet**:

- **Today:** a held redemption gets the status *under review*. The employee's
  credits stay reserved, so neither side loses anything while it waits. The
  redemption appears on the BID admin view with the AI's score, decision and
  explanation, and the admin view counts how many are waiting.
- **Planned:** buttons that let a BID or HR reviewer approve or reject a held
  redemption, with their name and reason recorded next to the AI's decision.
- **Planned:** once Stripe Connect is built, no money will move to a merchant
  for a held redemption until a person clears it. **Today no money moves at
  all**, whether a redemption is cleared or held. Redemptions only update the
  credit ledger.

### Which merchants are eligible (planned)

Today employees can only redeem at the demo's merchants, which were seeded by
hand as fictional independent businesses. The app does not yet check whether a
merchant is independent. Merchant Eligibility Matching will do that, and it is
**planned**.

### Synthetic data

Every person, business and transaction in the demo is fictional. No real
employee, customer or card data is used.

## How I built Local Goods with Claude Code

I built Local Goods with [Claude Code](https://claude.com/claude-code), working
in small steps and reviewing every change before committing it.

- **A project brain.** [`CLAUDE.md`](CLAUDE.md) holds the brief that Claude
  Code reads at the start of every session. It covers the customers, the MVP
  scope (one BID, one employer), the exact independence rule, the stack and
  the hard rules: never commit secrets, synthetic data only, small commits,
  tests for business logic.
- **Small, descriptive commits.** Each working change (schema, fraud scorer,
  wallet, admin view, each deploy fix) went in as its own commit.
- **Tests without the real API.** Claude Code wrote feature tests that swap the
  real Claude client for [`FakeClaudeClient`](tests/Fakes/FakeClaudeClient.php).
  The fail-closed rule and the score-70 override are covered by tests that
  never call the API. GitHub Actions runs the tests on every push.
- **Deploying and debugging.** Claude Code helped write the Elastic Beanstalk
  config and deploy hooks, and helped diagnose live issues such as a 500 error
  on first deploy and a SQLite "database is locked" error.
- **Decisions on record.** The main design choices are written up as short
  decision records in [`docs/adr/`](docs/adr/).

I made the product decisions, reviewed the code and tested each change in
the browser. Claude Code did most of the typing.
