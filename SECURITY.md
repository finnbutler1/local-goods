# Security

Local Goods is a **student prototype** built for a university contest. It runs
in Stripe **test mode**: no real money moves, and all people, businesses and
transactions are fictional.

- Plain-English version: https://uselocalgoods.com/security
- Detail: [docs/money-safety-case.md](docs/money-safety-case.md) and
  [docs/security-verification.md](docs/security-verification.md)

How to read this file: each control names **Where** it lives (a file) and
**Proved by** (a test). Where no test checks something, it says so.

## Reporting a problem

Email **butlerf@cua.edu** with what you found and how to reproduce it, or use
the **Report a vulnerability** button on the repository's Security tab (GitHub
private vulnerability reporting). Please don't open a public GitHub issue for a
vulnerability. I'll reply within about **5 business days**. If you give me a
reasonable time to fix it before sharing it, I'll credit you if you'd like.

- Never include real personal data in a report, and don't access or change
  anyone else's data while testing.
- **Never include passwords, keys, session cookies or magic links** in a report.
  Demo passwords are shared with judges privately and are never committed.
- **No bug bounty.** This is an unfunded prototype: thank-yous only.

## Scope

**In scope**

- The live site at https://uselocalgoods.com
- The code in this repository

**Out of scope**

- Stripe, AWS, CloudFront and other services we use (report those to them)
- Denial-of-service or volume testing
- Social engineering, phishing or physical attacks

## Controls

### Money

