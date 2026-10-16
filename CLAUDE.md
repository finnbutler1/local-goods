# Local Goods — project brief for Claude

Read this first in every session. It is the source of truth for what we are
building, for whom, and the rules for working in this repo.

## Current milestone

**Stripe Connect, test mode — built (ADR 0015, `docs/STRIPE.md`).** Separate
charges and transfers. Next milestone: Prompt 8 (fraud gate on transfers).

- Mode: `App\Support\StripeMode::active()` only with `STRIPE_KEY`,
  `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` all set and test keys; otherwise
  simulated (CI, tests, missing/live keys). Never print, log or commit keys.
  All Stripe calls go through `App\Services\Payments\StripeGateway`; tests
  use `Tests\Concerns\UsesFakeStripe` / `Tests\Fakes\FakeStripeGateway`.
- Purchases: pending until a signed `payment_intent.succeeded`
  (`CreditLedger::markPurchasePaidByStripe`, status `paid`, once). Simulated
  mode keeps `payPurchase` (status `simulated`). Never label one as the other.
- Payouts: two channels that never mix. `StripeFunding::claim` (inside the
  clearing transaction) makes a Stripe payout row only for a cleared
  redemption in Stripe mode covered by a Stripe-paid purchase (oldest first);
  everything else, including all seeded history, stays simulated and is
  never sent to Stripe. `MerchantPayouts::send` runs after commit, only once
  the merchant is onboarded. `TransferGate::allows` is where Prompt 8's fraud
  gate goes. Ledger payout entries only for `sent`/`simulated` rows.
- Webhook `/stripe/webhook` in `routes/stripe.php`, outside the web group (no
  session/CSRF/user); signature is the only auth; `stripe_events` dedupes
  (not a demo table). The judge sweep skips only `stripe.webhook`.
- Onboarding: Express dashboard on **Accounts v2** (Stripe refuses new v1
  Express accounts here; v2 needs `contact_email`), demo merchants only; GET
  return/refresh pages never create anything; Reset demo keeps
  `stripe_account_id`. `StripeOnboarding::apply` reads v2 accounts and v1
  `account.updated` snapshots. Transfers stay on v1.
- Reconciliation: `/admin/reconciliation`, Stripe-funded activity only.
- Every Stripe call is wrapped in `NoticeSafeStripeGateway`: stripe-php
  raises Stripe-Notice headers as PHP warnings (a 500 otherwise), and it
  ignores `STRIPE_SUPPRESS_NOTICES` when an AI agent env var (CLAUDECODE) is
  set. Don't bind the SDK gateway unwrapped.

**Money loop screens — built (ADR 0014).** Employer/HR dashboard
(`/employer`, `Employer\EmployerDashboardController`), extended wallet,
merchant dashboard (`/merchant`) and BID participation
(`/bid-admin/participation`).

- Purchases: `CreditLedger::requestPurchase` writes only a `pending`
  `credit_purchases` row, **no ledger entry**; only a paid purchase
  (`simulated` or Stripe `paid`) is in the ledger, once.
  Pending credits can't be allocated or spent. Seeded purchases are
  `simulated` (paid). Ledger invariant counts paid purchases only.
- Allocation from the dashboard: `CreditLedger::allocateMany` (all or
  nothing, checks paid balance and `EmployerProgram::remainingBudgetCents`).
  Occasions: `App\Enums\Occasion` (monthly plus optional welcome, birthday,
  recognition; amounts on `employers`, empty = off).
- Metrics: `App\Services\EmployerMetrics`, cleared redemptions only, calendar
  month in DC time, 8 Monday weeks. Each number on the page says how it's
  counted; keep the code and that text in step.
- Privacy: outside an employee's own wallet show `Employee::initials()` or
  `publicLabel()` ("H.M. · #014"), never `display_name`.
  `EmployeeNamePrivacyTest` sweeps every page as every role.
- Merchant login: `merchants.user_id`, linked to Farragut Grind
  (`demo.accounts.merchant.business`) by `DemoAccountSeeder::linkDemoMerchant`.
- Local trial runs: `php artisan serve` doesn't pass `DB_DATABASE` to its
  worker, so it uses `.env`'s database. Use `php -S` from `public/` with a
  scratchpad SQLite instead.

