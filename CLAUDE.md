# Local Goods — project brief for Claude

Read this first in every session. It is the source of truth for what we are
building, for whom, and the rules for working in this repo.

## Current milestone

**Merchant Eligibility Matching data foundation — waiting on Finn's labels.**
Steps 1 (ledger), 5 (seed), 2 (candidates) and 3 (label file) are done; step 4
(lock the labels) comes after Finn commits `data/merchant_labels.csv`.

Done: Prompts 0, S, R, DB, E, 1, 2 and 3, magic links (ADR 0010), blind
adjudication (ADR 0011) and the three-account ledger (ADR 0012).
- Ledger (ADR 0012): one append-only ledger, each entry on exactly one
  account (employee, employer or merchant). `App\Services\CreditLedger` does
  purchases, allocations, merchant earnings and payouts. A merchant earns a
  redemption only once it clears, never while held. Invariant: purchases −
  payouts − amounts under review = sum of all entries. Demo payouts are
  `simulated` (no Stripe yet).
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
