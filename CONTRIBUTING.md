# How Local Goods is built

Local Goods is a solo project: one builder (Finn Butler) working with Claude Code. This page is how a change gets made here, so a reader can judge the process, not only the result. It is not an invitation to send pull requests; to report a problem see [SECURITY.md](SECURITY.md).

## How a change is made

1. **Plan first.** Claude Code starts in plan mode: it reads the code and writes a plan, and nothing is edited until Finn approves it.
2. **One batch per commit.** Each approved piece of work is one small commit with a plain-English message and tests for any business logic (ledger, eligibility, fraud rules, payouts).
3. **CI on every push** ([`ci.yml`](.github/workflows/ci.yml)): the full test suite on **SQLite** and on **MySQL 8.4** (production runs MySQL), the declared PHP minimum and `composer audit`, an audit of production JavaScript packages, a scan of the whole git history for secrets, and a **cached-boot smoke check** that signs in and loads `/admin` with config, routes and views cached ([`scripts/ci-cached-boot-smoke.sh`](scripts/ci-cached-boot-smoke.sh)).
4. **Decisions are written down** in [`docs/adr/`](docs/adr/); what the project claims is checked against the code in [TRANSPARENCY.md](TRANSPARENCY.md).

## Deploying and rolling back

There is no auto-deploy. Finn builds `deploy.zip` with `scripts/build-deploy-zip.sh` and uploads it in the Elastic Beanstalk console, after the checklist in [CLAUDE.md](CLAUDE.md#deploy-checklist): CI green on both databases, an RDS snapshot first if the deploy has a migration, the CloudFront origin secret set, then a check of the live site. The deploy hook never wipes data. **Rollback:** redeploy the previous version label in Beanstalk; if a migration went with the bad deploy, the RDS snapshot taken before it is the restore point.

## Freeze and Dependabot

Judging is the week of Nov 23, 2026, and features freeze on **Nov 16**. Before the freeze the content security policy stays report-only, and Dependabot pull requests are **not merged** ([`dependabot.yml`](.github/dependabot.yml)); they wait until judging closes.

## Never do

- Use live Stripe keys. Test mode only; live keys are refused.
- Press **Reset demo** on the live site. It erases the real examples made there.
- Rewrite git history without Finn's approval (two eval locks name commits).
- Put real people's names or data in demo data. Everyone in the demo is fictional.
- Say or imply that a Business Improvement District endorses or partners with Local Goods.
- Change locked eval data (`data/fraud_eval_lock.json`, `data/merchant_labels_lock.json`) or the frozen AI prompts without Finn's say-so and a fresh unseen test set.
- Commit secrets, `.env`, or `deploy.zip`.
