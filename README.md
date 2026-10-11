# Local Goods

**An employee perk program that keeps employer spending in the neighborhood: credits that can only be spent at independent local merchants.**

**Live app:** http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com

[![CI](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml/badge.svg)](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml)

**Stack:** Laravel 13 · PHP 8.4 · Tailwind CSS (Vite) · SQLite · Claude API (`anthropic-ai/sdk`) · AWS Elastic Beanstalk · GitHub Actions · Stripe Connect (planned)

---

## The problem

Employers in downtown business districts spend heavily on employee perks, but
very little of that money stays in the surrounding neighborhood. It goes to
national chains and online platforms. Yet **independent merchants return
roughly 3x more per dollar to the local economy** than national chains. Business
Improvement Districts (BIDs) want to support their independent merchants, and
employers want a perk their staff will actually use.

## How it works

1. **The employer buys credits** for its employees through its local BID (the
   MVP uses the Golden Triangle BID in Washington, DC).
2. **Employees spend them only at independent merchants inside the BID.** The
   employee's phone is the card, and they redeem in the app.
3. **AI checks every redemption before the merchant is paid.** Suspicious ones
   are held for a person to review. Payouts will go through Stripe Connect
   (planned).

**Try it:** open the [employee wallet](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/demo/employee),
redeem a normal amount, then click **Try a suspicious redemption**. After that,
open the [BID admin view](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/demo/bid-admin)
to see the AI's score, decision and explanation for each one.

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
anyway. Held credits stay reserved, so nobody loses money while a person
reviews. See
[`RedemptionFraudDetector.php`](app/Services/Fraud/RedemptionFraudDetector.php),
its [tests](tests/Feature/RedemptionFraudDetectorTest.php) and
[ADR 0003](docs/adr/0003-fail-closed-fraud-detection.md).

## How it meets the judging criteria

Only what exists today is claimed. Everything else is marked **planned**.

| Category | Points | Evidence |
|---|---|---|
| **Functionality** | 25 | **Live:** the [employee wallet](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/demo/employee) shows a balance, a redeem form and recent redemptions; the [BID admin view](http://LocalGoods-env.eba-fih22s6v.us-east-1.elasticbeanstalk.com/demo/bid-admin) shows every AI decision; a Reset demo button; an append-only credit ledger ([`CreditLedgerEntry`](app/Models/CreditLedgerEntry.php)). **Planned:** employer credit purchase, Stripe Connect payouts (test mode), approve/reject buttons for held redemptions. |
| **Problem** | 20 | A specific buyer (employer HR via the BID), a specific place (Golden Triangle BID, DC) and a specific leak (perk dollars leaving the neighborhood). See [The problem](#the-problem) and [ADR 0002](docs/adr/0002-one-bid-and-one-employer-for-the-mvp.md). |
| **AI** | 20 | **Live:** Redemption Fraud Detection with structured JSON output, a [versioned prompt file](resources/prompts/redemption-fraud-detection.md), anonymous [signals](app/Services/Fraud/SignalCalculator.php), the score-70 override and fail-closed handling. **Planned:** Merchant Eligibility Matching. |
| **Code quality / GitHub** | 15 | 36 automated tests run by [GitHub Actions CI](.github/workflows/ci.yml) on every push (badge above). Tests use a [fake Claude client](tests/Fakes/FakeClaudeClient.php), so they need no secrets. Small descriptive commits, [decision records](docs/adr/), a [CHANGELOG](CHANGELOG.md) and a [`CLAUDE.md`](CLAUDE.md) project brief. |
| **UX** | 10 | One-click demo flow (normal, then suspicious redemption), plain-English AI explanations, status badges, navigation between demo views, all businesses labeled fictional. **Planned:** the employer/HR dashboard. |
| **Business impact** | 10 | Money stays with independent merchants (roughly 3x local return per dollar). BIDs get a tool to sell to employers they already work with. Fraud is checked before payouts. Metrics like local spend captured are **planned**. |
| **Bonus: accessibility & ethical AI** | Bonus | **Accessibility:** form labels, `aria-describedby`/`aria-invalid` on errors, `aria-live` status messages, `aria-current` navigation, screen-reader text ([views](resources/views/)). **Ethical AI:** names never sent to the AI, every decision explained and shown, fails closed, high scores always held, synthetic data only. See [TRANSPARENCY.md](TRANSPARENCY.md). **Planned:** a human approve/reject action that records the reviewer's reason. |

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

Requirements: PHP 8.3+ (CI uses 8.4), Composer, Node 22+.

```sh
git clone https://github.com/finnbutler1/local-goods.git
cd local-goods
composer setup          # installs dependencies, creates .env, migrates, builds assets
php artisan db:seed     # loads the synthetic demo data
composer dev            # runs the app at http://localhost:8000
```

To get real AI scoring, set `ANTHROPIC_API_KEY` in `.env`. Without a key, every
redemption is held (fail closed).

Run the tests (no API key needed):

```sh
php artisan test
```

## What's next

- Stripe Connect (test mode): employer funding and merchant payouts, gated by
  Redemption Fraud Detection ([ADR 0001](docs/adr/0001-stripe-connect-instead-of-self-built-escrow.md))
- Merchant Eligibility Matching, checked against manual review of the seeded
  merchants (target: at least 90% agreement)
- Approve/reject buttons for held redemptions, recording the reviewer's name
  and reason
- An employer/HR dashboard
- Amazon RDS and HTTPS for production ([ADR 0005](docs/adr/0005-sqlite-now-rds-for-production-next.md))

---

Finn Butler · CUA Busch School AI Vibe Coding Contest, Fall 2026