**Merchant Eligibility Matching — built and measured (ADR 0013).** The AI is
a triage tool and only recommends; a person makes every decision
(`MerchantReviewDecision` refuses anyone but a BID or platform admin). Prompt
frozen at `458e8c2d` (`eligibility.frozen_prompt_version`, CI checks it); the
holdout has been run once with it (0 of 17 chains called eligible, 0 of 3
independents found). A prompt change needs Finn's say-so and a fresh unseen
holdout. Web search is future work.

- Matcher: `App\Services\Eligibility\MerchantEligibilityMatcher`, prompt
  `resources/prompts/merchant-eligibility.md` (rule verbatim; version = first
  8 hex of its SHA-256). Input is only name, address, category and license
  business activity (`data/sources/merchant_candidate_activity.csv`, from
  `eligibility:fetch-activity`); never read the label file in it. Fails
  closed to undetermined + human review; undetermined or confidence < 0.7
  always needs review. The AI has no web access: keep
  `KNOWLEDGE_NOTICE` next to any reasoning shown.
- Eval: `php artisan merchant:eval [--split=dev|holdout] [--dry-run]`.
  Refuses if the label lock doesn't match. Change the prompt using dev only;
  holdout runs once per prompt version (refused after that). Dev and holdout
  never pooled. Always tell Finn the `--dry-run` call count before a real run.
  Writes `docs/evals/merchant-*` and `data/merchant_assessments.json`
  (AI output only, read by the queue; production never calls the API for it).
- Queue: `/bid-admin/merchants`, append-only `merchant_review_decisions`
  (not in DEMO_TABLES). Approving never creates a merchant or transaction.
- Real names: only for admins and named reviewers
  (`User::maySeeCandidateNames`); logged-out visitors and the Public judge
  account see totals only on `/eval/merchants` and the queue.

Done: Prompts 0, S, R, DB, E, 1, 2 and 3, magic links (ADR 0010), blind
adjudication (ADR 0011), the three-account ledger (ADR 0012), Merchant
Eligibility Matching (ADR 0013), the money loop screens (ADR 0014) and
Stripe Connect test mode (ADR 0015).
- Ledger (ADR 0012): one append-only ledger, each entry on exactly one
  account (employee, employer or merchant). `App\Services\CreditLedger` does
  purchases, allocations, merchant earnings and payouts. A merchant earns a
  redemption only once it clears, never while held. Invariant: purchases −
  payouts (sent or simulated) − amounts under review = sum of all entries.
  Seeded payouts are `simulated`.
- Seed: Meridian (120 staff, 40 enrolled). Jordan Rivera must stay the first
  employee with the same 12 redemptions (fraud eval signal test and wallet
  demo depend on it). `DemoHistorySeeder` adds 39 employees, 8 weeks of
  deterministic history at the fictional merchants and two planted collusion
  patterns (documented there). Reset demo clears `DemoResetter::DEMO_TABLES`
  only.
- Real merchant candidates: `data/merchant_candidates.csv` from
  `php artisan eligibility:build-candidates` (DC Open Data licenses + DC GIS
  boundary; kept only if the spatial query, the license's BID field and our
  point-in-polygon check agree). Real candidates must never appear in
  synthetic data or seeds, and any page showing one must label it
  "Candidate — not a Local Goods participant." (`config('eligibility.candidate_label')`).
  Never store owner or agent names.
- Labels: `data/merchant_labels.csv`, 60 rows (40 dev, 20 holdout). Labels
  are Finn's alone: never fill in, suggest or hint at `my_label`,
  `fail_reason`, `evidence_url` or `my_note`. `fail_reason` is one of
  local_chain, franchise, national_chain, outside_control (part 1 of the
  rule). Checked by `App\Services\Eligibility\MerchantLabels`.
- Label lock: `data/merchant_labels_lock.json` (written by `merchant:lock`)
  holds a SHA-256 of every labeled row and labeling commit 30b610e. Never
  edit a locked row (CI fails via `MerchantLabelLockTest`); a deliberate
  change needs Finn's say-so and `merchant:lock --force`.
