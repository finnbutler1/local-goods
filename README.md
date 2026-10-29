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

## You work here. Spend here.

At 6 p.m., downtown's workers head home, and their spending goes with them.
Local Goods gives employees credits that only work at independent local
businesses, so the district your company calls home gets livelier.

> **Status:** working demo. Redemptions, AI fraud scoring and human approve/deny of held redemptions (with a reason, gating every Stripe transfer) are live. Employer, employee, merchant and BID dashboards cover the whole money loop in the ledger: buying credits, giving them out, spending, merchants owed and paid. Merchant Eligibility Matching is built as a triage tool: the AI screens real BID businesses, a BID admin decides, and approved businesses don't join the demo yet. Payments and payouts run on **Stripe Connect in test mode** (Checkout for employer purchases, Express accounts for the demo merchants, a Transfer per Stripe-funded cleared redemption); no real money moves, and every money page says whether it's Stripe test mode or simulated. Seeded history stays simulated, and without Stripe keys the whole demo falls back to simulated mode. The demo merchants are seeded by hand. Both AI functions, Redemption Fraud Detection and Merchant Eligibility Matching, assist human decisions and do not make them.

**Live app:** https://d4ghrpm72uczx.cloudfront.net

[![CI](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml/badge.svg)](https://github.com/finnbutler1/local-goods/actions/workflows/ci.yml)

**Stack:** Laravel 13 · PHP 8.3+ (CI on 8.4) · Tailwind CSS 4 (Vite 8) · SQLite (local) / Amazon RDS MySQL (production) · Claude API (`anthropic-ai/sdk`) · AWS Elastic Beanstalk · GitHub Actions · Stripe Connect, test mode (`stripe/stripe-php`)

---

## The problem

Thousands of people work in the Golden Triangle, a business district in
downtown Washington, DC, every weekday. Most follow the same loop: commute
in, sit at a desk, commute out. That isn't a failing of the people doing it;
it's how office work is set up. But it means their spending, and the life
they could bring to the streets around them, goes home with them.

## Why it matters

Everyone gains, and the district gains most.

- **The district.** More foot traffic and more spending at the independent
  shops, restaurants and services that give the Golden Triangle its
  character. A livelier district is good for everyone who works, lives or
  does business in it.
- **Employers.** A benefit staff actually use, because it shows up at lunch,
  not in a portal. And a practical way to improve the streets around their
  own office: a livelier neighborhood can make an office easier to recruit
  to, easier to stay at and more worth coming in to; a pilot would measure
  how much.
- **Merchants.** A large, steady workforce audience on their doorstep, and a
  reason for those customers to come back.
- **Employees.** A perk that makes the workday better (lunch, coffee,
  errands) and a reason to discover the neighborhood they already spend most
  of their week in.
- **The BID.** A measurable, privacy-respecting way to support the
  district's independent businesses: spending and participation are counted,
  employees are never named.

Money spent at independent local businesses tends to recirculate roughly 3x
more in the local economy than money spent at chains, an effect often called
the local multiplier. An estimate from an outside study, not measured by Local Goods: the Civic Economics / American Booksellers Association [Indie Impact study](https://www.publishersweekly.com/pw/by-topic/industry-news/bookselling/article/55547-news-from-aba-growing-shopping-local.html) found independent retailers and restaurants recirculated 52.3% of their revenue locally, against 15.8% for chains, about 3.3 times as much (data from 2011–2012, 134 businesses in 10 U.S. communities, none in DC). Real results here will be measured in a pilot.

## Business model

**Projection, not results.** Employers pay a monthly platform fee for each
participating employee, on top of the credits they buy. The BID, which
introduces us to employers, gets a share of that fee. Merchants may pay a
small fee per sale; it is zero in the product today. The credits themselves
pass through Stripe to merchants and are never Local Goods revenue, and credits
employees don't spend stay the employer's money.

The model is worked out month by month from stated assumptions, each with a
public source or labelled an assumption to check in customer discovery. On the
assumptions as of October 2026, the Golden Triangle alone is a pilot rather
than a business; the model depends on growing to more districts and on the
employer fee. Method, every assumption and what we still have to learn:
[docs/finance/MODEL.md](docs/finance/MODEL.md). Signed-in reviewers can explore
low and high cases on the Business model page.

<!-- projection:readme-table:start -->
| Base case | Year 1 | Year 2 | Year 3 | Year 4 |
|---|---|---|---|---|
| Golden Triangle only: revenue | $28k | $74k | $96k | $96k |
| Golden Triangle only: EBITDA | −$198k | −$129k | −$97k | −$97k |
| DC-wide: revenue | $31k | $145k | $405k | $748k |
| DC-wide: EBITDA | −$216k | −$310k | −$407k | −$173k |
| National rollout: revenue | $56k | $453k | $1.83M | $4.52M |
| National rollout: EBITDA | −$315k | −$809k | −$1.29M | −$1.04M |
<!-- projection:readme-table:end -->

## How it works

1. **The employer buys credits** for its employees through its local BID (the
   MVP uses the Golden Triangle BID in Washington, DC). *Today: the employer
   dashboard records a purchase as pending; HR pays through Stripe Checkout
   in test mode (test card 4000 0000 0000 0077), and only when Stripe's
   signed webhook confirms it can HR give the credits to employees, one at a
   time or in bulk. Stripe holds the money, not Local Goods
   ([docs/STRIPE.md](docs/STRIPE.md)).*
2. **Employees redeem them at merchants inside the BID**, using their phone as
   the card, two ways: **Order to go** (redeem in the app and show the
   confirmation) or **Pay in person** (get a one-time 6-character pay code
   that the cashier types into the merchant dashboard and confirms; nothing
   moves until they do, [ADR 0026](docs/adr/0026-pay-in-person-with-pay-codes.md)).
   Ordering from a menu shows the DC sales tax before paying (subtotal, tax,
   total), and the merchant sees the tax it collected for its own records;
   it's a display at DC's published rates, not tax advice
   ([ADR 0027](docs/adr/0027-dc-sales-tax-shown-at-redemption.md)). *Today: employees can only redeem at approved, live merchants
   inside the district: the demo's fictional merchants, plus any shop that
   joins through Apply to join (step 6). Merchant Eligibility Matching also
   assesses real businesses in the BID for the BID admin to review.*
3. **AI checks every redemption.** *Live: suspicious redemptions are held and
   shown on the BID admin view, where a person approves or denies them, with a reason.
   Merchants are owed a redemption only once it clears; a cleared redemption
   funded by Stripe-paid credits becomes a Stripe test-mode transfer to the
   merchant's connected account. Held or denied ones never transfer: one gate,
   `TransferGate`, decides every transfer ([ADR 0016](docs/adr/0016-fraud-detection-gates-stripe-transfers.md)).*
4. **Mistakes get fixed, on the record.** Employees, merchants and HR can ask
   for a correction; the platform admin approves or rejects it with a reason
   they see. A refund reverses that part of the merchant's Stripe transfer,
   unspent credits go back to the employer's card as Stripe refunds, and a
   missed payout is sent again, each as a new append-only ledger line
   confirmed by Stripe's webhook, and reconciliation still matches
   ([ADR 0028](docs/adr/0028-refunds-and-corrections.md)).
5. **How Local Goods would earn money.** A per-transaction merchant fee is
   a configurable setting that charges nothing by default. When it is set,
   each sale keeps the fee it was made with, the merchant's Stripe transfer
   is the sale less the fee, a refund gives back its share of the fee, and
   reconciliation checks it all. Only the platform admin can change it, and
   every change is logged
   ([ADR 0029](docs/adr/0029-merchant-fee-ready-but-at-zero.md)).
6. **Shops join, and the district boundary is enforced.** An independent
   shop applies at [/apply](https://d4ghrpm72uczx.cloudfront.net/apply)
   (no account) and follows a private status link. Local Goods checks the
   address against the BID boundary DC publishes and asks Merchant
   Eligibility Matching for a suggestion; a BID admin approves or declines
   with a reason. The shop then sets up Stripe payouts and goes live.
   Credits can only be spent at approved, live merchants inside the
   district, and a district can have only one active exclusive BID
   partnership. See the [district map](https://d4ghrpm72uczx.cloudfront.net/district)
   and [who does what](https://d4ghrpm72uczx.cloudfront.net/who-does-what)
   ([ADR 0031](docs/adr/0031-districts-partnerships-merchant-joining.md)).

**Try it:** [log in](https://d4ghrpm72uczx.cloudfront.net/login) with a demo account (available on
request; there is no public sign-up). As the employee, redeem a normal amount,
then click **Try a suspicious redemption**. To see tax at checkout, order
from a menu in the wallet. On **Pay in person**, get a pay
code, then log in as the merchant and enter it under **Take a payment**. As the BID admin, see the AI's
score, decision and explanation for each one, approve or deny held
redemptions, and press **Run a collusion example** to watch the real AI
check a synthetic credit-to-cash redemption. Open **Corrections** to see how a
refund or a missed payout is fixed. The [AI eval results](https://d4ghrpm72uczx.cloudfront.net/eval) are public.
Judges and other reviewers sign in with a magic link (no account, no password;
see **For judges** above) to a read-only view with a guided tour, a "view as"
switcher for each role, and a Feedback button on every page
([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)).

## The two AI functions

| Function | Status | What it does |
|---|---|---|
| **Redemption Fraud Detection** | **Live** | Scores every redemption 0–100 for abuse such as credit-to-cash collusion. It returns `clear` or `hold` with a plain-English explanation. Only anonymous signals are sent (amount, balance, visit counts, category, hour), never names. |
| **Merchant Eligibility Matching** | **Built (triage)** | *The AI is a triage tool: it screens out obvious chains and franchises, routes anything it can't verify to a person, and never approves a merchant on its own.* Suggests whether a real business in the BID is independent (not a franchise, not chain- or PE-owned, five or fewer locations), with a classification, written reasoning and a 0–1 confidence. It sees only the business's public license facts and has no web access. A BID admin approves, rejects or asks for research. A failed or invalid answer becomes "undetermined" for a person, never a guess. Every business is labeled "Candidate — not a Local Goods participant." An AI "eligible" is shown as "AI recommends eligible — confirm before approving" and is never approved automatically. Measured against 60 hand labels at [`/eval/merchants`](https://d4ghrpm72uczx.cloudfront.net/eval/merchants) ([ADR 0013](docs/adr/0013-merchant-eligibility-matching.md)). |

**Data:** the merchant candidates come from public DC business license records. Only business information is kept (name, address, category, license type): no owner names, contact details or personal data. The AI's outputs are tested so they never name individuals ([details](TRANSPARENCY.md#merchant-data-public-business-information-only)).

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
or denies it (suspected abuse: the credits go back to the employer; no purchase
happened: back to the employee). Each decision needs a reason and is recorded with
who decided, when and what the AI said, and a redemption can only be
decided once. A held redemption never reaches Stripe; only a cleared one funded by Stripe-paid credits is transferred (test mode). See
[`RedemptionFraudDetector.php`](app/Services/Fraud/RedemptionFraudDetector.php),
its [tests](tests/Feature/RedemptionFraudDetectorTest.php) and
[ADR 0003](docs/adr/0003-fail-closed-fraud-detection.md).

## How it meets the judging criteria

Only what exists today is claimed. Everything else is marked **planned**.

| Category | Points | Evidence |
|---|---|---|
| **Functionality** | 25 | **Live:** after [logging in](https://d4ghrpm72uczx.cloudfront.net/login), the employee wallet shows a balance, a redeem form and recent redemptions; the BID admin view shows every AI decision; Approve and Reject buttons for held redemptions ([`RedemptionReviewService`](app/Services/RedemptionReviewService.php)); a Reset demo button; logins with six roles, each with its own dashboard ([ADR 0007](docs/adr/0007-fortify-for-login-and-a-role-column.md)); read-only reviewer access from magic links (no passwords, valid until judging ends, revocable), with a guided tour, "view as" for each role and in-app feedback scored against this rubric ([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)); an append-only credit ledger ([`CreditLedgerEntry`](app/Models/CreditLedgerEntry.php)); a BID review queue for Merchant Eligibility Matching with Approve, Reject and Needs research, logged append-only with who and when ([`MerchantReviewDecision`](app/Models/MerchantReviewDecision.php)). **Money loop ([ADR 0014](docs/adr/0014-employer-program-pending-purchases-and-initials.md)):** an employer/HR dashboard with program setup (monthly budget, allowance, optional new-hire, birthday and recognition occasions), buying credits (pending until Stripe confirms payment by a signed webhook, which alone writes the ledger entry) and giving them to one person or many, all or nothing, within the budget ([`CreditLedger`](app/Services/CreditLedger.php)); a wallet with merchant search and category filters, a confirmation screen and full ledger history; a merchant dashboard (owed split into "via Stripe test mode" and "simulated", held ones "not owed yet", Stripe onboarding); a BID participation overview and merchant roster with onboarding status; a BID impact page read from the ledger that rolls up the HR numbers with the same definitions ([ADR 0017](docs/adr/0017-bid-impact-from-the-ledger.md)). **Stripe Connect, test mode ([ADR 0015](docs/adr/0015-stripe-connect-test-mode-separate-charges-and-transfers.md), [docs/STRIPE.md](docs/STRIPE.md)):** Checkout with `transfer_group`, Express onboarding, a Transfer per Stripe-funded cleared redemption with `source_transaction`, a verified idempotent webhook, and a platform-admin reconciliation page that explains every mismatch in plain English. Feature tests cover each flow with Stripe faked, and the cent-exact ledger math. **Planned:** turning an approved candidate into a participant. |
| **Problem** | 20 | A specific buyer (employer HR via the BID), a specific place (Golden Triangle BID, DC) and a specific leak (perk dollars leaving the neighborhood). See [The problem](#the-problem), [Why it matters](#why-it-matters) and [ADR 0002](docs/adr/0002-one-bid-and-one-employer-for-the-mvp.md). |
| **AI** | 20 | **Live:** Redemption Fraud Detection with structured JSON output, a [versioned prompt file](resources/prompts/redemption-fraud-detection.md), anonymous [signals](app/Services/Fraud/SignalCalculator.php), the score-70 override and fail-closed handling. **Measured:** [caught 17 of 26 suspicious; 17 of 19 holds were suspicious](docs/evals/fraud-eval-results.md), on 50 unseen cases (26 labeled suspicious) that were labeled by hand and locked with a SHA-256 per row before the AI saw them. 95% confidence intervals: catch rate 46–81%, hold precision 69–97%. Misses are the weak spot: the 9 missed cases mostly had one strong signal at normal hours, and the results break them down by pattern. Earlier, smaller runs are kept below the headline. 90 synthetic cases in all; method in [TRANSPARENCY.md](TRANSPARENCY.md#how-we-measure-it). **Built:** Merchant Eligibility Matching, with a [versioned prompt](resources/prompts/merchant-eligibility.md) that holds the independence rule word for word, four public facts as input, validated JSON, a fail-closed fallback to "undetermined", and a plain statement that the AI has no web access. **Eval:** [`merchant:eval`](app/Console/Commands/MerchantEval.php) runs against 60 hand labels locked before the AI saw them (40 dev, 20 holdout, scored separately; holdout once per prompt version). **Measured on the 20-case holdout, run once with the frozen prompt `458e8c2d`:** it wrongly called 0 of 17 chains and franchises eligible (95% CI 0–18%), called 15 of 17 not eligible, and routed 14 of 20 cases to a person. Agreement (17 of 20) only equals the always-"not eligible" baseline, and it found 0 of 3 independents. **Honest limit:** without web access it recognized only 2 of 11 independents on dev; the rest came back undetermined for a person. So it is a triage tool, not a decider: *The AI is a triage tool: it screens out obvious chains and franchises, routes anything it can't verify to a person, and never approves a merchant on its own.* Web search is future work. [Results](docs/evals/merchant-eval-results.md). |
| **Code quality / GitHub** | 15 | A [test that sends every write route as a judge](tests/Feature/JudgeReadOnlyTest.php) and checks nothing changes. Automated tests run by [GitHub Actions CI](.github/workflows/ci.yml) on every push, against both SQLite and MySQL (badge above). Tests use a [fake Claude client](tests/Fakes/FakeClaudeClient.php) and a [fake Stripe gateway](tests/Fakes/FakeStripeGateway.php) (webhooks are signed exactly as Stripe signs them), so they need no secrets and never touch the network. Small descriptive commits, [decision records](docs/adr/), a [CHANGELOG](CHANGELOG.md) and a [`CLAUDE.md`](CLAUDE.md) project brief. |
| **UX** | 10 | A custom brand system, Transit lines ([ADR 0025](docs/adr/0025-transit-lines-brand.md): Overpass + Atkinson Hyperlegible Next, self-hosted; light and dark tokens with every text pair tested for WCAG AA in both modes in [`ColorContrastTest`](tests/Unit/ColorContrastTest.php)), an employer-first landing page, a shared app shell (sidebar on desktop, bottom tabs on mobile), one-click demo flow, plain-English AI explanations, all businesses labeled fictional. A local-only `/styleguide` shows every token and component. The employer/HR dashboard frames results as "your team got out into the neighborhood", and every number has a "How we count this" note; the eight-week trend has a table view. Every page has an empty state, a "working…" state on slow buttons and plain-English error pages, and fits a 320px phone screen ([accessibility and UX check](docs/accessibility.md)). |
| **Business impact** | 10 | Designed to keep perk spending with independent merchants. An estimate from an outside study, not measured by Local Goods: the Civic Economics / American Booksellers Association [Indie Impact study](https://www.publishersweekly.com/pw/by-topic/industry-news/bookselling/article/55547-news-from-aba-growing-shopping-local.html) found independent retailers and restaurants recirculated 52.3% of their revenue locally, against 15.8% for chains (data from 2011–2012, 134 businesses in 10 U.S. communities, none in DC). BIDs get a tool to sell to employers they already work with. Every redemption is fraud-scored today, merchants are owed only cleared ones, and cleared Stripe-funded ones are paid out through Stripe Connect (test mode), so custody stays with a licensed processor instead of us. **Live metrics:** the employer sees the share of staff who redeemed this month, visits out of the office, local businesses discovered (including first-time visits), dollars spent locally and top categories; the BID sees employees active, merchants reached and dollars spent at independent merchants, with the study's rate shown separately as a labelled outside estimate. Live (test-mode) Stripe payouts; real-money payouts would need only live keys and Stripe's account review. |
| **Bonus: accessibility & ethical AI** | Bonus | **Accessibility:** every page checked with keyboard only, at 320px and 375px, and with axe (WCAG 2.2 AA rules); errors tied to their fields, focus moved to the result after each action, tables reachable by keyboard, reduced motion respected. [`AccessibilityTest`](tests/Feature/AccessibilityTest.php) checks labels, alt text, names and error wiring on every page for every role. What was and wasn't tested (not yet with a screen reader) is in [docs/accessibility.md](docs/accessibility.md). **Ethical AI:** names never sent to the AI, every decision explained and shown, fails closed, high scores always held, a person approves or denies every hold (name, time and reason recorded) and no money moves until they do, the merchant AI only recommends and never approves a business on its own (enforced in the model and tested), real business names kept off public pages, synthetic demo data only, and employees shown by initials only outside their own wallet (checked on every page by [`EmployeeNamePrivacyTest`](tests/Feature/EmployeeNamePrivacyTest.php)). See [TRANSPARENCY.md](TRANSPARENCY.md). |

## Which error we chose to minimize

Which error we chose to minimize, and why. Local Goods is tuned to avoid false holds, even though that lets some fraud through. On our locked held-out test it caught 17 of the 26 suspicious redemptions, and 17 of the 19 redemptions it held were truly suspicious. We accept that trade on purpose. A false hold falls on the person least able to absorb it: a salon owner or café waiting on money she has already earned, and a merchant who gets stuck once may quietly stop accepting the credits. That would empty the program from the inside. A missed fraud, by contrast, is capped: an employee can never spend more than the credits their employer has put in their wallet, their balance never goes below zero, and a merchant is paid only for credits an employee actually spent. So the most one missed fraud can move is the credits loaded onto that one employee ([how we test this](tests/Feature/MissedFraudLossCapTest.php)). It is paid by the employer, not the merchant, and repeat patterns get easier to spot over time. So we would rather miss a small, bounded loss than delay an honest merchant’s pay. If fraud turns out to be costlier than we expect, the threshold can be tightened, and we will measure any change on a fresh held-out set, never on the cases we have already seen.

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

- Merchant Eligibility Matching, checked against manual review of the seeded
  merchants (target: at least 90% agreement)
- A check across redemptions for collusion rings (new signals, so a new
  prompt version and a fresh holdout)
- An employer/HR dashboard
- Interchange revenue would need Local Goods to issue cards (Stripe
  Issuing). The MVP has no card program, so this is not built.

---

Finn Butler · CUA Busch School AI Vibe Coding Contest, Fall 2026
