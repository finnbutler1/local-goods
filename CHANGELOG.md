# Changelog

What has shipped in Local Goods so far, newest first. Dates come from the
commit history.

## 2026-10-14: Magic links for reviewers

- **Reviewers never set a password.** Each one gets a personal magic link from
  `/admin/reviewers` that signs them straight into their read-only reviewer
  view. It works as often as needed until judging ends (`JUDGING_ENDS_AT`,
  default November 30, 2026, 11:59 PM Eastern); after that, or after Revoke or
  New link, it shows "This link has ended"
  ([ADR 0010](docs/adr/0010-magic-links-for-reviewers.md)).
- The token in the link sits after `#`, so it never appears in server logs.
  Only its hash is stored. The sign-in is rate-limited, can only ever open a
  reviewer (judge) account, and New link or Revoke ends open sessions on their
  next click.
- **Public judge access:** one shared, generic read-only reviewer for the
  README's "For judges" link. It is its own type in the feedback inbox, has
  tighter feedback limits, and can't approve or reject anything. The demo judge
  login it replaces no longer uses a password.
- Removed the "choose a password" page, the password section of `/account` for
  reviewers, and password logins for reviewers. Old invite links show the
  ended page.
- The platform admin's Accounts table has a Status column (Active / Revoked)
  and hides revoked accounts unless "Show revoked" is on.
- The old "Casey Brooks" demo judge is removed once: deleted if nothing points
  at it, otherwise left revoked. `demo:seed-accounts` never recreates it.

## 2026-10-13: HTTPS through CloudFront

- The live site is served over HTTPS by CloudFront
  ([ADR 0009](docs/adr/0009-https-through-cloudfront.md)). Every link,
  redirect and invite link uses the CloudFront address; the login cookie is
  only sent over HTTPS.
- Invite links are signed by path, so they still validate behind CloudFront.
  Links issued before this change need a "New link".
- Login rate limits count each real visitor, using the IP CloudFront reports,
  and only believe it from requests carrying CloudFront's secret header.

## 2026-10-12: Reviewer logins and in-app feedback

- **Invite reviewers** (platform admin, `/admin/reviewers`): enter a name,
  email and reviewer type to create a read-only login and get a link to copy.
  The link is signed, works once and expires in 7 days; the reviewer chooses
  their own password ([ADR 0008](docs/adr/0008-read-only-reviewers-with-single-use-invite-links.md)).
  New link, Revoke, and invited / first login / last seen times.
- **Read-only judges**, enforced by middleware and by policies. A test sends
  every write route as a judge and checks it gets 403 and nothing changes.
- **View as**: judges can look at the app as a BID admin, employer admin,
  employee or merchant, with a "This is read-only" banner and no Approve,
  Reject, Reset demo or Confirm redemption.
- **Judge landing page**: what Local Goods is, a numbered guided tour, and
  "What's new since your last visit" (changelog entries from
  `/admin/changelog` plus the reviewer's own feedback marked fixed).
- **Feedback button** on every signed-in page: rubric category, 1–5 rating,
  severity and comment, with the page address recorded automatically.
  Reviewers edit their own items at `/account`, where everyone can also change
  their password.
- **Feedback inbox** (`/admin/feedback`): filters, status, the commit that
  fixed it, average rating per rubric category, unread count in the sidebar.
- BID admin cards now label the AI's call and the person's call separately:
  "AI decision: hold (risk 82)" and "Final decision: approved by Sam Taylor
  on …".

## 2026-10-12: Logins and roles

- Log in with Laravel Fortify ([ADR 0007](docs/adr/0007-fortify-for-login-and-a-role-column.md)).
  There is no public sign-up: accounts are created by an admin. Logins are
  limited to five tries a minute. Without a mail service, the login page says
  to contact the admin to reset a password.
- Six roles, each with its own dashboard and menu: platform admin, BID admin,
  employer admin, employee, merchant and judge. The demo logins come from
  `php artisan demo:seed-accounts`, with passwords from `DEMO_PASSWORD_*`
  settings (never in the repo).
- The employee wallet moved to `/employee` and the BID admin view to
  `/bid-admin`, both behind login. Old `/demo` links go to Log in. The eval
  results stay public at `/eval`.
- Approve and Reject now record the signed-in BID admin, and only BID admins
  and the platform admin can use them; judges and other roles get 403.

## 2026-10-12: BID review of held redemptions

- Held redemptions on `/demo/bid-admin` now have **Approve** and **Reject**
  buttons. Approve spends the reserved credits; reject returns them to the
  employee. Each decision adds a ledger entry with who decided and when, and a
  redemption can't be decided twice.
- Landing page: the "person makes the final call" bullet now says held
  redemptions wait for BID review; the sample stats are replaced by one worked,
  labeled scenario (2,000 × $50 × 12 = $1.2M a year); the how-it-works
  connector runs exactly from step 1 to step 3.

## 2026-10-12: Brand system, landing page and app shell

- New visual identity: Fraunces headings and Public Sans body text, both
  self-hosted from npm (no font CDN), and forest/cream/amber theme tokens in
  `resources/css/app.css`. `ColorContrastTest` checks every approved
  text/background pair passes WCAG AA.
- Landing page rebuilt for employers: hero with Request a demo and Log in,
  how it works, audience cards (employers first), a Trust and AI section that
  quotes the saved eval headline, and impact figures clearly labeled as samples.
- Shared app shell (sidebar on desktop, bottom tab bar on mobile, signed-in
  name and role). The demo pages now use it; their behavior is unchanged.
- Empty employer `/dashboard` and a local-only `/styleguide`.

## 2026-10-11: Redemption Fraud Detection eval

- 30 synthetic redemptions, each labeled *normal* or *suspicious* by a person
  before the AI saw them ([`data/fraud_eval_cases.csv`](data/fraud_eval_cases.csv)).
- `php artisan fraud:eval` runs them through the production fraud check and
  reports agreement separately for dev and holdout cases. Replies are cached,
  so re-running an unchanged eval costs nothing.
- First run (`claude-opus-5-5`, prompt `e5bcf207`): **80% holdout, 85% dev**.
  See [the results](docs/evals/fraud-eval-results.md) and the
  `/demo/bid-admin/eval` page.
- Prompt change 1, based on dev case FE-18: hold a full-balance redemption at
  a merchant not visited in 30 days. Second run (prompt `8d5e1e70`): 90%
  holdout, 90% dev. The headline stays at 80% because the holdout results had
  already been seen when the prompt was changed.
- Ten new hand-labeled cases (`holdout_2`, FE-31 to FE-40), never seen while
  changing the prompt: 80% agreement with prompt `8d5e1e70`.
- **New headline:** 80% agreement on 10 unseen cases with the current prompt,
  and 0 suspicious cases missed. Every disagreement is a false alarm on the
  same pattern, which goes to human review. Runs 1 and 2 stay listed with
  their notes.

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