- Blind adjudication (ADR 0011): `/admin/adjudication` builds a set from the
  latest eval run (every disagreement plus as many random agreed cases as
  blind controls; locked once published, new version to change). Only judges
  with reviewer type BID or contest judge vote (`adjudicate` gate,
  `User::canAdjudicate`) at `/adjudicate`; `adjudication.start/vote/submit`
  are on the judge write allowlist. Results (`AdjudicationResults`) on the
  admin page and `/eval#adjudication`; they never change labels or the
  headline. Judges who can't vote get an "Independent check of the AI" card
  on `/judge`; the voting end date it shows is `reviewers.adjudication_ends_at`
  (display only: voting ends when the admin presses Close voting).
- Magic links (ADR 0010, supersedes 0008's invites): judges never create
  accounts or set passwords. `App\Services\ReviewerLinks` makes a reusable
  link `/judge-access#<id>.<token>` (token after `#`, so never in logs; the
  page posts it). Only its hash is stored, compared with `hash_equals`; valid
  until `JUDGING_ENDS_AT` (`config/reviewers.php`, default 2026-11-30 23:59
  America/New_York). Only ever opens a judge account; judges can't log in
  with a password. `EndReviewerSessions` ends a judge session on its next
  request after revoke, New link (session fingerprint mismatch) or the end
  date. In tests, `actingAs($judge)` gives them a link-backed session
  (`tests/TestCase.php`); use `be()` for one without. One shared "Public
  judge access" reviewer (`ReviewerType::Public`, made by
  `demo:seed-accounts`, no password) for the README link; tighter feedback
  limits (`throttle:feedback`). After a new public link, update README's
  "For judges" link.
- 3 (reviewers and feedback): judges are read-only: `BlockJudgeWrites`
  (allowlist: judge-access.store, logout, feedback.store, feedback.update)
  plus gates and policies; `JudgeReadOnlyTest` sweeps every write route, so
  **any new write route must pass it**. Judges can GET the BID admin,
  employer, employee and merchant pages ("view as", `App\Support\ViewAs`)
  but never `/admin/*`, which holds real reviewers' names and emails.
  Feedback button on every signed-in page (`feedback` table), inbox at
  `/admin/feedback`, changelog for reviewers at `/admin/changelog`, "What's
  new" on `/judge`. ADR 0008.
- HTTPS (ADR 0009): CloudFront (`cloudfront.net` address) in front of
  Beanstalk, talking to it over http without the Host header. In production
  `UseAppUrl` pins URLs and the request itself to APP_URL (magic links use
  it too); `TrustCloudFront` believes X-Forwarded-For only with the
  `X-Origin-Verify` secret (`CLOUDFRONT_ORIGIN_SECRET`); session cookie is
  Secure. `BehindCloudFrontTest` covers it. Never trust proxies with `*`.
- 2 (logins and roles): Laravel Fortify login (no registration, 5 tries a
  minute, "contact the admin" instead of reset emails while there is no mail
  service). Six roles in `users.role` (`App\Enums\Role`), each with its own
  dashboard and menu; `role:` middleware and `RedemptionPolicy` (only
  bid_admin and platform_admin approve or reject). Wallet at `/employee`,
  BID admin at `/bid-admin`, both behind login; `/eval` stays public; old
  `/demo` URLs redirect to `/login`. Demo logins come from
  `php artisan demo:seed-accounts` with `DEMO_PASSWORD_*` settings (local
  `.env` and Beanstalk environment properties; never commit or print them).
  The deploy hook runs it after migrating.
- S (October working slice): employee wallet and BID admin view, Claude API fraud check that fails closed (any AI
  failure holds the redemption), append-only credit ledger, deployed to
  Elastic Beanstalk.
- R (repo trophy case): README with live link, stack and rubric table, CI
  badge, `TRANSPARENCY.md`, `CHANGELOG.md`, decision records in `docs/adr/`.
- Production runs on Amazon RDS MySQL.
- Redemption Fraud Detection eval: headline is `holdout_3` (50 cases, 26
  suspicious, locked in `data/fraud_eval_lock.json` at labeling commit
  5f594c1): caught 17 of 26 suspicious; 17 of 19 holds were suspicious.
  Prompt frozen at `8d5e1e70`. Never edit locked holdout_3 rows (CI fails and
  `fraud:eval` refuses); a prompt change needs a fresh unseen split.
