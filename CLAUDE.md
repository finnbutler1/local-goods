# Local Goods — project brief for Claude

Read this first in every session. It is the source of truth for what we are
building, for whom, and the rules for working in this repo.

## Current milestone

**Revenue model update — projection and wording only (2026-11-13, ADR
0043), not deployed.** No fee code, ledger, Stripe, migration or database
change; no BID or merchant fee is charged in the app.

- `config/projections.php`: `bid_initiation_fee` removed; `bid_program_fee_pilot`
  ($5,000, Large: Golden Triangle), `bid_program_fee_other` ($2,500, Medium:
  the model has no size for other districts), `bid_program_free_months` (12,
  pilot district only; others pay from launch), `bid_fee_tiers` (display),
  `unspent_never_spent_share` (0.08, capped at `1 − utilization`).
- `BusinessModel`: `bid_fees` a twelfth a month; `unspent_estimate` is display
  only, **never in revenue, EBITDA or break-even** (`BusinessModelTest`). P&L
  row `[data-unspent-estimate]`, note `[data-unspent-estimate-note]`.
- Box, README Business model, MODEL.md, TRANSPARENCY, JUDGES.md say what the
  product does with unspent credits (no expiry; employer's not-yet-given
  credits refunded if asked; employee wallets keep theirs), the state-law
  line and "Local Goods charges no dormancy fees." Base DC-wide break-even
  month 54 (was 56). Tests: `BusinessModelTest`, `BusinessModelPageTest`.

**Plain "How Local Goods makes money" summary — wording and docs only
(2026-11-12), not deployed.** No code, migration, model number or money
change. The employer fee was switched on in Stripe test mode on live on
Thursday evening, 2026-11-12 (Finn's time); keep 2026-11-12 everywhere.

- `/business-model` top box (`projections/partials/how-it-makes-money`,
  `[data-how-it-makes-money]`) and the README "## Business model" opening say
  the same six things; the fee sentence comes from
  `EmployerPlatformFee::status()`, figures from `config/projections.php` and
  the model, always next to "Projection, not results" and `#assumptions`.
  The BID "would receive" a share (planned); the district and unspent-credit
  items were rewritten on 2026-11-13 (ADR 0043, milestone above); ACH is on the roadmap (cards, Apple Pay,
  Google Pay today). README "How it works" item 5 names the employer fee as
  the main revenue line. Test: `BusinessModelPageTest`.

**Employer platform fee: wording follows the setting, ready to switch on
(2026-11-12).** Finn switches it on in Stripe test mode on live after this
deploy (Beanstalk only). No migration or money code change.

- Every "is it on?" sentence comes from `EmployerPlatformFee::status()`
  (/business-model `[data-employer-fee-status]`, Where the Money Is). On, the
  employer dashboard's fee lines carry `data-employer-fee-live` and their
  money mode (`moneyLabel()`, `formLabel()`: "… no real money");
  `PublicClaimsTest` skips only those labelled lines, and
  `PublicClaimsEmployerFeeOnTest` reruns the sweep with the fee on.
  `/security` names no fee either way (`SecurityPageTest` checks both).
- Docs (README, ADR 0042 "Switched on", MODEL.md, STRIPE.md) say switched on
  in test mode on 2026-11-12; switch off by unsetting the variable.
- Demo activity purchase with the fee on: `DemoActivityEmployerFeeTest`.

**Employer platform fee per participating employee — code done (2026-11-12,
ADR 0042), merged to main, not deployed; switched off.**
One additive migration (`2026_11_14_000001_add_employer_platform_fee_to_credit_purchases`,
snapshot `before-employer-fee`). Fraud gate, AI input, eval data, locks,
merchant fee and the percent fee's own code unchanged.

- `EMPLOYER_PLATFORM_FEE_ENABLED` must be exactly `true` (unset = off,
  everywhere); **never set it in a deploy file or seeder** and never turn
  it on without Finn's say-so. Refused together with `PLATFORM_FEE_PERCENT`
  (`EmployerPlatformFee::problem`, in `ProductionConfig`).
- Price = base `projections.assumptions.employer_platform_fee` ($6.00),
  copied onto the purchase. Participants = `App\Support\ParticipatingEmployees`
  only (different employees who got credits in the previous calendar month,
  DC time).
- Charged in `CreditLedger::requestPurchase` after the employer lock: first
  purchase of the month, unique `employer_fee_key`; cancelling only through
  `CreditPurchase::cancelIfPending` (frees the key). Ledger type
  `employer_platform_fee` (platform account) only on payment;
  `CreditLedgerEntry::PURCHASE_FEE_TYPES` where purchase fees are summed.
  Never refundable (refunds cap at credits).
- Off: `[data-planned-fee-preview]` on the employer dashboard, labelled
  "Projection, not results"; link to `/business-model#assumptions` only for
  roles that can open it. `PublicClaimsTest` and `BuyCreditsFlowTest`
  skip only that element.
- Tests: `EmployerPlatformFeeTest`, `ParticipatingEmployeesTest`,
  `EmployerPlatformFeeChargeTest`, `EmployerPlatformFeeDisplayTest`,
  `MysqlEmployerFeeLockTest`, `EmployerFeeMigrationSafetyTest`.

**"Show all (N)" on long lists — code done (2026-11-12), not deployed.** Views, one
component and presentation JS only: no migration, money, route, fraud prompt,
AI input, eval data or lock change.

- `App\Support\ShowMore` (`CAP` 50 rows per list; first rows shown: payouts 3,
  recent redemptions 3, received/history/purchases/recently given/admin Recent
  Activity 5). `<x-show-more>` + `[data-show-more]` in `app.js`: every row is in
  the page, rows past the limit carry `data-more`; without JS nothing is hidden.
  Over 50 the old reload link stays (`?all=1`, `?history=1`; new `all_payouts`,
  `all_recent`, `all_purchases`, `all_given`). Held payments, Fraud Review,
  corrections, applications are never folded; a pending purchase never is.
- Test: `ShowMoreListsTest`.

**Optional two-step sign-in for staff accounts — code done (2026-11-12), not
deployed.** One additive migration
(`2026_11_13_000001_add_two_factor_columns_to_users_table`: Fortify's three
nullable `users` columns, nothing else; snapshot `before-two-step-sign-in`).
No money, Stripe, AI, eval data, lock, password rule, mail guard or origin
lock change.

- Fortify `twoFactorAuthentication(['confirm' => true, 'confirmPassword' =>
  true])`: on only after a code is confirmed; turning on/off and new recovery
  codes need the password. Key and codes encrypted by Fortify with `APP_KEY`
  (**never rotate APP_KEY**: it would also break these). `User::mayUseTwoStepSignIn()`:
  not a judge, not revoked, not one of `DemoLogins` (the platform admin may).
- `GuardTwoStepSignIn` (in Fortify's middleware list) refuses the manage
  routes (403) for anyone else, and Fortify's read-back JSON GETs (QR code,
  secret key, recovery codes) always 404: our page `/account/two-step-sign-in`
  (`account.two-step`, `password.confirm`) shows the QR code/key only while
  setup is unfinished and recovery codes only once, right after they are made.
- Challenge `auth/two-factor-challenge` (code or recovery code); limiter
  `two-factor` 5/min per pending sign-in, plus `auth-forms`. Magic links don't
  go through Fortify's login, so judges are never challenged.
- `demo:seed-accounts` clears the columns on the four shared logins only;
  **no deploy step may touch the platform admin's or any other account's
  two-factor columns** (`TwoStepSignInTest`).
- Emergency: `auth:disable-two-step <email>` (dry run; `--apply`) clears only
  those three columns on one account; refuses unknown emails, demo logins,
  judges and accounts without it; logs user id and role only. In
  `artisan-as-webapp.sh` (exactly one plain email, optional `--apply`).
- Rolling back the code, not the migration, was checked against `2218ec4` on a
  scratch database: a user with it on signs in with the password alone.
- Tests: `TwoStepSignInTest`, `DisableTwoStepCommandTest`,
  `TwoStepSignInMigrationSafetyTest`, `ArtisanAsWebappScriptTest`,
  `SecurityPageTest`.

**Safety guards for the shared demo logins — code done (2026-11-12), not
deployed.** No migration, money rule, Stripe flow, fraud prompt, AI input,
eval data or lock change.

- Reset demo: the `reset-demo` gate also needs `config('demo.reset_enabled')`
  (true only when APP_ENV is local/testing, **no env override**) and refuses
  whenever `app()->isProduction()`. Never add a switch that turns it on live.
- `App\Support\DemoLogins` = the four shared logins (BID admin, employer,
  employee, merchant; platform admin and judge excluded). They can't change
  or reset their password (`UpdateUserPassword`, `ResetUserPassword`;
  `/account` shows a note). `demo:seed-accounts` restores the env password
  anyway on every deploy/config change.
- The demo employer login buys at most `demo.employer_purchase_cap_cents`
  ($5,000) per purchase (validation in `storePurchase` only).
- Tests: `ResetDemoTest`, `AccountPasswordTest`, `DemoPurchaseCapTest`.

**README screenshots, "Run it yourself" and an architecture diagram — docs
only (2026-11-12), not deployed.** No code, migration, money or test change.

- Four PNGs in `docs/screenshots/` (landing map, phone wallet, Fraud Review
  with a held payment, Where the Money Is), taken from a fresh clone in the
  scratchpad with a scratch SQLite and no keys. README "Run it yourself" was
  run from that clone; the full suite needs
  `php -d memory_limit=2G vendor/bin/phpunit` (about 7 minutes) and the
  default `APP_URL`. Mermaid diagram and file list under "Architecture".
  Tests: `PublicClaimsTest`, `OneMinuteSummaryTest` still green.

**Screen-reader names and an axe pass — done (2026-11-11), not deployed.**
Accessible names only; no visible wording, route, money or data change.

- From Finn's VoiceOver notes: the three landing "Request a demo" links say
  "(opens your email)", the wallet balance heading says the amount, and every
  `<x-district-map>` pin has its own `aria-label` (shapes `aria-hidden`).
  axe 4.14 run once locally (20 visits, 0 violations); not in CI. Finn re-tested
  all three fixes in VoiceOver on the live site (2026-11-11). `docs/accessibility.md` is now written around
  what a screen-reader user hears. Tests: `DemoRequestLinkTest`,
  `EmployeeWalletTest`, `DistrictMapPageTest`.

**Apple Pay / Google Pay wording and the dispute gap — docs and copy only
(2026-11-11), not deployed.** No code logic, migration, money or Stripe change.

- Employers can pay for credits by card, Apple Pay or Google Pay through
  Stripe Checkout (enabled in the Stripe Dashboard); bank debits and other
  delayed methods stay off on purpose. README bullet, a `/security` Money
  item (`SecurityController::GROUPS` paths may now be a list), a
  `/coming-next` wallet-card sentence and a "What's not done yet" line:
  card disputes and returned payments aren't handled yet (test mode only).
  Tests: `SecurityPageTest`, `ComingNextPageTest`.

**"Coming next" concept page — code done (2026-11-11), not deployed.** View
and a `Route::view` only: no migration, money, sign-in, fraud prompt, AI
input, eval data or lock change.

- `/coming-next` (`coming-next`): four drawn cards (wallet card, delivery for
  Order to go, residents, AI merchant suggestions), each "Concept, not built"
  at the top and in its `aria-label`. HTML/CSS only; Apple Pay, Google Pay,
  DoorDash Drive and Uber Direct appear as plain text. It must say "We have
  not partnered with any delivery service." and never imply a partnership or
  endorsement; never name the director or the district. Keep `$` figures
  away from fee/revenue words (`PublicClaimsTest`). Linked from `/judge` and
  the README. Test: `ComingNextPageTest`.

**Security headers, reporting and a Security & Trust page — code done
(2026-11-10), not deployed.** No migration, money, sign-in, fraud prompt, AI
input, eval data or lock change.

- `SecurityHeaders` (web group only; `/up` and `/stripe/webhook` get none).
  The Content-Security-Policy is **report-only and must stay that way
  before the freeze**; enforcing it needs Finn's say-so. A new outside
  origin (script, frame, API) goes into its policy. HSTS has no preload.
- `/.well-known/security.txt` expires **2027-11-10** (`SecurityTxtTest`
  fails when it lapses); `SECURITY.md` at the root.
- `/security` (`SecurityController::GROUPS`): every claim cites a file,
  `SecurityPageTest` checks the files exist; the SPF/DMARC line rests on
  DNS (Finn checked: SPF `-all`, DMARC `p=reject`). Never add "secure",
  "guarantee" or "bank-grade"; change the list with the protection.
- `.github/dependabot.yml` (weekly; composer patch/minor only). **Dependabot
  PRs are not merged before the Nov 16 freeze.** CI audits production npm
  packages at "high".
- Tests: `SecurityHeadersTest`, `SecurityTxtTest`, `SecurityPageTest`,
  `DependabotConfigTest`.

**Blind adjudication retired — code done (2026-11-09, ADR 0041), not
deployed.** No migration, money, fraud prompt, AI input, eval data, sign-in or
lock change; no adjudication row is written.

- `config('reviewers.adjudication_retired')` is true and has no env override.
  `AdjudicationSet::retired()`; `isOpen()`/`isClosed()` read it, so the stored
  `published` set behaves as closed. **Never write to the adjudication tables
  to close it.**
- `/adjudicate` shows `adjudicate/closed` (200). Voting POSTs redirect there
  after the gate; `/admin/adjudication` is read-only and its POSTs refuse.
  Removed from `/judge`, the tour (8 steps) and the eval pages. Personal
  contacts land on the closed page; links unchanged.
- The notes below on adjudication describe the retired feature. Tests of the
  dormant voting code switch the setting off in `setUp`. Test:
  `AdjudicationRetiredTest`.

**Landing copy no longer rules out a card; roadmap additions — done
(2026-11-10).** Copy only: no migration, money, fraud prompt, AI input, eval
data or lock change.

- "No cards to issue" and "The phone is the card" removed from the landing
  page and the judge tour (a reusable employer-funded prepaid card is on the
  roadmap). README "Where it's going" gains residential property owners
  funding credits, Apple Pay/Google Pay for the card, and delivery partners
  for Order to go, credited to feedback from "a business improvement district
  director" (never name the person, district or organization).
  Test: `LandingPageTest`.

**Request a demo opens an email — code done (2026-11-09), not deployed.**
Front end only: no migration, money, mail setting, mail guard, sign-in,
fraud prompt, AI input, eval data or lock change.

- All three landing "Request a demo" buttons use `App\Support\DemoRequestLink::url()`
  (mailto to `config('localgoods.demo_email')`, default butlerf@cua.edu,
  encoded subject and body). The final section also shows the address as
  plain text. Nothing is sent from the server. Test: `DemoRequestLinkTest`.

**Platform admin Home with live data, errors outside the app folder — code
done (2026-11-09), not deployed.** No migration, money, fraud prompt, AI
input, eval data or lock change.

- `admin-home` read the generator's stored reconciliation (an array) as a
  `?bool`: a `TypeError` once it had run that day. The view now takes
  `['matches']`; **`ActivityStatus::today()` stays an array** (the demo
  activity page needs it). Present since 73aafa1 (Nov 4), not from 8001410.
- `PublicJudgeLink::url()` reports a bad end date or unknown enum on the row
  and returns null, so the card says "Not set".
- Production logs through `with_system_log`: `LOG_CHANNEL` plus ERROR and
  above via PHP's `errorlog`. Exact file on the host not yet confirmed.
- Tests: `AdminHomeLiveDataTest`, `LoggingChannelConfigTest`,
  `CachedBootSmokeScriptTest`. CI job "Cached boot smoke (general
  protection)" runs `scripts/ci-cached-boot-smoke.sh` (CI only).

