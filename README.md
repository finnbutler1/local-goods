# Local Goods

## For judges

**Public judge link:** *coming with the next deploy. The link will go here.*
It signs you straight into a read-only reviewer view of the live app: no
account, no password. Works until judging closes on November 30, 2026.

**Demo video (2–3 min):** *coming soon.*

Inside, a guided tour walks through each role, **View as** shows the app as an
employee, BID admin, employer or merchant, and the **Feedback** button on every
page sends notes scored against the rubric. Named reviewers get a personal link
instead, so their feedback carries their name
([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)).

---

**An employee perk program designed to keep employer spending in the neighborhood: credits meant to be spent only at independent local merchants.**

> **Status:** working demo. Redemptions, AI fraud scoring and human approve/reject of held redemptions are live. Merchant Eligibility Matching is built: the AI assesses real BID businesses and a BID admin decides, but approved businesses don't join the demo yet. Payouts are **planned**: no money moves yet, and the demo merchants are seeded by hand.

**Live app:** https://d4ghrpm72uczx.cloudfront.net

[![CI](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml/badge.svg)](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml)

**Stack:** Laravel 13 · PHP 8.3+ (CI on 8.4) · Tailwind CSS 4 (Vite 8) · SQLite (local) / Amazon RDS MySQL (production) · Claude API (`anthropic-ai/sdk`) · AWS Elastic Beanstalk · GitHub Actions · Stripe Connect (planned)

---

## The problem

