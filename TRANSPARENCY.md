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

1. We wrote 90 synthetic redemptions
   ([`data/fraud_eval_cases.csv`](data/fraud_eval_cases.csv)): clearly normal,
   clearly suspicious, and some genuine close calls.
2. **A person labeled every case** *normal* or *suspicious*, with a short
   reason, **before the AI saw any of them**. The labels were committed to Git
   first, so the history shows they came before the results.
3. **The newest 50 cases (`holdout_3`) are locked.**
   [`data/fraud_eval_lock.json`](data/fraud_eval_lock.json) holds a SHA-256 of
   every row (facts, label and note) and the commit the labels were made in
   (`5f594c1`). It was committed before the AI saw those cases.
   `php artisan fraud:eval` refuses to run if any locked row changes, and a
   test fails in CI if one does.
4. `php artisan fraud:eval` sends each case through the **real production
   check**: the same prompt file, the same model, and the same signal rules
   ([`SignalCalculator::fromFacts`](app/Services/Fraud/SignalCalculator.php)).
   A `hold` counts as suspicious and a `clear` counts as normal.
5. The cases come in groups. We may change the prompt to fix mistakes on the
   20 **dev** cases, but **never** because of a holdout case. `holdout_2` (10)
   and `holdout_3` (50) were labeled after the last prompt change and never
   seen while changing it.
6. We report two numbers, not just agreement. **Catch rate:** of the cases the
   person labeled suspicious, how many the AI held. **Hold precision:** of the
   cases the AI held, how many the person labeled suspicious. Each comes with a
   95% Wilson confidence interval and always says how many suspicious cases it
   was measured on.
7. Every miss and false alarm is grouped by **pattern tags** worked out from
   the case's facts only, never from the label or the AI's answer: round
   amount, 3x or more the usual amount, 90%+ of the balance, a merchant not
   visited in 30 days, outside business hours, 5+ visits in 7 days, above the
   category's typical range, and the merchant category.
8. We publish the real numbers, low or not, with every disagreement shown next
   to the person's reason. Every run is kept with its date, model and prompt
   version (a fingerprint of the prompt file).

**Headline: caught 17 of 26 suspicious cases; 17 of 19 holds were
suspicious.** Measured on the 50 locked `holdout_3` cases (26 labeled
suspicious) with the current prompt `8d5e1e70`. 95% confidence intervals:
catch rate 46–81%, hold precision 69–97%. Overall agreement 78% (39 of 50).

**The weak spot is misses, not false alarms.** The AI cleared 9 of the 26
suspicious cases, mostly ones with a single strong signal (a big jump over the
employee's usual spend, or nearly the whole balance) at normal hours and
inside the category's typical range. 4 of the 9 were at the salon, whose wide
$25–$90 typical range made amounts 2 to 3.5 times the employee's usual look
normal. A cleared redemption goes through without a person looking at it, so
a miss is the costlier mistake. The 2 false alarms are frequent visitors
spending a round amount well above their usual, which the prompt treats as
possible credit-to-cash collusion. A held redemption waits for a person, so a
false alarm costs a delay, not a wrong payout. The full per-pattern table, with
the AI's reasoning for each, is in the
[results](docs/evals/fraud-eval-results.md).

The prompt was frozen for this measurement and will not be changed because of
these results without a fresh set of unseen cases to measure the change.

Every run is kept (model `claude-opus-5-5`). Each cell is agreement, then how
many of the suspicious cases the AI caught:

| Run | Prompt | Dev | Holdout | Holdout 2 | Holdout 3 | Note |
|---|---|---|---|---|---|---|
| 1 | `e5bcf207` (original) | 85% (17/20), caught 6 of 7 | 80% (8/10), caught 3 of 4 | n/a | n/a | First run. Its holdout cases were unseen at the time. |
| 2 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | n/a | n/a | Holdout not fully independent (see below). |
| 3 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | n/a | The earlier headline. Holdout 2 had only 4 suspicious cases. |
| 4 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | **78% (39/50), caught 17 of 26** | **Headline.** 50 locked cases, 26 suspicious. |

Run 3's "0 suspicious cases missed" was measured on only 4 suspicious cases.
On 26 suspicious cases, the same prompt missed 9, which is why the headline
moved to `holdout_3`.

Change 1 added one rule to the prompt: using all or nearly all of the balance
at a merchant not visited in the last 30 days is enough to hold on its own. It
was written to fix one dev case (FE-18). **But the holdout results from run 1
had already been seen when it was made**, and holdout case FE-23 is a similar
pattern. So the 90% holdout score in runs 2 to 4 is not a fully independent
test. That is why the `holdout_2` and `holdout_3` cases were added.

Full results: [`docs/evals/fraud-eval-results.md`](docs/evals/fraud-eval-results.md),
also shown on the public `/eval` page. Limits: 50 cases is still a small
sample (the confidence intervals above are wide), the cases are synthetic, and
one person's labels are one opinion. Agreement shows how closely the AI matches
that person, not whether either of them is right about real fraud.

### Checking the person's labels: blind adjudication

One person's labels are one opinion, and where the person and the AI
disagree, the person may be the one who is wrong. So BID staff and contest
judges check the disagreements, blind
([ADR 0011](docs/adr/0011-blind-adjudication-of-eval-disagreements.md)):

- **What they vote on:** every case, in every split, where Finn's label and the
  AI's call disagree, mixed with the same number of randomly chosen cases where
  both agreed, so nobody can tell which cases are disputed. Finn can adjust the
  set before publishing it; after that it is locked.
- **What they see:** only the facts of each redemption, one at a time, in a
  random order of their own. Never Finn's label or note, the AI's decision,
  score or explanation, or anyone else's vote. They choose normal or
  suspicious and write a short reason, and can change their mind until they
  press Submit all.
- **Who votes:** only BID staff and contest judges with their own personal
  link. The public judge link, friends and Finn can't vote.
- **One question first:** "Have you looked at the Local Goods eval results
  page before today?" Someone who has may already know the answers, so results
  are shown for everyone and again for only those who said no. While voting is
  open, the eval pages hide the per-case answers from adjudicators who haven't
  submitted. Anyone not signed in can still see them, which is why the question
  is asked.
- **How it's reported:** a separate section on `/eval`, never mixed into the
  headline. "On N disputed cases, adjudicators sided with the AI in X, with
  Finn in Y, split in Z", how many adjudicators voted, how often they agreed
  with the shared label on the control cases (a reliability check), and
  results by split and by pattern. Each case's votes and reasons, without
  names, appear once voting closes. **Adjudication never changes Finn's
  labels or any headline number.**

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