**Public judge link on the landing page — done (2026-11-09).** No migration,
money, fraud prompt, AI input, eval data, sign-in or lock change.

- `App\Support\PublicJudgeLink::url()` reads `PUBLIC_JUDGE_URL` (Beanstalk
  only; **never ask for, print or commit it**). Not set unless it is exactly
  `https://uselocalgoods.com/judge-access#<id>.<token>` and that token opens
  the public account, so it hides itself after `JUDGING_ENDS_AT` (Finn chose
  to keep that end date for this link too).
- Shown on the platform admin Home and, logged out only, as the landing
  button `rel="nofollow"`. `brand-head` adds noindex on `judge-access.*` and
  for every read-only user.
- Signed in as the public judge, the landing button reads "Continue looking
  around" and goes to `judge.dashboard` (no token; shown even if the link is
  unset). Other signed-in users get no button.
- Public account is shared: it sends feedback but never sees or edits past
  feedback (`FeedbackPolicy::update`, `/account`); `auth-forms` counts it per IP.
- Tests: `PublicJudgeLinkTest`.

**Demo logins' new addresses — code done (2026-11-08, ADR 0040); Finn runs
the update on live.** No migration, money, fraud prompt, AI input, eval data
or lock change.

- `config('demo.previous_emails')` (role → old address, four roles).
  `demo:update-emails` (dry run; `--apply`) writes `email` only, matched by
  role and exact previous address; judges and the platform admin are excluded
  in the query. Link check before and after (8-hex check, never the hash);
  a difference rolls back and exits 1.
- Until it has run, `demo:seed-accounts` skips that role and warns
  (`needs_email_update`), so a deploy makes no duplicates. Old addresses work
  until `--apply`.
- Live, after deploy: RDS snapshot `before-demo-emails`, then
  `sudo /var/app/current/scripts/artisan-as-webapp.sh demo:update-emails`,
  then the same with `--apply`.
- Tests: `UpdateDemoEmailsTest`.

**Demo mail guard — done (2026-11-08).** No
migration, money, fraud prompt, AI input, eval data or lock change.

- `App\Support\DemoMailGuard` (listeners on `MessageSending` and
  `NotificationSending` in `AppServiceProvider`, every environment, no
  switch) refuses any mail with a recipient on an exact domain in
  `config('demo.mail_blocked_domains')`. **Never add uselocalgoods.com, a
  reviewer's domain or a suffix match.** `User::sendPasswordResetNotification`
  skips them too; `Merchant::stripeContactEmail` never hands one to Stripe
  (read only when an account is created). Magic links are never emailed.
- Live check: `sudo /var/app/current/scripts/artisan-as-webapp.sh
  demo:check-mail-guard` (the script only allows the commands it lists).
- Tests: `DemoMailGuardTest`, `ArtisanAsWebappScriptTest`.

**Names removed from git history — done (2026-11-07, ADR 0039).** One
partial history rewrite with Finn's approval; no migration, money, fraud
prompt, AI input, eval data or holdout change. One lock change: the merchant
label lock re-locked at the new labeling commit `37cc902` (rows unchanged).

- Partial `git filter-repo` from `2d585fb` on (old hash `6b68968`, before
  the 2026-11-07 rewrite): 321 of 440 commits got new hashes; the 119
  before it (fraud lock `5f594c1`, the 3 GitHub-signed commits) are
  unchanged. HEAD's tree and the labels blob are
  identical. Old→new table in ADR 0039; the runbook keeps old hashes.
- **Never rewrite history again without Finn's say-so.** The old working
  copy holds the old history: never push from it or pull into it.
- Still open: GitHub Support purge (runbook step 8), old-URL check (step
  10), deleting the backup and scratch files (step 9), feedback SHAs (Finn,
  in `/admin/feedback`).

**Custom domain uselocalgoods.com — code done (2026-11-06, ADR 0037);
switch made by Finn in the consoles.** No migration, money, fraud prompt, AI
input, eval data or lock change.

- APP_URL = `https://uselocalgoods.com` (Beanstalk only). CloudFront can't
  tell Laravel the visitor's host, and the session cookie is host-only, so a
  second address breaks forms: every other host gets a 301 from the
  CloudFront Function `infra/cloudfront/canonical-host.js` (viewer request;
  `CanonicalHostFunctionTest` runs it with node). **Never redirect
  `/stripe/webhook` or `/up`**; keep `SESSION_DOMAIN` unset.