Employers in downtown business districts spend heavily on employee perks, but
very little of that money stays in the surrounding neighborhood. It goes to
national chains and online platforms. Yet independent merchants return far
more to the local economy: [Civic Economics](https://www.civiceconomics.com/) studies found that independent businesses recirculate roughly 48% of their revenue locally, versus under 14% for chains: roughly 3x more per dollar. Business
Improvement Districts (BIDs) want to support their independent merchants, and
employers want a perk their staff will actually use.

## How it works

1. **The employer buys credits** for its employees through its local BID (the
   MVP uses the Golden Triangle BID in Washington, DC). *Planned: in the demo,
   employees start with seeded credits.*
2. **Employees redeem them at merchants inside the BID**, using their phone as
   the card. *Today: employees can only redeem at merchants in the demo's
   hand-seeded list. Merchant Eligibility Matching assesses real businesses in
   the BID for the BID admin to approve or reject. Turning an approved business
   into a participant is planned.*
3. **AI checks every redemption.** *Live: suspicious redemptions are held and
   shown on the BID admin view, where a person approves or rejects them.
   Planned: Stripe Connect payouts that only go out for cleared redemptions.
   No money moves yet.*

**Try it:** [log in](https://d4ghrpm72uczx.cloudfront.net/login) with a demo account (available on
request; there is no public sign-up). As the employee, redeem a normal amount,
then click **Try a suspicious redemption**. As the BID admin, see the AI's
score, decision and explanation for each one, and approve or reject held
redemptions. The [AI eval results](https://d4ghrpm72uczx.cloudfront.net/eval) are public.
Judges and other reviewers sign in with a magic link (no account, no password;
see **For judges** above) to a read-only view with a guided tour, a "view as"
switcher for each role, and a Feedback button on every page
([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)).

## The two AI functions

| Function | Status | What it does |
|---|---|---|
| **Redemption Fraud Detection** | **Live** | Scores every redemption 0–100 for abuse such as credit-to-cash collusion. It returns `clear` or `hold` with a plain-English explanation. Only anonymous signals are sent (amount, balance, visit counts, category, hour), never names. |
| **Merchant Eligibility Matching** | **Built** | Suggests whether a real business in the BID is independent (not a franchise, not chain- or PE-owned, five or fewer locations), with a classification, written reasoning and a 0–1 confidence. It sees only the business's public license facts and has no web access. A BID admin approves, rejects or asks for research. A failed or invalid answer becomes "undetermined" for a person, never a guess. Every business is labeled "Candidate — not a Local Goods participant." Measured against 60 hand labels at [`/eval/merchants`](https://d4ghrpm72uczx.cloudfront.net/eval/merchants) ([ADR 0013](docs/adr/0013-merchant-eligibility-matching.md)). |

## Fails closed

When the AI call fails, Local Goods holds the redemption instead of approving it. It fails closed, the way a bank's fraud team would design it.

The following all produce a hold with risk score 100:

- an API error, timeout or missing key
- a refusal
- malformed JSON
- an out-of-range score

If Claude says `clear` but gives a score of 70 or higher, the app holds it
anyway. A held redemption keeps the employee's credits reserved and appears on
the BID admin view, where a person approves it (the reserved credits are spent)
or rejects it (the credits go back to the employee). Each decision is recorded in
the append-only ledger with who decided and when, and a redemption can only be
decided once. Payouts are planned, so no money moves either way yet. See
[`RedemptionFraudDetector.php`](app/Services/Fraud/RedemptionFraudDetector.php),
its [tests](tests/Feature/RedemptionFraudDetectorTest.php) and
[ADR 0003](docs/adr/0003-fail-closed-fraud-detection.md).

## How it meets the judging criteria

Only what exists today is claimed. Everything else is marked **planned**.

| Category | Points | Evidence |
|---|---|---|
| **Functionality** | 25 | **Live:** after [logging in](https://d4ghrpm72uczx.cloudfront.net/login), the employee wallet shows a balance, a redeem form and recent redemptions; the BID admin view shows every AI decision; Approve and Reject buttons for held redemptions ([`RedemptionReviewService`](app/Services/RedemptionReviewService.php)); a Reset demo button; logins with six roles, each with its own dashboard ([ADR 0007](docs/adr/0007-fortify-for-login-and-a-role-column.md)); read-only reviewer access from magic links (no passwords, valid until judging ends, revocable), with a guided tour, "view as" for each role and in-app feedback scored against this rubric ([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)); an append-only credit ledger ([`CreditLedgerEntry`](app/Models/CreditLedgerEntry.php)); a BID review queue for Merchant Eligibility Matching with Approve, Reject and Needs research, logged append-only with who and when ([`MerchantReviewDecision`](app/Models/MerchantReviewDecision.php)). **Planned:** employer credit purchase, Stripe Connect payouts (test mode), turning an approved candidate into a participant. |
| **Problem** | 20 | A specific buyer (employer HR via the BID), a specific place (Golden Triangle BID, DC) and a specific leak (perk dollars leaving the neighborhood). See [The problem](#the-problem) and [ADR 0002](docs/adr/0002-one-bid-and-one-employer-for-the-mvp.md). |
| **AI** | 20 | **Live:** Redemption Fraud Detection with structured JSON output, a [versioned prompt file](resources/prompts/redemption-fraud-detection.md), anonymous [signals](app/Services/Fraud/SignalCalculator.php), the score-70 override and fail-closed handling. **Measured:** [caught 17 of 26 suspicious; 17 of 19 holds were suspicious](docs/evals/fraud-eval-results.md), on 50 unseen cases (26 labeled suspicious) that were labeled by hand and locked with a SHA-256 per row before the AI saw them. 95% confidence intervals: catch rate 46–81%, hold precision 69–97%. Misses are the weak spot: the 9 missed cases mostly had one strong signal at normal hours, and the results break them down by pattern. Earlier, smaller runs are kept below the headline. 90 synthetic cases in all; method in [TRANSPARENCY.md](TRANSPARENCY.md#how-we-measure-it). **Built:** Merchant Eligibility Matching, with a [versioned prompt](resources/prompts/merchant-eligibility.md) that holds the independence rule word for word, four public facts as input, validated JSON, a fail-closed fallback to "undetermined", and a plain statement that the AI has no web access. **Eval:** [`merchant:eval`](app/Console/Commands/MerchantEval.php) runs against 60 hand labels locked before the AI saw them (40 dev, 20 holdout, scored separately; holdout once per prompt version). It reports agreement next to the always-"not eligible" baseline, how many independent businesses the AI found and how many of its "eligible" calls were right, with Wilson intervals: [results](docs/evals/merchant-eval-results.md). |
| **Code quality / GitHub** | 15 | A [test that sends every write route as a judge](tests/Feature/JudgeReadOnlyTest.php) and checks nothing changes. Automated tests run by [GitHub Actions CI](.github/workflows/ci.yml) on every push, against both SQLite and MySQL (badge above). Tests use a [fake Claude client](tests/Fakes/FakeClaudeClient.php), so they need no secrets. Small descriptive commits, [decision records](docs/adr/), a [CHANGELOG](CHANGELOG.md) and a [`CLAUDE.md`](CLAUDE.md) project brief. |
| **UX** | 10 | A custom brand system (Fraunces + Public Sans, self-hosted; forest/cream/amber tokens with every text pair tested for WCAG AA in [`ColorContrastTest`](tests/Unit/ColorContrastTest.php)), an employer-first landing page, a shared app shell (sidebar on desktop, bottom tabs on mobile), one-click demo flow, plain-English AI explanations, all businesses labeled fictional. A local-only `/styleguide` shows every token and component. **Planned:** the employer/HR dashboard (an empty shell is at `/employer`). |
| **Business impact** | 10 | Designed to keep perk spending with independent merchants. [Civic Economics](https://www.civiceconomics.com/) studies found that independent businesses recirculate roughly 48% of their revenue locally, versus under 14% for chains: roughly 3x more per dollar. BIDs get a tool to sell to employers they already work with. Every redemption is fraud-scored today, and **planned** Stripe payouts will only go out for cleared ones. Metrics like local spend captured are **planned**. |
| **Bonus: accessibility & ethical AI** | Bonus | **Accessibility:** form labels, `aria-describedby`/`aria-invalid` on errors, `aria-live` status messages, `aria-current` navigation, screen-reader text ([views](resources/views/)). **Ethical AI:** names never sent to the AI, every decision explained and shown, fails closed, high scores always held, a person approves or rejects every hold (name and time recorded), synthetic data only. See [TRANSPARENCY.md](TRANSPARENCY.md). **Planned:** recording the reviewer's written reason. |

## How I built it with Claude Code

I built Local Goods with [Claude Code](https://claude.com/claude-code):

- [`CLAUDE.md`](CLAUDE.md) gives every session the same brief: customers,
  scope, the exact independence rule, the stack and the hard rules (no
  secrets, synthetic data only, small commits, tests for business logic).
- Claude Code wrote the code and tests with me.
- It helped debug the live Elastic Beanstalk deploy.

I made the product decisions and reviewed and tested every change. Details are
in [TRANSPARENCY.md](TRANSPARENCY.md).

## Local setup

Requirements: PHP 8.3+ (CI uses 8.4), Composer, Node 20.19+ or 22.12+ (CI uses 22).

```sh
git clone https://github.com/finnbutler1/local-goods.git
cd local-goods
composer setup          # installs dependencies, creates .env, migrates, builds assets
php artisan db:seed     # loads the synthetic demo data
# set the DEMO_PASSWORD_* values in .env, then:
php artisan demo:seed-accounts   # one demo login per role, plus the public judge account (no password)
composer dev            # runs the app at http://localhost:8000
```

To get real AI scoring, set `ANTHROPIC_API_KEY` in `.env`. Without a key, every
redemption is held (fail closed).

Open `http://localhost:8000/styleguide` to see every color, type style and component
(local environment only; it returns 404 in production).

Run the tests (no API key needed):

```sh
php artisan test
```

## What's next

- Stripe Connect (test mode): employer funding and merchant payouts, gated by
  Redemption Fraud Detection ([ADR 0001](docs/adr/0001-stripe-connect-instead-of-self-built-escrow.md))
- Merchant Eligibility Matching, checked against manual review of the seeded
  merchants (target: at least 90% agreement)
- A written reason with each approve/reject decision
- An employer/HR dashboard

---

Finn Butler · CUA Busch School AI Vibe Coding Contest, Fall 2026
