# Security verification

How to check the protections on the [Security & Trust](https://uselocalgoods.com/security) page yourself, and what that does and doesn't show.

## 1. Live probes

```
scripts/security-probes.sh                       # the public checks
scripts/security-probes.sh <raw-beanstalk-host>  # also checks the origin lock
```

The script only sends plain requests with no credentials and no body, and writes nothing. It checks:

| Check | Expected |
|---|---|
| Security headers on `https://uselocalgoods.com/` (nosniff, referrer policy, frame denial, permissions policy, report-only content policy, HSTS) | all present |
| `GET /.env` and `GET /.git/config` | 404 |
| `POST /stripe/webhook` on the old CloudFront address | 400 (the signature check answers; it is not redirected) |
| The raw Elastic Beanstalk address, if you pass it | 403 (only CloudFront may reach the server) |

It prints PASS, FAIL or SKIP per line and exits 1 if anything fails.

## 2. Tests that act as attack checks

Run with `php -d memory_limit=2G vendor/bin/phpunit --filter <name>`. CI runs all of them on every push.

| What is attacked | What the test does | Test file |
|---|---|---|
| A judge writing data | Sends every write route, and the QUERY, TRACE, POST, PUT, PATCH and DELETE methods, as a judge and checks nothing changes | [`BlockJudgeWritesMethodsTest`](../tests/Feature/BlockJudgeWritesMethodsTest.php), [`JudgeReadOnlyTest`](../tests/Feature/JudgeReadOnlyTest.php) |
| Flooding | Sends one request too many to the write routes and sign-in, and fails a route that can reach the AI without a limit | [`RateLimitsTest`](../tests/Feature/RateLimitsTest.php) |
| Mail to demo addresses | Tries to send mail and notifications to blocked domains | [`DemoMailGuardTest`](../tests/Feature/DemoMailGuardTest.php) |
| Spending more than exists | Overspend, two purchases at once, and the solvency rule | [`EmployeeBalanceSafetyTest`](../tests/Feature/EmployeeBalanceSafetyTest.php), [`MoneyGuardsTest`](../tests/Feature/MoneyGuardsTest.php), [`SolvencyGuardTest`](../tests/Feature/SolvencyGuardTest.php) |
| Personal data showing up | Opens every page as every role looking for names, and scans committed AI output for named individuals | [`EmployeeNamePrivacyTest`](../tests/Feature/EmployeeNamePrivacyTest.php), [`MerchantOutputPrivacyTest`](../tests/Feature/MerchantOutputPrivacyTest.php) |
| A forged payment event | Sends a webhook with no or a wrong signature, a replay, and live-mode events | [`StripeWebhookTest`](../tests/Feature/StripeWebhookTest.php) |
| Missing browser protections | Checks the headers on web pages and their absence on the webhook | [`SecurityHeadersTest`](../tests/Feature/SecurityHeadersTest.php) |

## 3. What this does not prove

- **No independent penetration test.** Everything here was written and run by the people who built the app.
- **The content policy is report-only.** It reports and blocks nothing yet.
- **A probe is one moment.** It shows the live site as of that run, not that it stays that way.
- **Tests use fakes.** They run against a fake Stripe, a fake AI client and an in-memory database, and the MySQL job covers only a few race tests.
- **Rate limits are per server** and start again after each deploy.
- **No two-factor sign-in.**
- **Card disputes and returned payments aren't handled yet.** Stripe runs in test mode only.
- **The mail-spoofing protection (SPF and DMARC) is not probed**; it rests on DNS records.
- **Passing checks are not a guarantee.** A check finds only the problems someone thought to test for.
