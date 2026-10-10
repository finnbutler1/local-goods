# Local Goods — project brief for Claude

Read this first in every session. It is the source of truth for what we are
building, for whom, and the rules for working in this repo.

## Current milestone

**One working slice (employee wallet + AI fraud check) live on Elastic Beanstalk by Thu Oct 15, 2026.**
Built: landing page, `/demo/employee` wallet, `/demo/bid-admin` view, Redemption
Fraud Detection. Remaining: deploy and verify on Beanstalk.
Everything else is out of scope until then. Update this section whenever the
milestone changes.

## What Local Goods is

- Employers inside a Business Improvement District (BID) buy credits.
- Their employees can spend those credits **only at independent merchants in
  that BID**.
- **Stripe Connect (test mode)** holds and moves the money.
- **Local Goods** keeps the entitlement ledger (who is owed what) and enforces
  the rules.

**MVP scope: ONE BID (Golden Triangle, Washington DC) and ONE employer.** Do not
build for multiple BIDs. No tenant switching, no BID selector, no
multi-BID data model.

## Customers, in priority order

1. **Major employers in the BID** (banks, law firms, agencies), through their
   HR departments. They are the buyer and fund the program. Their pitch: a
   perk employees actually use, and a reason for staff to get out of the
   office into the neighborhood.
2. **The Golden Triangle BID.** The channel that introduces us to employers.
3. **Merchants** (restaurants, retail, salons, gyms). They gain incremental
   sales and have almost no barrier to joining.

**The employer/HR experience gets the most polish.**

## Redemption is closed-loop

- The employee's phone is the card. Redemptions happen in the app.
- Merchants are paid out through Stripe Connect.
- **No Visa/Mastercard card program in the MVP.** Do not add card issuing.

## The two named AI functions

1. **Merchant Eligibility Matching.** Decides whether a business in the BID is
   independent and eligible under the rule below, with written reasoning and a
   confidence score.
2. **Redemption Fraud Detection.** Scores every redemption for abuse such as
   credit-to-cash collusion. **It gates real Stripe transfers:** a flagged
   redemption is held and no money moves until it is cleared. It fails closed:
   any API error or invalid reply means hold, never clear. Only anonymous
   signals (no names) are sent to the AI.

Use these exact names in code, UI, and docs.

## Independence rule (verbatim — do not paraphrase in code or prompts)

> A merchant is independent and eligible only if (1) it is owned and operated
> by individuals or a company headquartered in the DC metro area with no
> outside parent, private-equity, or public-company control; (2) it and any
> sister brands have five or fewer locations; (3) it is not a franchisee. If
> ownership can't be established from public sources it is labeled
> undetermined.

## Stack

- **Laravel** — check `composer.json` for the version (currently `^13.17`, PHP `^8.3`).
- **Tailwind via Vite** (`npm run dev` / `npm run build`).
- **SQLite** at `storage/database.sqlite` (gitignored), locally and on Beanstalk.
  A postdeploy hook (`.platform/hooks/postdeploy/01_reset_database.sh`) runs
  `migrate:fresh --seed --force` on every deploy, so demo data resets each deploy.
- **Claude API** via the official `anthropic-ai/sdk` package, wrapped in
  `App\Services\Ai\ClaudeClient`. Key from `ANTHROPIC_API_KEY`; model from
  `ANTHROPIC_MODEL` (default `claude-opus-5-5`). Prompts live in `resources/prompts/`.
  Tests use `Tests\Fakes\FakeClaudeClient` and never call the real API.
- **AWS Elastic Beanstalk**, PHP platform, single instance. Config lives in
  `.ebextensions/`. EB runs `composer install` on deploy, so `vendor/` is not
  shipped.
- **Deploying:** run `scripts/build-deploy-zip.sh`, then upload the resulting
  `deploy.zip` in the Elastic Beanstalk console. **There is no auto-deploy
  from GitHub.**
- Live URL is in `README.md`.

## Contest

CUA Busch School AI Vibe Coding Contest, judged the **week of Nov 23, 2026**.

| Criterion | Points |
|---|---|
| Functionality | 25 |
| Problem | 20 |
| AI | 20 |
| Code quality / GitHub | 15 |
| UX | 10 |
| Business impact | 10 |
| Bonus | Accessibility and ethical AI |

Keep accessibility (labels, contrast, keyboard use) and ethical AI (explain
AI decisions, let a human override, show confidence) in mind on every screen.

## Hard rules

- **Never commit secrets:** `.env`, API keys, `.pem`/`.key` files, or
  `deploy.zip`. Check `git status` before committing.
- **All demo people and transactions are synthetic.** Never use real
  employee names, real card data, or real customer records.
- **Small commits** with descriptive messages after every working change.
- **Write or update tests for business logic** (ledger, eligibility, fraud
  rules, payouts). Run `php artisan test` before committing.
- **Explain changes in plain English.** The owner is a beginner: say what
  changed, why, and how to check it worked, without jargon.
- **Ask before deleting files or adding paid services.**
