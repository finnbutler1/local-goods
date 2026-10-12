# Local Goods

**An employee perk program designed to keep employer spending in the neighborhood: credits meant to be spent only at independent local merchants.**

> **Status:** working demo. Redemptions, AI fraud scoring and human approve/reject of held redemptions are live. Payouts and independence checks are **planned**: no money moves yet, and the demo merchants are seeded by hand.

**Live app:** http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com

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
   hand-seeded list. Automatic checks that a merchant is independent
   (Merchant Eligibility Matching) are planned.*
3. **AI checks every redemption.** *Live: suspicious redemptions are held and
   shown on the BID admin view, where a person approves or rejects them.
   Planned: Stripe Connect payouts that only go out for cleared redemptions.
   No money moves yet.*

**Try it:** [log in](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/login) with a demo account (available on
request; there is no public sign-up). As the employee, redeem a normal amount,
then click **Try a suspicious redemption**. As the BID admin, see the AI's
score, decision and explanation for each one, and approve or reject held
redemptions. The [AI eval results](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/eval) are public.
Judges and other reviewers get their own read-only login from a single-use
invite link, a guided tour, a "view as" switcher for each role, and a Feedback
button on every page ([ADR 0008](docs/adr/0008-read-only-reviewers-with-single-use-invite-links.md)).

## The two AI functions

| Function | Status | What it does |
|---|---|---|
| **Redemption Fraud Detection** | **Live** | Scores every redemption 0–100 for abuse such as credit-to-cash collusion. It returns `clear` or `hold` with a plain-English explanation. Only anonymous signals are sent (amount, balance, visit counts, category, hour), never names. |
| **Merchant Eligibility Matching** | **Planned** | Will decide whether a business in the BID is truly independent (not a franchise, not chain- or PE-owned, five or fewer locations), with written reasoning and a confidence score. Not built yet. The demo merchants are seeded by hand. |

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
| **Functionality** | 25 | **Live:** after [logging in](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/login), the employee wallet shows a balance, a redeem form and recent redemptions; the BID admin view shows every AI decision; Approve and Reject buttons for held redemptions ([`RedemptionReviewService`](app/Services/RedemptionReviewService.php)); a Reset demo button; logins with six roles, each with its own dashboard ([ADR 0007](docs/adr/0007-fortify-for-login-and-a-role-column.md)); read-only reviewer logins from single-use invite links, with a guided tour, "view as" for each role and in-app feedback scored against this rubric ([ADR 0008](docs/adr/0008-read-only-reviewers-with-single-use-invite-links.md)); an append-only credit ledger ([`CreditLedgerEntry`](app/Models/CreditLedgerEntry.php)). **Planned:** employer credit purchase, Stripe Connect payouts (test mode). |
| **Problem** | 20 | A specific buyer (employer HR via the BID), a specific place (Golden Triangle BID, DC) and a specific leak (perk dollars leaving the neighborhood). See [The problem](#the-problem) and [ADR 0002](docs/adr/0002-one-bid-and-one-employer-for-the-mvp.md). |
| **AI** | 20 | **Live:** Redemption Fraud Detection with structured JSON output, a [versioned prompt file](resources/prompts/redemption-fraud-detection.md), anonymous [signals](app/Services/Fraud/SignalCalculator.php), the score-70 override and fail-closed handling. **Measured:** [80% agreement on 10 unseen cases with the current prompt, and 0 suspicious cases missed](docs/evals/fraud-eval-results.md). Every disagreement is a false alarm that goes to human review, never a wrong approval. Small sample: one case is 10 points. 40 synthetic cases in all, each labeled by a person before the AI saw it; every run is kept; method in [TRANSPARENCY.md](TRANSPARENCY.md#how-we-measure-it). **Planned:** Merchant Eligibility Matching. |
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
php artisan demo:seed-accounts   # creates one demo login per role
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
- HTTPS for production

---

Finn Butler · CUA Busch School AI Vibe Coding Contest, Fall 2026
