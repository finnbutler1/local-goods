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
The BID admin view (after [logging in](https://d4ghrpm72uczx.cloudfront.net/login))
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
- **Today:** a person at the BID approves or rejects each held redemption on
  the admin view. Approving spends the reserved credits; rejecting returns
  them to the employee. Who decided and when are recorded on the redemption
  and in the append-only ledger, shown next to the AI's decision, and a
  redemption can only be decided once.
- **Planned:** a written reason with each decision, and the same buttons for
  HR reviewers.
- **Planned:** once Stripe Connect is built, no money will move to a merchant
  for a held redemption until a person clears it. **Today no money moves at
  all**, whether a redemption is cleared or held. Redemptions only update the
  credit ledger.

### How we measure it

We test Redemption Fraud Detection against a person's judgment, not our own
expectations:

1. We wrote 30 synthetic redemptions
   ([`data/fraud_eval_cases.csv`](data/fraud_eval_cases.csv)): clearly normal,
   clearly suspicious, and some genuine close calls.
2. **A person labeled every case** *normal* or *suspicious*, with a short
   reason, **before the AI saw any of them**. The labels were committed to Git
   first, so the history shows they came before the results.
3. `php artisan fraud:eval` sends each case through the **real production
   check**: the same prompt file, the same model, and the same signal rules
   ([`SignalCalculator::fromFacts`](app/Services/Fraud/SignalCalculator.php)).
   A `hold` counts as suspicious and a `clear` counts as normal.
4. The cases are split into 20 **dev** cases and 10 **holdout** cases. We may
   change the prompt to fix mistakes on dev cases, but **never** because of a
   holdout case, so the holdout number is the honest one.
5. We publish the real numbers, low or not, with every disagreement shown next
   to the person's reason. Every run is kept with its date, model and prompt
   version (a fingerprint of the prompt file), so results before and after a
   prompt change can be compared.

**Headline: 80% agreement on 10 unseen cases with the current prompt, and 0
suspicious cases missed.** Every disagreement is a false alarm on the same
pattern (a frequent customer spending a round amount well above their usual),
which the prompt treats as possible credit-to-cash collusion. A held
redemption is never approved automatically: it waits under review for a
person, so a false alarm costs a delay, not a wrong payout. This is a small
sample: with 10 cases, one case moves the score by 10 points.

The headline comes from run 3's `holdout_2` cases (FE-31 to FE-40). They were
written and labeled by hand after the last prompt change, committed before
the AI saw them, and scored with the prompt unchanged. The prompt will not be
changed because of these results. Any future prompt change needs a fresh set
of unseen cases to measure it.

Every run is kept (model `claude-opus-5-5`):

| Run | Prompt | Dev | Holdout | Holdout 2 | Note |
|---|---|---|---|---|---|
| 1 | `e5bcf207` (original) | 85% (17/20) | 80% (8/10) | n/a | First run. Its holdout cases were unseen at the time. |
| 2 | `8d5e1e70` (change 1) | 90% (18/20) | 90% (9/10) | n/a | Holdout not fully independent (see below). |
| 3 | `8d5e1e70` (change 1) | 90% (18/20) | 90% (9/10) | **80% (8/10)** | **Headline.** Holdout 2 is a clean test of change 1. |

Change 1 added one rule to the prompt: using all or nearly all of the balance
at a merchant not visited in the last 30 days is enough to hold on its own. It
was written to fix one dev case (FE-18). **But the holdout results from run 1
had already been seen when it was made**, and holdout case FE-23 is a similar
pattern. So the 90% holdout score in runs 2 and 3 is not a fully independent
test. That is why the 10 `holdout_2` cases were added.

Full results: [`docs/evals/fraud-eval-results.md`](docs/evals/fraud-eval-results.md),
also shown on the public `/eval` page. Limits: 30 cases is a small
sample, the cases are synthetic, and one person's labels are one opinion.
Agreement shows how closely the AI matches that person, not whether either of
them is right about real fraud.

### Which merchants are eligible (planned)

Today employees can only redeem at the demo's merchants, which were seeded by
hand as fictional independent businesses. The app does not yet check whether a
merchant is independent. Merchant Eligibility Matching will do that, and it is
**planned**.

### How reviewers get in

Judges and other reviewers never create an account or choose a password. Finn
makes each one a personal **magic link**; opening it signs them into a
read-only view of the app. The README also has one **public judge link** to a
shared, generic reviewer account.

- **What a link can do:** look at every screen and leave feedback. It can't
  change data, approve or reject a redemption, or open the admin pages that list
  reviewers' names and emails. It can only ever sign in to a reviewer account.
- **How long it lasts:** until judging closes (November 30, 2026, 11:59 PM
  Eastern), unless Finn switches it off or replaces it sooner. Either way, any
  open session ends on its next click.
- **What we keep:** a fingerprint (hash) of each link, never the link itself,
  plus when each reviewer first and last visited and the feedback they send. The
  link's secret part never reaches our server logs.
- **Attribution:** feedback from a personal link carries that reviewer's name.
  Feedback from the public link is anonymous, labeled "Public judge access",
  and limited to a few notes a minute so one visitor can't flood the inbox.

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
