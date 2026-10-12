# Changelog

What has shipped in Local Goods so far, newest first. Dates come from the
commit history.

## 2026-10-11: Redemption Fraud Detection eval

- 30 synthetic redemptions, each labeled *normal* or *suspicious* by a person
  before the AI saw them ([`data/fraud_eval_cases.csv`](data/fraud_eval_cases.csv)).
- `php artisan fraud:eval` runs them through the production fraud check and
  reports agreement separately for dev and holdout cases. Replies are cached,
  so re-running an unchanged eval costs nothing.
- First run (`claude-opus-5-5`, prompt `e5bcf207`): **80% holdout, 85% dev**.
  See [the results](docs/evals/fraud-eval-results.md) and the
  `/demo/bid-admin/eval` page.

## 2026-10-11: Production database moves to Amazon RDS MySQL

- Production reads its MySQL connection from Beanstalk environment
  properties; local development and tests stay on SQLite.
- **Deploys no longer wipe data.** The deploy hook runs `migrate --force` and
  seeds the demo only if its tables are empty.
- Redemptions lock the employee's row so simultaneous redemptions can't
  overspend on MySQL.
- CI runs every push on both SQLite and MySQL.
- The database hook runs before PHP restarts and checks the DB_ settings
  first, so a mistyped setting stops the change instead of breaking the site.
- Decision record: [ADR 0006](docs/adr/0006-amazon-rds-mysql-in-production.md).

## 2026-10-11: CI and project documentation

- GitHub Actions CI runs the full test suite and front-end build on every push
  and pull request.
- [TRANSPARENCY.md](TRANSPARENCY.md) explains how AI is used in the product and
  how the app was built with Claude Code.
- Architecture decision records in [docs/adr/](docs/adr/).
- This changelog, and a README rewritten around the judging rubric.

## 2026-10-10: First working slice, live on Elastic Beanstalk

- **Redemption Fraud Detection.**
  - PHP calculates anonymous signals and sends them to Claude with a prompt
    file.
  - Claude returns a risk score, a clear/hold decision and a plain-English
    explanation.
  - A score of 70 or higher is always held.
  - Fails closed on any error.
- **Employee wallet** (`/demo/employee`): balance, history, a redeem form,
  and a one-click "suspicious redemption" demo that the AI holds.
- **BID admin view** (`/demo/bid-admin`): every redemption with its AI score,
  decision and explanation, plus a count of held redemptions.
- **Data model:** BID, employer, employees, merchants and an append-only
  credit ledger. Held redemptions keep their credits reserved.
- **Reset demo** button on the BID admin view, and in-page navigation between
  the demo views.
- Brand layout and landing page. The footer notes that all businesses are
  fictional.
- **Deployment fixes:**
  - a postdeploy hook that reseeds the demo database
  - SQLite moved into `storage/`
  - a fix for "database is locked" during redemptions
  - a fix for a 500 error on first deploy
  - nginx routing for Laravel
  - Laravel error logs included in Beanstalk log downloads
- `CLAUDE.md` project brief and a deploy-zip script.

## 2026-10-09: Deployment prep

- Week 2 deployment preparation and the live URL added to the README.

## 2026-10-02: Security housekeeping

- `.pem` and `.key` files ignored repo-wide.

## 2026-09-26: Week 1 skeleton

- Laravel application skeleton.
- Contest attribution in the README.

## 2026-09-21: Project start

- Project thesis: the problem, the two AI functions, the target users and the
  success criteria.
- `.gitignore` to keep secrets out of the repo.