- External return URLs via `AppUrl::route` (Checkout, onboarding). Old
  address in `config('app.previous_urls')` (feedback links).
  `ProductionConfig` refuses a non-https APP_URL. Judge links are
  path-based, so old ones still work: never press New link for this.
- Stripe webhook destinations: edit the URL in place (keeps the secret).
- Tests: `CustomDomainTest`, `CanonicalHostFunctionTest`.

**Prof. Yoest's asks — done (2026-11-06).** No migration, money, fraud
prompt, AI input, eval data, holdout or lock change.

- PHP: `^8.4.1` with `config.platform.php` 8.4.1 (20 locked Symfony 8.1
  packages need it); CI job "Composer (declared PHP minimum)" runs
  `validate --strict`, `why-not --locked php <min>` and
  `check-platform-reqs --lock`. Live Beanstalk platform not pinned in the
  repo: confirm in the console.
- "Local Goods in one minute": `partials/one-minute-summary` on `/judge`, same
  text as the README's first section (`OneMinuteSummaryTest`). Change both
  together; never imply the BID endorses or partners with us.
- History: `docs/history-rewrite-runbook.md` was carried out on 2026-11-07
  (ADR 0039, milestone above). Never print the names; the runbook's script
  prints counts only.
- Security: 5 Symfony 8.1 packages patched for 10 advisories (patch
  releases only; table in TRANSPARENCY.md "Symfony security advisories
  (2026-11-06)"). The CI Composer job runs `composer audit --locked`, so a
  new advisory fails the build: patch/minor within PHP 8.4.1, ask Finn
  before a major.

**Grouped admin menu and admin Home pages — done (2026-11-04, ADR 0035).**
Front end and routes only; no migration, money, fraud prompt, AI input, eval
data or lock change.

- `Role::navigationGroups()` (BID admin, platform admin; judges viewing as BID
  admin get the BID subset) rendered by `<x-nav-groups>` in the sidebar and the
  phone Menu sheet; `navigation()` is the flat list. **A new admin page must be
  added to a group** (`AdminMenuCoverageTest`); a menu page's `<title>` and
  `<h1>` equal its Title Case label (old heading goes in a line under it).
- Homes: `/admin` (platform) and `/bid-admin/home` (`bid-admin.home`, role
  `bid_admin`; landing for BID admin and judges viewing as one). `/bid-admin`
  stays Fraud Review. Cards read stored data only, never Stripe
  (`AdminHomeTest`); accounts table at `/admin/accounts`.
- Menu says "Fee Settings" on every platform-admin page, so `mentionsFees` in
  `BuyCreditsFlowTest` reads the page body only.
- Not yet tried in a real browser or with a screen reader.

**Redemption refund lock order and MySQL-only test assumptions — done
(2026-11-02).** No migration; fraud prompt, AI input, eval data, holdouts and
locks unchanged. One money change: `approveRedemptionRefund` locks the
redemption, payout and (simulated only) merchant before `claim()`, as
`approveEmployerRefund` does. Without it, two simulated refunds at one merchant
at the same moment could leave it below zero, and `SolvencyGuard` can't see
that because it reads the same snapshot. Test: `MysqlRedemptionRefundSnapshotTest`.

- `StripeFunding::claim` had the same shape and could claim one purchase
  twice; fixed 2026-11-04. **Lock order everywhere: employer, employee,
  purchases, then everything else** (a redemption refund, which never touches
  the employer or purchases: employee, redemption, payout, merchant). A new
  transaction that locks these rows must follow it. `lockEmployer` and
  `lockPurchases` in `StripeFunding` come first in `redeem` (cleared only) and
  in `decide` (approve only), with Stripe on; `requestPurchase` and
  `markPurchasePaidByStripe` lock the employer first. Never put the AI call or
  Stripe inside these transactions. Test: `MysqlStripeFundingSnapshotTest`.
  Deadlock runs and their limits: TRANSPARENCY.md and ADR 0028. Say "no
  deadlocks in these runs", never "deadlock-free".
- A MySQL test that commits a competing change from a hook must check the hook
  ran before it can fail on anything else, or it can fail on old code for the
  wrong reason. `MysqlRedemptionRefundSnapshotTest` fires at the first lock.
- A locking read sees the latest commit; a plain read after it doesn't. In a
  money transaction take every lock a check depends on before the first plain
  read.
- Tests: pick a row with `orderBy('id')`, never a bare `first()`, and use real
  ids. To check by hand: MySQL with `auto_increment_increment=7`,
  `--order-by=random`, and SQLite with `PRAGMA reverse_unordered_selects=ON`.

**Account menu, pay-code dialog and wallet tabs — done (2026-11-02).**
Front end and copy only; no migration, money, fraud prompt, AI input, eval
data or lock change.

- Account menu: everyone gets Your account, What we store about you
  (`/account/data`, `account.data`, GET only, also on the personal-contact
  allowlist), Help and feedback (`/account#feedback`), public site, Log out;
  employees add Your receipts (`wallet#history`), merchants Payout history
  (`#payout-history`). Judges see the same menu. **Rejected (decisions
  table):** sign out of other devices (needs `AuthenticateSession` in the
  web group, which changes every session including judges' magic links, for
  a fictional-account demo), two-factor sign-in (later built as optional
  two-step sign-in, 2026-11-12), change name/email, device
  list or login history, download/delete my data, notification preferences.
- Pay-code dialog: `partials/pay-code-not-valid-dialog` has no variables, so
  it is identical for every refusal; shown only for `PayCodes::NOT_VALID`.
  The rate-limit message stays inline (`role="alert"`). Inline message kept
  as the no-JS fallback. Tests: `PayCodeNotValidDialogTest`.
- Wallet tabs: icon, label, one-line description, check mark on the
  selected tab (`WalletTabsTest`); keep the attribute line layout that
  `PayInPersonWalletTest` matches.
- Not yet tried in a real browser or with a screen reader.

**Two separate Stripe balances: customer funds and Local Goods Operating
— done (2026-11-01, ADR 0034).** One additive migration
(`2026_11_02_000001_add_operating_account`, snapshot
`before-operating-account`). Fraud prompt, signals, AI input, eval data,
holdouts, locks and adjudication unchanged; merchant payouts and employer
refunds unchanged.

- Platform balance = customer funds account. `OperatingAccountSetup`
  makes Local Goods Operating (v2, no dashboard, **never a bank account**,
  one per environment, `operating_accounts`, kept by Reset demo) from a
  button on Reconciliation. Gateway: `createOperatingAccount`,
  `retrieveAccountBalance` (v1 `/balance` + `Stripe-Account`).
- Sweeps: `OperatingSweeps` only (`sweep-operating-funds`, platform admin,
  reason, Review → Confirm against `reviewed_against_cents`). Reserve
  (row + `operating_sweep` line inside `SolvencyGuard::transaction`), then
  transfer after commit; refusal → `operating_sweep_returned`; lost answer
  stays pending (unique `open_key`), "Retry owed transfers" resends. Only
  Stripe-earned money is swept in Stripe mode. **Destination only from
  `OperatingSweeps::destination()`** (refuses a merchant's account;
  `FraudGateTest` allows this one other `createTransfer` caller).
- Solvency: money in hand = customer funds account; `sweptCents`; both
  types in `ACCOUNT_OF_TYPE` (platform), `MONEY_IN_HAND_TYPES`,
  `SWEEP_TYPES`. `operating_sweeps` in `DEMO_TABLES`/`EMPTY_AFTER_SEED`.
- Reconciliation: expected platform balance net of sweeps; sweeps matched
  both ways (`lg_sweep_key`); Operating balance = sweeps sent (note if a
  Reset since it was made).
- Screens: Where the money is (two accounts, real balances via
  `AccountBalances`, staff only, cached 60s; `EarnedMoney` from ledger and
  fee audit log, never "ongoing income" with fees off; sweep history;
  judges words/counts only). Employer Buy credits/receipt
  (`partials/customer-funds-note`), Checkout `custom_text`
  (`StripeCheckout::CUSTOMER_FUNDS_MESSAGE`), merchant payout rows.
  **With no fee earned, employer and reconciliation pages never say "fee"**
  (`BuyCreditsFlowTest`): say "money Local Goods has earned".
- Live: the only earned money is $0.16 from the three-minute fee test
  (Oct 28, redemption #1841), kept as earned (decisions table) and swept
  by Finn after deploy. Trials: `scripts/stripe-trial-operating-account.php`,
  `scripts/stripe-trial-small-sweep.php`.
- Tests: `OperatingAccountMigrationSafetyTest`, `OperatingAccountSetupTest`,
  `SolvencySweepTest`, `OperatingSweepTest`, `OperatingSweepReconciliationTest`,
  `WhereTheMoneyIsAccountsTest`, `CustomerFundsNoticeTest`.

**Customer funds, operating funds and the solvency rule — done
(2026-10-30, ADR 0033).** One additive migration
(`2026_11_01_000001_add_solvency_checks`, snapshot `before-solvency`).
Fraud prompt, signals, AI input, eval data, holdouts, locks and
adjudication unchanged.

- Tranches: `App\Services\Solvency\LedgerTranches` (a line's tranche is
  its account; platform = operating, everything else = customer funds).
  **A new entry type must be added to `ACCOUNT_OF_TYPE`**
  (`LedgerTranchesTest`).
- Rule: money in hand (paid purchases + platform fees − payouts +
  reversals/refund reversals − employer refunds) ≥ promised (each
  employee/employer/merchant account above zero only + held redemptions +
  open refund holds + operating), at every moment; we expect exactly 0
  (more = "not balanced"). Stripe floor: Stripe out ≤ Stripe in.
  `SolvencyCheck::now()` (cheap), `history()` (replays by `created_at, id`,
  one moment per timestamp), `recordsAgree()`.
- Guard: `SolvencyGuard::transaction($what, fn)` around every money
  operation we decide (CreditLedger purchase/payPurchase/allocate(Many)/
  payout, `RedemptionService::redeem`, `RedemptionReviewService::decide`,
  `CorrectionDecisions` refunds and close). It reads once, at the end,
  inside the transaction (**never before the operation's locks**: MySQL
  snapshot). It refuses a shortfall bigger than `SolvencyGuard::known()`
  (the last stored run). `beforeTransfer` in `MerchantPayouts::sendOne`;
  `afterRecorded` for Stripe-reported money (never refuses). The seed runs
  `paused()` and must pass `history()`. Kill switch
  `SOLVENCY_GUARD_ENABLED`; `config/solvency.php` `tick_check` (history
  every tick, default, or current plus daily history).
- `ledger:check-solvency [--trigger=deploy] [--no-record]` stores on
  `solvency_checks` (in `DEMO_TABLES`/`EMPTY_AFTER_SEED`), exits 1 on
  failure; the deploy hook runs it with `|| true`. The demo tick checks
  before and after and halts the day on failure.
- Simulated refund of a paid-out sale (supersedes part of ADR 0028):
  covered by `owedSimulatedCents` first, the rest a simulated pull-back
  (`refund_reversal` on the latest simulated payout, "simulated, no real
  money"); never below zero, never from Stripe-owed money.
- Seed: Pattern B $70 → $60 when it would overdraw (fixed-date seed
  byte-identical; `DemoSeedSolvencyTest` covers known-bad dates).
- Page `/where-the-money-is` (`money`; judges, BID admin, platform admin;
  judges see no operating or money-in-hand figures), POST
  `admin.solvency.run` (`run-solvency-check`, platform admin).
- Tests: `LedgerTranchesTest`, `SolvencyRuleTest`, `SolvencyHistoryTest`,
  `SolvencyGuardTest`, `SolvencySimulatedModeTest`,
  `DemoActivitySolvencyTest`, `DemoSeedSolvencyTest`,
  `WhereTheMoneyIsPageTest`, `SolvencyMigrationSafetyTest`.

**Live demo activity in Stripe test mode — done (2026-10-29, ADR 0032),
off until Finn switches it on.** One additive migration
(`2026_10_31_000001_add_demo_activity`, snapshot `before-demo-activity`).
Fraud prompt, signals, AI input, eval data, locks and adjudication
unchanged; money logic unchanged apart from one gateway method.

- `App\Services\DemoActivity\ActivityGenerator` (tick every 5 min, weekdays
  07:00–19:00 DC): normal services only (`requestPurchase` +
  `StripeTestPayments`, `allocateMany`, `redeem`, `PayCodes`,
  `CorrectionRequests` as the Farragut Grind login). **Never decides**
  (no approve/deny/refund decision; a source scan checks). Never Jordan or
  the collusion pattern members (`demo_activity.excluded_employees`).
- `DayPlanner` is pure (same date, same plan); about every other day one
  slot is "planned as unusual" (recorded, never told to the AI).
- `StripeGateway::createTestPayment` (pm_card_visa, Checkout's metadata +
  `lg_demo_activity`), only from `StripeTestPayments`; the webhook marks
  the purchase paid. Restricted keys need PaymentIntents: Write.
- Safety: `DEMO_ACTIVITY_ENABLED` (default false; **never set it in a
  deploy file or seeder**, a test checks), `DEMO_ACTIVITY_ENDS_ON`
  (2026-11-30), test mode only, caps in `config/demo_activity.php`, queue
  caps (>5 held / >3 open refunds), day stops after 3 failures or two
  reconciliation mismatches (a mismatch that is only webhooks on their way,
  `only_awaiting_webhooks_since` in the report, isn't counted for
  `webhook_grace_minutes`; re-checked after a tick sends transfers), unique
  (day, slot) claims, tick always exits 0.
- Marked: `isGeneratedDemoActivity()` on `Redemption`/`CreditPurchase`
  (eager-load `demoActivity`), `<x-demo-activity-badge>`; allowances aren't
  marked. `partials/waiting-for-a-person` (`WaitingForAPerson`) on the
  admin home, fraud review, corrections queue and the status page.
- Status: `/bid-admin/demo-activity` (read-only, judges via View as),
  `demo-activity:status`, `demo-activity:tick --dry-run`.
- Scheduling: `.platform/hooks/predeploy/02_scheduler.sh` (+ confighook)
  writes `/etc/cron.d/local-goods-scheduler` → `scripts/run-scheduler.sh`
  (reads Beanstalk properties each minute, no PHP unless the switch is
  true, `schedule:run` as webapp; the user switcher only by full path,
  runuser → su → sudo, exit 1 + "FAILED" line if none: cron's PATH has no
  /usr/sbin). **The hook must never fail a deploy**
  (no `set -e`, ends `exit 0`; `DemoActivityScheduleTest`). Logs:
  `storage/logs/scheduler.log`, `storage/logs/demo-activity.log`.
- Tables in `DEMO_TABLES` (front) and `EMPTY_AFTER_SEED`.
- Tests: `DemoActivityPlanTest`, `DemoActivityPurchaseTest`,
  `DemoActivityTickTest`, `DemoActivitySafetyTest`,
  `DemoActivityFraudInputTest`, `DemoActivityPageTest`,
  `DemoActivityBadgeTest`, `DemoActivityScheduleTest`,
  `DemoActivityMigrationSafetyTest`; helper `Tests\Concerns\RunsDemoActivity`.

**Districts, partnerships and merchant joining — done (2026-10-28, ADR
0031).** Three additive migrations (`2026_10_30_000001…000003`, snapshot
`before-districts`). Fraud prompt (`8d5e1e70`), eligibility prompt
(`458e8c2d`), signals, AI inputs, eval data and locks unchanged.

- `districts`: the Golden Triangle row, copied from
  `data/sources/golden_triangle_bid.geojson` (hash, source, dates;
  `config/districts.php`). Never fetched at runtime. Merchants have
  `district_id`, `latitude`/`longitude`, `district_approved_at`, `live_at`;
  employers have `district_id` (program district). Demo points:
  `DatabaseSeeder::DEMO_COORDINATES` (all inside; two demo addresses moved).
- `App\Services\Districts\DistrictRules` is the one place for the rules:
  approve only inside (`approveMerchant` + `Merchant` saving hook);
  `ensureRedeemable` is the one line at the top of `RedemptionService::redeem`
  (refuses before scoring; employers with no district are unrestricted);
  `PayCodes` checks before issue and before the claim; wallet lists
  `Merchant::redeemableFor`. Never pass district fields to signals or the
  AI (`FraudInputDistrictTest`). Test merchants for the seeded employee use
  `Tests\Concerns\PlacesMerchantsInTheDistrict`.
- Partnerships: `PartnershipSettings` only (BID or platform admin, reason,
  reviewed change id, status forward only), append-only
  `partnership_changes`; unique `exclusive_active_key` (set by the model)
  = one active exclusive per district. `PartnershipRevenueShare` reads
  platform ledger lines, display only. `/bid-admin/partnership`: judges see
  words, no figures.
- Addresses: `DcMarGeocoder` (MAR address points layer on DC GIS, keyless;
  citizenatlas is retired, MAR 2 needs a key) → `AddressLookup`
  (`address_lookups`; checker rows keep no typed text) → `DistrictCheck`
  (same `isInside` as approval). Tests: `Tests\Concerns\FakesDcAddresses`
  (prevents stray HTTP).
- Joining: `/apply` (prototype notice first, "I understand this is a
  prototype", privacy note, honeypot `website`, 3 per 10 min per IP; no
  outside calls). `MerchantApplications` is the only status changer
  (applied → eligibility_checked → bid_approved | declined →
  stripe_onboarding → live; conditional UPDATE + append-only event). Status
  link `/apply/status#<id>.<token>` (hash only; JS `form[data-status-link]`).
  The AI only suggests (name, address, category, empty activity; never the
  answer or email). Queue `/bid-admin/applications` (`decide-merchant-applications`;
  judges: masked email, disabled). Go live: one line in
  `StripeOnboarding::apply` → `goLiveIfReady`. `applications:purge
  --before=YYYY-MM-DD` (run by hand 2027-01-01; also checker lookups > 30
  days). Test helper `Tests\Concerns\MakesApplications`.
- Demo: one demo partnership and two `is_sample` applications, all
  labelled; partnership and application tables are in `DEMO_TABLES` and
  `DemoResetter::DISTRICT_TABLES` (seed-if-empty adds just them).
- Pages: `/district` (SVG from the stored polygon, `<x-district-map>`,
  group with linked pins, text alternative, checker), `/who-does-what`
  (8 steps, `[data-tabs]`), `/bid-admin/impact.csv` (aggregates only).
- Tests: `DistrictBoundaryTest`, `DistrictRulesTest`, `FraudInputDistrictTest`,
  `DistrictCheckTest`, `DistrictMapPageTest`, `PartnershipTest`,
  `PartnershipPageTest`, `PartnershipRevenueShareTest`,
  `MerchantApplicationPipelineTest`, `ApplicationEligibilitySuggestionTest`,
  `ApplyFormTest`, `ApplicationStatusLinkTest`, `ApplicationQueueTest`,
  `PurgeApplicationsTest`, `MerchantJoinOnboardingTest`, `DistrictsDemoTest`,
  `WhoDoesWhatPageTest`, `ImpactExportTest`, `DistrictsMigrationSafetyTest`.

**Business model and projections — done (2026-10-28, ADR 0030).** No
migration; money logic, ledger, Stripe, fee settings, fraud prompt, AI
input, eval data and locks unchanged.

- One source: `config/projections.php` (each assumption: low/base/high,
  unit, `source_url` or exactly "Assumption: to validate in customer
  discovery", note, `verified_on`). Only `demand`/`revenue` groups vary
  by case. Scenarios a/b/c (`bids_by_year_end`); card issuing off.
  Go-to-market keys under review: `account_manager_bids_per_manager`,
  `sales_cost_per_new_employer`, `bid_launch_cost`.
- `App\Support\Projections\BusinessModel` (pure, 72 months, P&L 48;
  never reads MerchantFee/PlatformFee/ledger/Stripe, nothing in
  `app/Services` calls it), `Projection`, `WhatItTakes` (scenario A, one
  BID at full ramp, searched), `Figures`, `ProjectionDocs`. Customer funds
  and breakage are never revenue.
- Pages: `/projection` public, district numbers only; `/business-model` and
  `/business-model.csv` for judge, BID admin, platform admin (personal
  contacts refused by their middleware). "Projection, not results" on
  every screen. Fee/revenue/profit figures only there and in the README
  `## Business model` section (`PublicClaimsTest`).
- **After changing an assumption run `php artisan projections:docs`**
  (rewrites the marked blocks in README and `docs/finance/MODEL.md`;
  `BusinessModelDocsTest` fails until you do).
- Tests: `BusinessModelTest`, `ProjectionsConfigTest`,
  `ProjectionFiguresTest`, `ProjectionPageTest`, `BusinessModelPageTest`,
  `BusinessModelDocsTest`.
- Tests that spend seeded balances seed on a fixed date
  (`Tests\Concerns\SeedsOnAFixedDate`, 2026-10-24): the seed's 8-week
  window gives one allowance fewer once its start crosses a month.

**Merchant fee, ready but at 0 — done (2026-10-24, ADR 0029).** One
migration (additive: `merchant_fee_changes`, redemption fee snapshot
columns, `merchant_payouts.fee_cents`, `corrections.fee_cents`, all 0).
Fraud prompt (`8d5e1e70`), signals, AI input, eval data, locks,
adjudication, `TransferGate` and the employer `PlatformFee` unchanged.

- Setting: `App\Support\MerchantFee` (bps ≤ 1000, fixed ≤ 100¢; fee =
  min(total, half-up(total × bps) + fixed), on the total incl. tax). The
  newest `merchant_fee_changes` row is the fee, no row = none (append-only
  audit log, not a demo table). Only `MerchantFeeSettings::change`,
  platform admin (`change-merchant-fee`), reason, reviewed-against id.
  `/bid-admin/fees`: Review (writes nothing) → Confirm (checkbox).
  **Never set a nonzero fee on live or in a seeder** (`MerchantFeeSeedTest`);
  tests use `Tests\Concerns\SetsMerchantFee`.
- Snapshot on the redemption in `RedemptionService::redeem` (`?MerchantFee
  $fee`; pay code confirm passes the one shown, `fee_change`, else
  `PayCodeController::FEE_CHANGED`). **Never pass the fee to signals or the
  AI** (`FraudInputMerchantFeeTest`).
- Ledger: `creditMerchant` adds `merchant_fee` (−) / `platform_merchant_fee`
  (+, platform) when the fee > 0. Stripe row = total − fee (`fee_cents`),
  no row when that is 0; `unclaimedCents` counts amount + fee. Refunds:
  `CorrectionLimits::feeShare` (cumulative, stored on the correction),
  `merchant_fee_returned` / `platform_merchant_fee_returned`, reversal =
  refund − share, `fee_only` action when that is 0. Invariant unchanged.
- Reconciliation: transfer + fee = redemption total; platform fee lines =
  cleared fees − completed refund shares; "includes $X fees kept".
- Screens: merchant fee sentence, Sale / Local Goods fee / You receive
  (`partials/merchant-fee`) on cleared rows, Stripe payouts, pay code
  review; CSV `Local Goods fee`, `You receive` after `Total`. **Judges
  count as the public: no fee figures for them** (`MerchantFee::showsFiguresTo`,
  `PublicClaimsTest` sweeps judge pages). README/TRANSPARENCY words only.
- Merchant "Cleared sales this month" net of completed refunds (Sales,
  Refunds, Net), `CompletedRefunds` timing.
- Tests: `MerchantFeeTest`, `MerchantFeeSettingsTest`, `MerchantFeeSaleTest`,
  `MerchantFeeRefundTest`, `MerchantFeeReconciliationTest`,
  `MerchantFeeDisplayTest`, `FraudInputMerchantFeeTest`, `MerchantFeeSeedTest`,
  `MerchantFeeMigrationSafetyTest`, `MerchantClearedNetOfRefundsTest`.

**Refunds and corrections — done (2026-10-24, ADR 0028).** One
migration (additive: `corrections`, `correction_items`,
`correction_actions`, nullable `credit_ledger_entries.correction_id`).
Fraud prompt (`8d5e1e70`), signals, eval data, locks, adjudication,
`TransferGate`, `RedemptionService` and `MerchantPayouts` unchanged.

- Requests: `CorrectionRequests` (employee/merchant on a cleared
  redemption, merchant on a Stripe payout, HR on unspent credits); own
  items only (404), `throttle:5,1,<name>`, one open per item via the unique
  `corrections.open_key`. Shared `partials/correction-request` (judges get
  disabled fields, no form). Asking moves no money.
- Decisions: `CorrectionDecisions`, **platform admin only**
  (`decide-corrections`), always a reason the requester sees. Queue
  `/bid-admin/corrections` (BID admin and judges read-only). Statuses
  requested → approved → processing → completed/failed, rejected, closed
  (Close releases employer holds; refused while a step is unknown).
- Approval: claim (requested only) + lock the item + re-check every
  counted correction (`CorrectionLimits`: amounts, item quantities,
  cumulative snapshot tax, each payment's `refundableCents`). Stripe after
  commit in `CorrectionProcessor` (key per action/attempt, never throws).
- Redemption refund: sent transfer → `reverseTransfer`, ledger on
  `transfer.reversed` (`refund`, `merchant_refunded`, `refund_reversal`);
  simulated → at once, netted from the merchant; not-yet-sent or
  dashboard-reversed payout → refused (`NOT_SENT_YET`,
  `REVERSED_IN_DASHBOARD`). Employer refund: hold at approval, newest
  payment first, credits only, `createRefund`, `refund.created/updated/failed`.
  Merchant adjustment reuses `MerchantPayouts::send` on the owed row.
  `CreditPurchase::unclaimedCents` subtracts refunds.
- Webhook: `transfer.reversed` records our reversals first; the old
  "owed again" path only for unexplained reversed money. Reconciliation
  matches reversals and refunds; Tax collected is net of refunds
  (`net_tax_cents`, by `completed_at`); CSV has refund rows.
- Never send corrections to the AI (`FraudInputCorrectionsTest`).
  Seed: one completed partial refund (Cold brew + tax) and one rejected
  request at Farragut Grind, never Jordan; tables in `DEMO_TABLES` and
  `EMPTY_AFTER_SEED`. Tour step 8. Stripe trial fixtures in
  `tests/Fixtures/stripe/corrections/` (scrubbed; `StripeTrialFixturesTest`).
  Tests: `CorrectionRequestTest`, `RedemptionRefundTest`,
  `EmployerRefundTest`, `MerchantAdjustmentTest`, `CorrectionRetryTest`,
  `CorrectionWebhookTest`, `CorrectionReconciliationTest`,
  `CorrectionTaxTest`, `CorrectionQueueTest`, `CorrectionPrivacyTest`,
  `CorrectionsDemoTest`, `CorrectionsMigrationSafetyTest`,
  `MysqlCorrectionLockTest`. Test helper `Tests\Concerns\MakesCorrections`.
- Spending figures (BID impact, participation, employer, landing totals,
  recirculation input) are net of completed refunds via
  `CompletedRefunds` (ledger `merchant_refunded`, dated when completed),
  labelled "net of refunds"; visits unchanged (`SpendingNetOfRefundsTest`).

**DC sales tax at redemption — done (2026-10-24, ADR 0027).** One
migration (additive: `merchant_products.tax_category` backfilled from the
merchant; item category/rate; `tax_basis`, `subtotal_cents`, `tax_cents`,
`tax_lines` on redemptions and pay codes). Fraud prompt (`8d5e1e70`),
signals, eval data, ledger rules, webhook, `TransferGate` and
reconciliation unchanged.

- `amount_cents` stays the total (subtotal + tax): the AI, ledger debit,
  merchant earning and Stripe transfer all use it as before. **Never add
  tax fields to the AI input** (`FraudInputTaxTest`).
- Rates only in `config/tax.php` (basis points, `effective_from` in DC
  time, sources, `verified_on`); a change is a new row. Prepared food 10%,
  general 6% then 7% from 2027-10-01 (OTR's own notice conflicts: body says
  2026, banner/FAQ say 2027; we follow the banner), exempt 0%.
  `App\Support\SalesTax` (per category on its subtotal, half up) and
  `TaxBreakdown` (snapshot; `added`, `included`, null = before tax display).
- Tax is added on menu items only; a typed-in amount or Other amount is
  the shop's total, "Amount includes any tax charged by the shop."
  `RedemptionBasket::build` returns `tax`; `redeem(..., ?TaxBreakdown $tax)`
  (default included) checks items + tax = amount and writes the columns.
  Pay codes fix tax at issue and confirm with it.
- Screens read only the snapshot (`partials/tax-breakdown`). Merchant:
  Tax collected (`MerchantTaxSummary`, cleared `added` orders, this and
  last month), `merchant.redemptions.csv` (initials, formula guard), menu
  tax category with "Confirm this with your accountant." Employer: one
  note, no numbers (`EmployerTaxNoteTest`). Never "tax-free", never tax
  advice: merchants file and remit.
- Demo: `DEMO_MENUS` optional 4th value overrides the default category;
  seeded typed-in rows are `included`; `DemoHistorySeeder::TAXED_ORDERS`
  adds three taxed Farragut Grind orders (never Jordan). Tour step "See tax
  at checkout".
- Tests: `SalesTaxTest`, `SalesTaxRedemptionTest`, `SalesTaxDisplayTest`,
  `MerchantTaxTest`, `EmployerTaxNoteTest`, `FraudInputTaxTest`,
  `SalesTaxDemoTest`, `SalesTaxMigrationSafetyTest`.

**Phone-first pass — done (2026-10-24).** Front end only: no route,
controller logic, money, Stripe, prompt, eval, lock or migration change.
Checks that ran: `docs/accessibility.md` "Phone-first pass".

- Tables: one pattern. `<x-rtable label="…">` around
  `<table data-rtable="stack">`; below 640px each row is a card. Every
  body cell needs `data-label="Column"`, `data-primary` (card title) or
  `data-label=""` (actions). Only a short table that fits 320px may be
  `data-rtable="fit"`. `ResponsiveTablesTest` sweeps every page.
- Long emails/links: `<x-breakable :value>` (`<wbr>` after @ . / _ -);
  ids: `wrap-anywhere-safe`. Never `break-all` (a test checks). Tests
  that look for an email must strip `<wbr>` (`EmployeeNamePrivacyTest`
  does).
- Phones: every `x-button` size and select is 44px; standalone links use
  `tap-target`; summaries and checkbox/radio rows are 44px (rules in
  `app.css`). Fields are at least 16px. Page titles use `text-title`
  (clamp). Hover tooltips also open on tap (`data-bar`).
- Safe areas: the app-shell footer (demo notice, now shown on phones
  too) carries `pb-[calc(10rem+env(safe-area-inset-bottom))]`; tab bar,
  toast and Feedback button use the insets. Honesty lines must never sit
  in anything hidden on phones (`PhoneHonestyTest`).
- Desktop (1280px) unchanged: before/after screenshots match apart from
  sub-pixel kerning around `<wbr>`.

**Pay in person with pay codes — done (2026-10-23, ADR 0026).** One
migration (`pay_codes`, `redemptions.channel` default `to_go`, additive).
Fraud prompt (`8d5e1e70`), signals, model, eval data, ledger rules,
webhook, reconciliation logic and `TransferGate` unchanged.

- Wallet tabs: Order to go (old flow, default; `?merchant=`, `?q=` land
  there) and Pay in person (`?tab=in-person`, `pay_merchant=`, own error
  bag `payCode`, ids prefixed `pay-`; shared `employee/partials/basket-fields`).
  Real tabs (`[data-tabs]` in `app.js`, arrow keys); links without JS.
- `App\Services\PayCodes` is the only place codes change: 6 chars from
  `PayCodes::ALPHABET`, 10 min, one live per employee, single use (atomic
  claim), HMAC `code_hash` + encrypted `code`; `NOT_VALID` for every miss,
  5 misses/min per merchant (`failureKey`). Never log a code, never put it
  in a URL or JSON; `pay_code` is in `dontFlash`. Expiry is lazy
  (`expireStale`).
- Confirm = `RedemptionService::redeem(..., $items, Redemption::CHANNEL_IN_PERSON)`.
  The channel is display only (`channelLabel()`); **never pass it to
  signals or the AI** (`FraudInputByChannelTest`).
- Merchant: "Take a payment" on `/merchant`; the session keeps only the
  looked-up id (`Merchant\PayCodeController::SESSION_KEY`). Initials only.
  Held wording neutral, no fraud words.
- Cancel this payment: held in-person only, by its own merchant login or
  a BID/platform admin, never the employee (`RedemptionDecision::mayCancel`;
  the employee only cancels an unused pay code), via
  `RedemptionReviewService::cancel` (same `decide()` path, `review_rejected`
  back to the employee, decision `cancel`). Approve/deny stay admin only.
  Not eval or adjudication data.
- Seed: one in-person $10.00 at Farragut Grind (`DemoHistorySeeder::IN_PERSON_*`,
  never Jordan). `pay_codes` in `DEMO_TABLES` and `EMPTY_AFTER_SEED`.
- Tests: `PayCodeTest`, `PayInPersonWalletTest`, `MerchantPayInPersonTest`,
  `HeldPaymentCancelTest`, `ChannelDisplayTest`, `FraudInputByChannelTest`,
  `PayCodesMigrationSafetyTest`; FraudGateTest covers in-person flows.

**Transit lines brand, button states and menus — done (2026-10-23, ADR
0025).** Front end only: no route, controller logic, money, Stripe, prompt,
eval or migration change. Source of truth: `docs/brand/` (Transit, Logo A).

- Tokens by role in `resources/css/app.css` (`page`, `surface`, `band`,
  `ink`, `muted`, `line`, `edge`, `primary*`, `accent*`, `ok`, `hold`,
  `danger`, `focus`, `disabled-*`, `night*`). Light in `@theme`, dark in
  the two dark blocks (keep them identical). Never reintroduce forest,
  cream, paper, amber, sage or the awning. New pairs go in
  `ColorContrast::PAIRS` (checked light and dark).
- Fonts Overpass + Atkinson Hyperlegible Next (`@fontsource-variable`);
  digits come from Overpass via a `unicode-range` face (plain zero).
- `<x-brand-head>` (icons, manifest, theme script) in all three layouts;
  `<x-theme-toggle>`; `<x-logo>` (`on-dark`, `mark-only`). Footer tagline
  "Reroute your lunch."; hero "You work here. Spend here.".
- Motif only in: landing route line, `<x-eyebrow>`, `.merchant-rail` on
  participating merchants (never on candidates).
- Buttons: `unavailable:` variant (disabled/aria-disabled, not busy);
  `form[data-busy]` adds a spinner + `aria-busy`, cancels a second submit,
  disables submit buttons one tick later. Spinner only when motion allowed.
- `<x-menu>` disclosure (Account, More, View as, public Menu); sidebar max
  5, tab bar max 4, rest in More by the priority list in `app-shell`
  (Feedback visible for platform admin, Merchants for BID admin).
  `Role::navigation()` unchanged. Tests: `BrandLayoutTest`,
  `ThemeToggleTest`, `ButtonFeedbackTest`, `DropdownMenuTest`.

**Mission-first messaging — done (2026-10-23, ADR 0024).** No migration,
no money, Stripe, prompt or eval change.

- Landing: "You work here. Spend here.", "The commute-desk-commute loop",
  "Why it matters" (district first). Judge home: "Why Local Goods exists"
  (the old "What Local Goods is" heading fails `PublicClaimsTest`). README
  opens with the same mission.
- The 3x line only via `partials/recirculation-evidence` (label, rates and
  ratio from `config/impact.php`, pilot line, source); README carries the
  same label and source in its section. Never "Local Goods creates/delivers
  3x", never "guaranteed", never a measured result. No fee, revenue or
  interchange figures on public pages or the README (tests check).

**Product menus at the demo merchants — done (2026-10-22, ADR 0023).** One
migration (`merchant_products`, `redemption_items`, additive). Money rules,
fraud prompt (`8d5e1e70`), signals, model and eval data unchanged.

- Menus: `DatabaseSeeder::DEMO_MENUS` (keyed like `DEMO_MERCHANTS`, 4–8
  each). `Merchant::mayHaveProducts()` (demo names, never a candidate) is
  enforced on save. Reset demo restores them (both tables in DEMO_TABLES;
  `redemption_items` in EMPTY_AFTER_SEED); `demo:seed-if-empty` adds only
  the menus to a demo seeded before them.
- Redeem: GET `?merchant=` shows the menu (no auto-submit), POST
  `items[product_id]=qty` (0–20) plus `amount` = Other amount.
  `RedemptionBasket::build` computes the total from the menu prices;
  `RedemptionService::redeem(..., $items)` scores the amount as before,
  then stores items (name and price copied). Items plus their tax (ADR
  0027) always add up to the amount; a bare amount has no items. **Never pass items into signals or
  the AI** (`RedemptionItemsTest` checks the input is identical).
- Shown via `Redemption::itemsSummary()` (load `items`): wallet, merchant
  dashboard, Fraud review ("not sent to Redemption Fraud Detection"),
  employer returned-credit rows.
- `/merchant/menu` (`Merchant\MenuController`): edit name, price,
  description, on/off, add (max 12); own products only, throttled, judges
  read-only.

**Every demo merchant can receive Stripe test transfers — done
(2026-10-22, ADR 0022).** One migration
(`merchants.stripe_set_up_by_platform`, additive, kept by Reset demo).

- `/admin/reconciliation` → "Connect demo merchants to Stripe (test mode)"
  (`admin.stripe.demo-merchants`, `DemoMerchantStripeSetup`): v2 accounts
  with **no Stripe dashboard** (Stripe refuses ToS on behalf for Express:
  `tos_acceptance_on_behalf_not_allowed`), Stripe test identity values plus
  `btok_us_verified`. Only `DatabaseSeeder::DEMO_MERCHANTS` with no login,
  never a name in `merchant_candidates.csv`. Idempotent, test mode only.
- One merchant per request (`DemoMerchantStripeSetup::step`, about 7s; the
  CloudFront origin limit is 30s). The id is saved right after create, then
  the bank account is added and the status read once, with no waiting and
  no transfers in the step. The page auto-continues (`form[data-auto-submit]`,
  `?connecting=<round>`, stops at 2× merchants); without JS, "Continue
  connecting". Throttle 30/min. A cut-off press is resumed with the same
  idempotency keys.
- Label "Demo account set up by Local Goods in test mode"
  (`Merchant::PLATFORM_SETUP_LABEL`). Never use this path for anyone with a
  login or any real business; they self-onboard on Express pages.
- Scratch trials against real Stripe use their own `APP_KEY`/`APP_ENV`
  (a different `lg_instance` from Finn's local app) and delete throwaway
  accounts afterwards.

**Buy credits in one step, platform fee ready but off — done (2026-10-21,
ADR 0021).** One migration (`platform_fee_cents` default 0, nullable
`credit_ledger_entries.platform`). Money rules unchanged.

- Flow: `employer.purchases.store` → `/employer?pay=<ref>#buy` (Stripe's
  embedded Checkout, `ui_mode: embedded_page`, Stripe.js `endive`,
  `createEmbeddedCheckoutPage`; `initEmbeddedCheckout` throws in that
  version) → `employer.purchases.checkout` (JSON client secret) → return
  `?checkout=returned` → "Confirming with Stripe…" polls
  `employer.purchases.status`. Simulated: one press, paid at once. GETs
  never create anything. `return_url` via `AppUrl::route` (APP_URL in
  production).
- Cancel/Try again: `StripeCheckout::close()` (expire if open; complete =
  never cancel) before `cancelled` or a new token (`restart()`). Webhook
  refuses payments for cancelled purchases.
- Fee: `PLATFORM_FEE_PERCENT` (`App\Support\PlatformFee`), unset = 0 = no
  fee text anywhere (tests check). Fixed on the purchase; own
  `platform_fee` line on the platform account (4th account kind); Stripe
  must receive credits + fee; reconciliation counts fees and checks the
  fee lines. Invariant: paid credits + fees − payouts − held = sum of
  entries. Leave it at 0 unless Finn says otherwise.
- `BuyCreditsFlowTest` is the end-to-end check (fake Stripe, fee off and on,
  reconciliation matching). Local trial: `stripe listen` needs `--events`.

**Merchant data: public business information only — done (2026-10-21).**
Professor Yoest's rule: "public business information only, with no owner
names or personal details." No migration, no prompt, label or lock change;
summary in TRANSPARENCY.md "Merchant data: public business information
only" (keep it true).

- AI reasoning that named people is redacted to "[name removed]" with a
  `redacted` note (shown next to the reasoning). `MerchantOutputPrivacyTest`
  scans every committed merchant output for named individuals: a new
  `merchant:eval` run that names someone must be redacted before committing.
- Candidates: 219 (5 person-named removed; a rebuild skips
  `eligibility.excluded_candidates`, hashes of `MerchantCandidateBuilder::key`).
  `source_url` never asks for `ENTITYNAME`; the points file has no license
  numbers (`fetch-activity` reads them from `source_url`).
- Evidence links: `EvidenceUrl::withoutTracking` wherever they are shown or
  copied into eval outputs. The locked `merchant_labels.csv` keeps 8 links
  with Finn's own tracking IDs, on purpose (lock intact).
- History was rewritten once, on 2026-11-07, to remove the names (ADR
  0039; the label lock was re-locked, the fraud lock commit is unchanged).
  Never rewrite it again without Finn's explicit say-so (both locks name
  commits).

**Confirmation toast after every action — done (2026-10-21).** Display
only; no migration, money, ledger, prompt or eval change.

- `<x-flash />` is a toast rendered once by `app-shell` and `layouts/app`
  (not per page). Keys: `status` (✓), `notice` (still waiting) and `error`
  only; never add new flash keys. Wording says the amount, the place and the
  employee by `initials()`. `ActionConfirmationsTest` covers each action.
- Approvals end with `App\Support\PayoutOutcome::sentence()` (by payout
  channel; "paid" only for a sent Stripe transfer). Display only, never
  sends.
- `app.js` focuses the toast after an `#anchor` jump (Chrome clears focus
  then); field errors still win. Checks that ran: `docs/accessibility.md`
  "Confirmation toasts".

**Blind adjudication in batches of 10 — done (ADR 0020).** One migration
(`adjudication_assignments`, additive). Case set, blind view, opening
question and existing votes unchanged.

- `AdjudicationAssigner::nextBatch`: 7 disputed + 3 controls, no repeats,
  fewest votes first within the person's group (headline and personal
  contacts balanced separately), per-person order. `adjudication.more` /
  `adjudication.finish` are on both allowlists.
- A vote counts once its batch is submitted; participants with no
  assignments and `submitted_at` set pressed Submit all before batches and
  all their votes count. `submitted_at` now means finished.
- Results: `vote_count` per case, `*_votes` per figure, fewer than
  `AdjudicationResults::MIN_VOTES` (3) = `too_few`, per group.

**Personal contacts vote in blind adjudication — done (ADR 0019).** No
migration, no money/AI/eval changes; published case set and votes untouched.

- `ReviewerType::PersonalContact` ("Personal contact (knows Finn)"):
  `mayAdjudicate()` (BID, contest judge, personal contact) drives
  `canAdjudicate`; `countsInAdjudicationHeadline()` (BID, contest judge)
  says whose votes make the result. Friend and Public still can't vote.
- `KeepPersonalContactsToAdjudication::ALLOWED_ROUTES`: voting, feedback,
  account, logout, magic link only; `/judge` and `/` redirect to
  `/adjudicate`, everything else 403 (`PersonalContactAccessTest`). A new
  page needs no change; it's refused for them automatically.
- `AdjudicationResults::for()`: `all`/`unseen` = headline only; `personal`
  is separate, numbered on its own. Never add the groups together.
  `AdjudicationSet::submittedCount()` is headline only.
- Admin pages name a personal contact with `User::adminLabel()` /
  `adminEmail()` (initials, no email); never their name anywhere
  (`EmployeeNamePrivacyTest`).

**Security audit and test gaps — done (Prompt 9C, ADR 0018).** No
migration, no money/AI/eval changes. Summary in TRANSPARENCY.md "Security";
keep it true.

- `scripts/scan-history-for-secrets.sh` (`SecretScanScriptTest`, CI
  `fetch-depth: 0`): never prints a value, fails on a shallow clone. Fake
  values in tests must say fake / not-a-real / example, or the scan fails.
- Origin lock: `RequireCloudFrontOrigin` 403s production requests without
  `X-Origin-Verify` (except `/up`); kill switch `ORIGIN_VERIFY_ENFORCE=false`.
  `app:check-production-config` (`ProductionConfig`) runs in the predeploy
  hook before anything else; production forces debug off at boot.
- Rate limits: write routes use `throttle:N,1,<route name>` (plain
  `throttle:N,1` shares one counter per person). Named limiters `redeem`,
  `feedback`, `login`, `auth-forms` (all Fortify posts, via
  `config/fortify.php`). `RateLimitsTest` fails a write route that can reach
  Claude without a throttle.
- `EmployeeNamePrivacyTest` also sweeps as the public judge (names,
  candidates, reviewer names, emails). `MoneyGuardsTest` covers the money
  refusals.

**Accessibility and UX pass — done (Prompt 9B, `docs/accessibility.md`).**
No migration, no money/AI/eval changes.

- `docs/accessibility.md` lists only checks that actually ran (not tested
  with a screen reader or a real phone). Keep it true when adding pages.
- Tests: `AccessibilityTest` (+ `Tests\Support\AccessibilityAudit`) and
  `EmptyStatesTest` sweep every parameterless GET page as every role via
  `Tests\Concerns\SweepsPages` (also used by `EmployeeNamePrivacyTest`);
  `ErrorPagesTest`. New pages are covered automatically and must pass.
- Patterns (all on `/styleguide` → States): `<x-flash />` for every
  status/error message (focus moves to `[data-flash]` after reload; first
  `aria-invalid` field wins); every POST form has `data-busy="…"` (a test
  checks; logout and magic link exempt); wide tables in
  `.scroll-region` with `role="region" tabindex="0" aria-label`; fields
  with a hint/error point at `{id}-hint`/`{id}-error`; animations only
  `motion-safe:`; `x-badge :wrap="true"` for long text.
- Error pages in `resources/views/errors` show detail only for our own 403
  reasons. The admin's new-link flash is `new_reviewer_link`
  (`ReviewerLinks::SESSION_KEY` is the judge fingerprint).

**BID impact dashboard — built (Prompt 9A, ADR 0017).** No migration.

- `App\Services\BidImpactMetrics`: spending = `merchant_earned` ledger
  entries, dated by the redemption's `created_at` in DC time (like
  `EmployerMetrics`). Merchants reached, employees participating (count
  only), employers participating; this month and since launch; 8 Monday
  weeks. Employer rollup = `EmployerMetrics::for()` unchanged. New
  engagement numbers go into `EmployerMetrics` first.
  `BidImpactMetricsTest` checks totals = ledger and BID = sum of HR.
- `/bid-admin/impact` (`bid-admin.impact`, BID admin nav; platform admin
  links from `/admin`, its tab bar is full at 6). Every number has "How we
  count this". Empty state when nothing has cleared (Reset demo reseeds
  history, so it shows only with an empty ledger).
- Recirculation: only as the boxed "Estimate from an outside study, not
  measured by Local Goods", Civic Economics Indie Impact national summary,
  52.3% (config `impact.recirculation`). "Roughly 3x" / "local multiplier"
  only with that label and source (ADR 0024); never a measured result
  (tests check).
- Landing strip: `BidImpactMetrics::totals()`, labelled "Demo data: all
  people and transactions are fictional.", no names.
- Fraud review label "Round amount (a multiple of $10, and at least $20)"
  is built from `fraud.round_number_*` config (`SignalPresenter`); display
  only.

**Fraud gate on Stripe transfers — built (Prompt 8, ADR 0016).** Built only
around the AI: the fraud prompt (`8d5e1e70`), signals, model and eval data
are unchanged.

- `TransferGate::allows` re-reads the row: approved + merchant earning in
  the ledger + (AI cleared itself via `Redemption::wasClearedByAi`, or a
  `redemption_decisions` Approve). Held, denied, unreviewed, seed: never.
  `MerchantPayouts::sendOne` is the only `createTransfer` caller and asks
  the gate first; `FraudGateTest` checks this (source scan + gate shut).
- Decisions: `RedemptionReviewService::approve($r, $user, $reason)` /
  `deny($r, $user, $reason, DenialReason)`. Append-only
  `redemption_decisions`, one per redemption (unique index), reason
  required, keeps the AI's original answer. Deny: `SuspectedAbuse` →
  employer pool (`review_denied_to_employer`), `NoPurchase` → employee
  (`review_rejected`). Stored status stays `rejected`; the UI says
  "Denied". Not eval labels: never read by the fraud eval, `/eval` or
  adjudication. In DEMO_TABLES; `demo:seed-if-empty` ignores it
  (`DemoResetter::EMPTY_AFTER_SEED`).
- Idempotency: `MerchantPayouts::idempotencyKey` (instance, redemption,
  row created, `attempts`); attempts only go up after a definite refusal.
- Audit trail: `Redemption::auditTrail()` on `/bid-admin` and `/merchant`
  (load `decision` and `stripePayout`). Staff by `User::initialsOf`.
- "Run a collusion example" (`bid-admin.collusion-example`,
  `run-collusion-example` gate, throttled): one real AI call, a seeded
  synthetic employee (never Jordan) at Farragut Grind. Hidden from judges.
- Honest limit: the planted collusion patterns look ordinary one
  redemption at a time; catching them needs new signals (new prompt
  version + fresh holdout, Finn's say-so).

**Stripe Connect, test mode — built (ADR 0015, `docs/STRIPE.md`).** Separate
charges and transfers.

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
  the merchant is onboarded. `TransferGate::allows` is the fraud gate
  (ADR 0016). Ledger payout entries only for `sent`/`simulated` rows.
- Webhook `/stripe/webhook` in `routes/stripe.php`, outside the web group (no
  session/CSRF/user); signature is the only auth; `stripe_events` dedupes
  (not a demo table). The judge sweep skips only `stripe.webhook`.
- Onboarding: Express dashboard on **Accounts v2** (Stripe refuses new v1
  Express accounts here; v2 needs `contact_email`), demo merchants only; GET
  return/refresh pages never create anything; Reset demo keeps
  `stripe_account_id`. `StripeOnboarding::apply` reads v2 accounts and v1
  `account.updated` snapshots. Transfers stay on v1.
- Reconciliation: `/admin/reconciliation`, Stripe-funded activity only,
  this environment only. Every Stripe object we create carries
  `StripeEnvironment::tag()` (`lg_env` + `lg_instance` from `APP_KEY`);
  other-environment and unknown untagged objects go in "From another
  environment, not counted", and the webhook ignores other-env events
  before writing anything. Never suggest refunds without "check before".
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
Eligibility Matching (ADR 0013), the money loop screens (ADR 0014),
Stripe Connect test mode (ADR 0015), the fraud gate on transfers
(ADR 0016, Prompt 8), the BID impact dashboard (ADR 0017, Prompt 9A), the accessibility and UX
pass (Prompt 9B), the security audit (ADR 0018, Prompt 9C) and personal
contacts in adjudication (ADR 0019), one-step Buy credits with the
platform fee ready but off (ADR 0021) and platform-set-up Stripe test
accounts for the demo merchants (ADR 0022), display-only product menus
(ADR 0023), mission-first messaging (ADR 0024), the Transit lines
brand with button states and menus (ADR 0025), paying in person with
pay codes (ADR 0026), the phone-first pass, DC sales tax at
redemption (ADR 0027), refunds and corrections (ADR 0028), the
merchant fee, ready but at 0 (ADR 0029), the business model with
impact projections (ADR 0030), districts, partnerships and merchant
joining (ADR 0031), live demo activity in Stripe test mode (ADR 0032),
customer funds with the solvency rule (ADR 0033), two separate Stripe
balances with recorded sweeps (ADR 0034) and names removed from the git
history (ADR 0039).
- Ledger (ADR 0012): one append-only ledger, each entry on exactly one
  account (employee, employer or merchant). `App\Services\CreditLedger` does
  purchases, allocations, merchant earnings and payouts. A merchant earns a
  redemption only once it clears, never while held. Invariant: purchases −
  payouts (sent or simulated) − amounts under review = sum of all entries.
  With corrections (ADR 0028): payouts are net of `refund_reversal` lines,
  and employer refunds held or done come off too
  (`MakesCorrections::assertLedgerInvariant`). Seeded payouts are `simulated`.
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
  holds a SHA-256 of every labeled row and labeling commit 37cc902. Never
  edit a locked row (CI fails via `MerchantLabelLockTest`); a deliberate
  change needs Finn's say-so and `merchant:lock --force`.
- Blind adjudication (ADR 0011): `/admin/adjudication` builds a set from the
  latest eval run (every disagreement plus as many random agreed cases as
  blind controls; locked once published, new version to change). Only judges
  with reviewer type BID, contest judge or personal contact (ADR 0019,
  reported separately) vote (`adjudicate` gate, `User::canAdjudicate`) at
  `/adjudicate`; `adjudication.start/vote/submit`
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
- HTTPS (ADR 0009, address ADR 0037): CloudFront (`uselocalgoods.com`; www
  and the old `cloudfront.net` address redirect there) in front of
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
  page, app shell, local-only `/styleguide`. Replaced by the Transit lines
  brand (ADR 0025).

Update this section whenever the milestone changes.

### Notes for later

**Dec 1 reminders (after judging closes):**

- Turn on Dependabot security updates (GitHub settings) after the freeze.
- Handle `charge.dispute.*` and returned or failed payments, and show a clear
  pending or failed state for employers, before enabling any bank debit or
  other delayed payment method. Stripe payment methods are limited to cards,
  Apple Pay and Google Pay on purpose (a Dashboard setting, not code; the
  webhook credits only on `payment_intent.succeeded`).

- **laravel/framework is on 13.42.0** (2026-11-13, advisory
  PKSA-yxbx-3c9s-cjt9 / CVE-2026-128083, Concurrency, which the app doesn't
  use; only `laravel/prompts` came with it). Dependabot PR #4 is superseded.
  **Update laravel/tinker (3.0.2 → 3.1.0, PR #2) after the contest:** it
  pulls about 30 other packages (Symfony 8.1.x, Guzzle). Framework 13.35+
  routes the QUERY method, which is why `BlockJudgeWrites` allows a judge GET,
  HEAD and OPTIONS only (`BlockJudgeWritesMethodsTest`). Re-run the full suite
  and the cached boot smoke after the tinker update.
- Renew `/.well-known/security.txt` before 2027-11-10.
- Balance-safety gaps left open after `EmployeeBalanceSafetyTest` (tests
  only, no app change was made):
  - A real two-connection race test on MySQL for pay-code issue/confirm and
    `redeem` (two connections, both reaching the balance check at once). The
    existing `MysqlRedemptionLockTest` only shows a redemption waits for a
    held employee lock; the two-codes race in the new test is simulated.
  - A database-level guard against negative employee balances. Today the
    check is in application code only (inside the locked transaction); the
    database would accept an entry that overdraws.
  - `CreditLedger::refundRedemption` only requires a positive amount; the
    "no more than was spent" cap is in `CorrectionLimits`, called by
    `CorrectionDecisions`. Consider a check at the ledger layer too.

- When real Golden Triangle merchant names are seeded from public records,
  update the footer in `resources/views/layouts/app.blade.php` and
  `tests/Feature/LandingPageTest.php` together. Until then the footer must say
  all businesses are fictional, and the test checks that "public records" does
  not appear.
- Stripe Issuing card preview: not built (ADR 0036). Issuing switches on
  in test mode, but funding cards needs a business account behind an identity
  check (test SSN refused); we chose not to fill it with invented details.
  Don't retry without Finn's say-so; the trial is `scripts/stripe-trial-issuing.php`.

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

- **Laravel** — check `composer.json` for the version (currently `^13.17`, PHP `^8.4.1`, the real minimum of the locked packages; `config.platform.php` pins it and CI checks the lock against it).
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
4. Origin lock (ADR 0018): Beanstalk must have `CLOUDFRONT_ORIGIN_SECRET`,
   and the CloudFront origin must send the same value as its custom header
   `X-Origin-Verify`. The deploy stops ("CLOUDFRONT_ORIGIN_SECRET is empty")
   if the secret is missing while `ORIGIN_VERIFY_ENFORCE` is on (default),
   and also if `APP_DEBUG` is true, `APP_ENV` isn't production or
   `SESSION_SECURE_COOKIE` isn't true (`app:check-production-config`).
   **Kill switch:** if the live site returns a bare "Forbidden" through
   CloudFront, set the Beanstalk environment property
   `ORIGIN_VERIFY_ENFORCE` to `false` (a configuration change, no
   redeploy), fix the header, then set it back to `true`.
5. Upload `deploy.zip` in Elastic Beanstalk with the next version label and
   wait for green. Then run `scripts/mark-deployed.sh` to record the commit
   the zip was built from in `.last-deploy-commit` (git-ignored).
6. After deploy: open https://uselocalgoods.com and check that the raw
   `…elasticbeanstalk.com` address returns "Forbidden", www and the old
   cloudfront.net address 301 to uselocalgoods.com, and a POST to
   `/stripe/webhook` on the old address answers 400 (not 301). Log in as each demo role, check
   `/eval` and `/eval/merchants`. **Never press Reset demo on the live site:**
   it erases the real examples made there (the in-person payment, menu
   orders with tax, the completed correction and any other live demo
   state). Reset demo is for local use only.
   If any demo merchant shows "Not connected to Stripe" on
   `/admin/reconciliation`, press "Connect demo merchants to Stripe (test
   mode)" (Reset demo keeps existing accounts, so usually only once).
   Open `/bid-admin/demo-activity`: it should say "Off" unless Finn has
   switched the generator on (`DEMO_ACTIVITY_ENABLED`, Beanstalk only).
   Open `/where-the-money-is`: the deploy recorded a solvency check. If it
   says Fail, tell Finn the window, merchant and amount; never write a
   fix to the ledger without his say-so.
   On `/admin/reconciliation`, if Local Goods Operating says "Not set up
   yet", press "Create the Local Goods Operating account (test mode)" once.
   Then `/where-the-money-is` shows both accounts. Only Finn sweeps on
   live (Review → Confirm, his own reason); never sweep for him. After a
   sweep, reconciliation's Local Goods Operating row should say Matches.
   With Stripe keys set: the employer page shows "Stripe test mode", and
   `/admin/reconciliation` says everything matches. As the BID admin, press
   "Run a collusion example" once (one AI call) and check its path line.
7. Never click New link on the live public judge or named reviewer rows.
8. Emergency only, if a staff account lost both its phone and its recovery
   codes: through Session Manager, run
   `sudo /var/app/current/scripts/artisan-as-webapp.sh auth:disable-two-step <email>`
   (a dry run that says which account and changes nothing), check the
   account it names, then the same with `--apply`. It turns off two-step
   sign-in for that one account only; they sign in with their password and
   can set it up again from Your account. It refuses the shared demo logins
   and judges.

## Contest

CUA Busch School AI Vibe Coding Contest, judged the **week of Nov 23, 2026**.

| Criterion | Weight |
|---|---|
| Functionality & technical execution | 25% |
| Problem identification & relevance | 20% |
| AI integration & innovation | 20% |
| Code quality & GitHub transparency | 15% |
| UX & design | 10% |
| Business impact & presentation | 10% |
| Bonus: accessibility and ethical AI | up to +5 |

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