- **Append-only ledger.** Entries can't be edited or deleted. Where: `app/Models/CreditLedgerEntry.php`. Proved by: `LedgerTest`.
- **Solvency guard** on every money operation we decide. Where: `app/Services/Solvency/SolvencyGuard.php`. Proved by: `SolvencyGuardTest`, `SolvencyRuleTest`.
- **No overspend**, including sales tax, two purchases at once, and credits held for review. Where: `app/Services/RedemptionService.php`. Proved by: `EmployeeBalanceSafetyTest`, `MoneyGuardsTest`.
- **Fraud check fails closed.** An AI error or unusable reply holds the payment. Where: `app/Services/RedemptionService.php`. Proved by: `FraudGateTest` (`test_an_ai_failure_or_invalid_reply_is_a_hold_and_never_transfers`).
- **A person decides held payments.** Only a BID or platform admin, with a reason that is kept. Where: `app/Services/RedemptionReviewService.php`. Proved by: `FraudGateTest`, `RedemptionReviewTest`.
- **Payouts only after the fraud gate.** Where: `app/Services/Payments/TransferGate.php`. Proved by: `FraudGateTest` (gate shut, source scan for other transfer callers).
- **Refunds capped at the sale.** Where: `app/Services/Corrections/CorrectionLimits.php`. Proved by: `RedemptionRefundTest`, `EmployerRefundTest`.
- **Webhooks are signature-verified**; live-mode events and mismatched amounts are refused. Where: `app/Http/Controllers/Stripe/StripeWebhookController.php`, `app/Services/Payments/StripeWebhookHandler.php`. Proved by: `StripeWebhookTest`.
- **Credits added only on `payment_intent.succeeded`.** Where: `StripeWebhookHandler.php`. Proved by: `StripeWebhookTest`, `StripeCheckoutTest` (pending credits can't be allocated).
- **Stripe test keys only.** Live keys are refused; with no keys the app simulates. Where: `app/Support/StripeMode.php`. Proved by: `StripeFallbackTest`.
- **Fees kept apart from customer funds**, and Local Goods' own money moves to the operating account only through recorded sweeps. Where: `app/Services/Solvency/LedgerTranches.php`, `app/Services/Payments/OperatingSweeps.php`. Proved by: `LedgerTranchesTest`, `OperatingSweepTest`.
- **Reconciliation with Stripe.** Where: `app/Http/Controllers/Admin/ReconciliationController.php`. Proved by: `ReconciliationTest`, `OperatingSweepReconciliationTest`.
- **Mass assignment is declared** (`$fillable` or `$guarded`) on the main money models. Where: `CreditLedgerEntry`, `CreditPurchase`, `Redemption`, `MerchantPayout`, `Correction` in `app/Models/`. Proved by: no test (checked by reading the files).

### Accounts and access

- **Role checks.** Each role opens only its own pages. Where: `app/Http/Middleware/EnsureUserHasRole.php`, `routes/web.php`. Proved by: `DemoAccountsTest` (`test_role_middleware_lets_only_listed_roles_in`).
- **Judges are read-only.** Only GET, HEAD and OPTIONS pass; QUERY and TRACE are blocked. Where: `app/Http/Middleware/BlockJudgeWrites.php`. Proved by: `BlockJudgeWritesMethodsTest`, `JudgeReadOnlyTest`.
- **Magic links are stored hashed** and end at `JUDGING_ENDS_AT`. Where: `app/Services/ReviewerLinks.php`, `app/Http/Middleware/EndReviewerSessions.php`. Proved by: `ReviewerMagicLinkTest`.
- **Optional two-step sign-in for staff.** A code must be confirmed before it turns on; the password is needed to turn it on or off or to get new recovery codes; recovery codes are shown once; wrong codes are rate limited. Where: `config/fortify.php`, `app/Http/Middleware/GuardTwoStepSignIn.php`. Proved by: `TwoStepSignInTest`.
- **Two-step secrets are encrypted** (with `APP_KEY`). Where: Fortify's storage of `two_factor_secret` and recovery codes. Proved by: `TwoStepSignInTest` (checks the stored value is not the key itself).
- **Emergency off for one account**, only through the server allowlist script. Where: `scripts/artisan-as-webapp.sh`. Proved by: `DisableTwoStepCommandTest`, `ArtisanAsWebappScriptTest`.
- **Shared demo logins** can't change their password or turn on two-step. Where: `app/Support/DemoLogins.php`. Proved by: `AccountPasswordTest`, `TwoStepSignInTest`.
- **Reset demo is blocked in production**, whatever the setting. Where: `app/Providers/AppServiceProvider.php`, `config/demo.php`. Proved by: `ResetDemoTest`.
- **The demo employer's purchases are capped** per purchase. Where: `config/demo.php`. Proved by: `DemoPurchaseCapTest`.
- **Rate limits** on sign-in (5 a minute), other auth forms, redemptions, feedback and magic links. Where: `app/Providers/FortifyServiceProvider.php`, `app/Providers/AppServiceProvider.php`. Proved by: `RateLimitsTest`.

### Data and privacy

- **Merchants see initials only; the BID sees totals only.** Where: views and `Employee::initials()`. Proved by: `EmployeeNamePrivacyTest` (opens every page as every role).
- **Public-judge feedback** can be sent but not read or edited by other visitors. Where: `app/Policies/FeedbackPolicy.php`. Proved by: `PublicJudgeLinkTest` (`test_the_public_account_can_send_feedback_but_not_see_or_edit_it`).
- **Demo addresses can't receive mail.** Where: `app/Support/DemoMailGuard.php`. Proved by: `DemoMailGuardTest`.
- **No mail service is set up.** The mailer defaults to `log` (`config/mail.php`); the app sends no email today. Proved by: no test (the Beanstalk mail settings are outside the code).
- **Merchant records are public business information only.** Proved by: `MerchantOutputPrivacyTest`.
- **No file uploads.** A search of `app/`, `resources/` and `routes/` found no upload handling. Proved by: no test.
- **Logs:** Stripe failures log the call, error type and code, not payloads (`StripeSdkGateway.php`); the pay code is never flashed back to a form (`bootstrap/app.php`). Proved by: no test. Not verified beyond these two places.

### AI

- **Redemption Fraud Detection receives anonymous signals only:** no names, items, tax, fees, districts or corrections. Where: `app/Services/RedemptionService.php`, `app/Support/SignalPresenter.php`. Proved by: `FraudInputTaxTest`, `FraudInputMerchantFeeTest`, `FraudInputCorrectionsTest`, `FraudInputDistrictTest`, `FraudInputByChannelTest`.
- **Merchant Eligibility Matching receives public business information only** (name, address, category, license activity). Where: `app/Services/Eligibility/MerchantEligibilityMatcher.php`. Proved by: `MerchantEligibilityMatcherTest`.
- **Prompts frozen, evaluation data locked.** Fraud prompt `8d5e1e70`, eligibility prompt `458e8c2d`. Where: `config/eligibility.php`, `data/fraud_eval_lock.json`, `data/merchant_labels_lock.json`. Proved by: `FraudEvalTest`, `FraudCatchCountsMatchLockTest`, `MerchantLabelLockTest`, `MerchantEligibilityMatcherTest`.
- **The AI never makes the final call on a held payment.** A held payment moves only on a person's approval. Where: `TransferGate.php`, `RedemptionReviewService.php`. Proved by: `FraudGateTest`.

### Infrastructure

- **Only CloudFront can reach the server** (origin lock). Where: `app/Http/Middleware/RequireCloudFrontOrigin.php`. Proved by: `BehindCloudFrontTest`.
- **HTTPS with HSTS**, and the login cookie is Secure, HTTP-only and SameSite=Lax. Where: `app/Http/Middleware/SecurityHeaders.php`, `config/session.php`, `app/Support/ProductionConfig.php`. Proved by: `SecurityHeadersTest`, `BehindCloudFrontTest`, `ProductionConfigTest`. `config/session.php` sets `http_only` to true and `same_site` to `lax` by default, and `.ebextensions/laravel.config` sets `SESSION_SECURE_COOKIE` to true and doesn't override the other two; the deploy stops if secure isn't true (`ProductionConfig`). No test pins HTTP-only or SameSite.
- **Security headers on web pages**; the Content-Security-Policy is **report-only** (it blocks nothing). Where: `SecurityHeaders.php`. Proved by: `SecurityHeadersTest`.
- **Production forces debug off and shows generic error pages.** The deploy stops if debug is on. Where: `app/Support/ProductionConfig.php`, `resources/views/errors/`. Proved by: `ProductionConfigTest`, `ErrorPagesTest`.
- **Errors also go to the system log**, outside the app folder. Where: `config/logging.php`. Proved by: `LoggingChannelConfigTest`. The exact file on the host isn't confirmed.
- **security.txt and `/version`.** `/version` shows the deployed commit and build time only. Where: `public/.well-known/security.txt`, `app/Http/Controllers/VersionController.php`. Proved by: `SecurityTxtTest`, `VersionEndpointTest`.

### Repository and supply chain

- **GitHub settings, switched on by hand** (no test checks them): private vulnerability reporting; dependency graph and Dependabot alerts; secret scanning with push protection; CodeQL default setup (JavaScript and workflows; it doesn't cover PHP); a ruleset ("Protect Main") that blocks force pushes and deletion on `main`.
- **CodeQL's first scan (2026-11-11) found 5 alerts**, all fixed in `1635c34` (CI permissions made read-only; a design mockup no longer builds HTML from strings). **Confirmed closed on GitHub on 2026-11-12** (checked by hand).
- **`composer audit` and `npm audit` in CI.** A new PHP advisory fails the build; the npm check covers production packages at "high" and is skipped with a warning if npm's audit endpoint doesn't answer. Where: `.github/workflows/ci.yml`. Proved by: the CI run itself.
- **Every commit is scanned for secrets** on every push. Where: `scripts/scan-history-for-secrets.sh`. Proved by: `SecretScanScriptTest`.
- **Dependabot updates are weekly, but PRs aren't merged before the Nov 16 freeze.** Where: `.github/dependabot.yml`. Proved by: `DependabotConfigTest` (the config only; the hold is a decision, not code).

## Set outside the code

These live in consoles, not in this repository, so no test can check them. Names only; values are never written here.

- **Beanstalk environment properties:** `EMPLOYER_PLATFORM_FEE_ENABLED` on, `PLATFORM_FEE_PERCENT` unset, `PUBLIC_JUDGE_URL` set, `LOG_CHANNEL`, `CLOUDFRONT_ORIGIN_SECRET` set. Checked by Finn on 2026-11-12. Check: Elastic Beanstalk → Configuration → Software, then `/where-the-money-is` and the employer page.
- **CloudFront origin sends the `X-Origin-Verify` header.** Checked by Finn on 2026-11-12. Check: `scripts/security-probes.sh <raw-host>` says the raw address is 403 and the site itself loads.
- **Stripe Dashboard:** payment methods limited to card, Apple Pay and Google Pay; verified domain. Checked by Finn on 2026-11-12. Check: Stripe Dashboard → Settings → Payment methods and Payment method domains.
- **SPF and DMARC** on the domain (DNS only; no test or probe checks them). Checked by Finn on 2026-11-12. Check: `dig +short TXT uselocalgoods.com` and `dig +short TXT _dmarc.uselocalgoods.com`. Pass = SPF ending in `-all`, DMARC with `p=reject`.
- **GitHub settings** listed above. Checked by Finn on 2026-11-12. Check: repository Settings → Code security, and Settings → Rules.

## Keys that must never change

**`APP_KEY` must never be rotated.** Changing it signs everyone out and makes the stored two-step secrets and recovery codes unreadable. If someone is locked out, confirm who they are outside the app first, then run `auth:disable-two-step <email>` through `scripts/artisan-as-webapp.sh` (a dry run first, then `--apply`). It clears that one account only; they can set two-step up again from Your account.

## Check it yourself

| Command | A pass looks like |
|---|---|
| `scripts/security-probes.sh` | `PASS` lines for the headers, `/.env` and `/.git/config` (404) and the old-host webhook (400); exit code 0. |
| `scripts/security-probes.sh <raw-host>` | Also `PASS raw Beanstalk address is 403`. Ask me for the address; it isn't published. |
| `composer audit --locked` | "No security vulnerability advisories found". |
| `php -d memory_limit=2G vendor/bin/phpunit --filter 'SecurityTxtTest\|SecurityHeadersTest\|StripeWebhookTest\|FraudGateTest\|TwoStepSignInTest\|JudgeReadOnlyTest\|BlockJudgeWritesMethodsTest\|RateLimitsTest'` | All tests pass. |
| GitHub → Settings → Rules → "Protect Main" | Active, with force pushes and deletion blocked. |
| GitHub → Security tab | Vulnerability reporting on, no open CodeQL alerts. |

## Decided: not now

- **Two-step sign-in stays optional.** Staff turn it on themselves; making it mandatory would lock out the shared demo logins judges use.
- **No email-verification step.** The app sends no email; judges use a link I send them and staff are created by an admin.
- **No web application firewall (WAF).** Disproportionate for a test-mode prototype.
- **No automatic card-dispute handling.** Only card, Apple Pay and Google Pay are enabled and all payments are test mode.
- **Merchant fee set to 0 during the pilot.** Fee logic is built and tested but unused (`MerchantFeeSeedTest`).
- **Laravel framework update held until after judging**, to avoid a risky change during the freeze.

## Still open

| Gap | Why | What would trigger a fix | Cost |
|---|---|---|---|
| No independent penetration test | Nobody outside the project has tried to break it | Before real money or real users | Cost not checked |
| CSP not enforced | Enforcing could break pages during judging | After the freeze, once reports show no breakage | No added cost |
| Card disputes and returned payments not handled | Test mode only | Before live keys or any delayed payment method | No added cost |
| Rate limits count per server | One server, cache-based; counts restart on deploy | A second server | No added cost |
| No real two-connection race test on MySQL for pay codes and redeem | Only lock-wait tests exist (`MysqlRedemptionLockTest`) | Before live money | No added cost |
| No database-level guard on negative balances | The check is in application code, inside the locked transaction | Before live money | No added cost |
| Low-level refund function relies on the cap one layer up | `CreditLedger::refundRedemption` checks only a positive amount; the cap is in `CorrectionLimits` (`EmployeeBalanceSafetyTest` documents it) | Next change to refunds | No added cost |
| `laravel/framework` and `tinker` updates held | Freeze; they pull about 30 packages | After judging, together | No added cost |
| Two-step is optional | See "Decided" | Before real staff accounts | No added cost |
| No edge firewall (WAF) | See "Decided" | Real traffic or abuse | Cost not checked; AWS lists WAF pricing at https://aws.amazon.com/waf/pricing/ |
| Changes go straight to `main`; CI passing isn't a required check in the ruleset | One-person project | More contributors, or before live money | Free (a ruleset setting) |
| Not probed | SPF, DMARC and GitHub settings rest on manual checks | Any DNS or settings change | Free |

## Last updated

2026-11-12