- Brand redesign: theme tokens, self-hosted fonts, employer-first landing
  page, app shell, local-only `/styleguide`.

Update this section whenever the milestone changes.

### Notes for later

- When real Golden Triangle merchant names are seeded from public records,
  update the footer in `resources/views/layouts/app.blade.php` and
  `tests/Feature/LandingPageTest.php` together. Until then the footer must say
  all businesses are fictional, and the test checks that "public records" does
  not appear.

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
- **Database:** SQLite at `storage/database.sqlite` (gitignored) for local
  development; tests use in-memory SQLite. **Production uses Amazon RDS MySQL**
  (database `local_goods`), configured only through Beanstalk environment
  properties (`DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`). CI runs the tests on both SQLite and MySQL.
- **Deploys never wipe data.** The predeploy hook
  (`.platform/hooks/predeploy/01_database.sh`, also run on config changes via
  `.platform/confighooks/`) checks the DB_ settings, then runs `migrate --force`,
  `demo:seed-accounts` and `demo:seed-if-empty`. It runs before PHP restarts, so a failure stops the
  deployment and the running site is unchanged. Never cache config in it.
  Never put `migrate:fresh`, `db:wipe` or `--seed` back in it
  (`tests/Unit/DeployHookTest.php` enforces this). Every migration must work on
  both SQLite and MySQL. Reset demo clears only `DemoResetter::DEMO_TABLES`.
- **Claude API** via the official `anthropic-ai/sdk` package, wrapped in
  `App\Services\Ai\ClaudeClient`. Key from `ANTHROPIC_API_KEY`; model from
  `ANTHROPIC_MODEL` (default `claude-opus-5-5`). Prompts live in `resources/prompts/`.
  Tests use `Tests\Fakes\FakeClaudeClient` and never call the real API.
- **AWS Elastic Beanstalk**, PHP platform, single instance. Config lives in
  `.ebextensions/`. EB runs `composer install` on deploy, so `vendor/` is not
  shipped.
- **Deploying:** run `scripts/build-deploy-zip.sh`, then upload the resulting
  `deploy.zip` in the Elastic Beanstalk console. **There is no auto-deploy
  from GitHub.** Laravel's error log (`storage/logs/*.log`) is included in the
  console's Logs → Request logs downloads (`.ebextensions/laravel-logs.config`).
- Live URL is in `README.md`.

## Deploy checklist

Follow these in order for every deploy. **Whenever you build a deploy zip
for Finn, repeat this checklist in your final message**, and call out the
snapshot step by name when the build says the deploy includes migrations.

1. Stop `composer dev` (Control+C) before building the zip
   (`scripts/build-deploy-zip.sh`).
2. Confirm CI is green on `main` for the commit being deployed (both the
   SQLite and the MySQL job).
3. If this deploy includes any new migration: take a manual RDS snapshot
   first (RDS → local-goods-db → Actions → Take snapshot, named
   `before-<short-feature-name>`) and wait until it shows Available. The
   build ends by listing the migrations since the last deployed commit, or
   saying "No migrations in this deploy." (`scripts/deploy-migrations.sh`;
   a reminder only, it never blocks the build).
4. Upload `deploy.zip` in Elastic Beanstalk with the next version label and
   wait for green. Then run `scripts/mark-deployed.sh` to record the commit
   the zip was built from in `.last-deploy-commit` (git-ignored).
5. After deploy: open the CloudFront site, log in as each demo role, check
   `/eval` and `/eval/merchants`, press Reset demo, then re-seed AI examples
   as Jordan at Farragut Grind (2 normal, 1 suspicious left held).
   With Stripe keys set: the employer page shows "Stripe test mode", and
   `/admin/reconciliation` says everything matches.
6. Never click New link on the live public judge or named reviewer rows.

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
- **Never touch a real database without asking first.** Never delete,
  overwrite or re-create `storage/database.sqlite` or any other database
  (local, RDS or otherwise) without asking Finn first. Never run
  `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `migrate:rollback`,
  `db:wipe`, `db:seed`, Reset demo or anything similar against one, and
  don't use `--env` or `DB_*` overrides as a workaround. If you need a
  database to try something, make a new SQLite file in the scratchpad and
  point `DB_DATABASE` at it. `php artisan test` is fine: tests use an
  in-memory database.
