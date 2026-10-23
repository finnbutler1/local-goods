# Changelog

What has shipped in Local Goods so far, newest first. Dates come from the
commit history.

## 2026-10-23: Leading with the buy-local mission

- **New landing page story.** "You work here. Spend here." At 6 p.m.,
  downtown's workers head home and their spending goes with them; Local
  Goods gives employees credits that only work at independent local
  businesses. A short "commute-desk-commute loop" block names the problem
  without blaming anyone, and "Why it matters" puts the district first,
  then employers, merchants, employees and the BID.
- **The 3x line is back, labelled.** "Money spent at independent local
  businesses tends to recirculate roughly 3x more in the local economy than
  money spent at chains, an effect often called the local multiplier."
  It appears only in a dashed box with "Estimate from an outside study, not
  measured by Local Goods", the study's 52.3% and 15.8%, "Real results will
  be measured in a pilot" and the Civic Economics source: below the
  landing page cards, on the BID impact page and in the README. It is
  never in the hero and never says Local Goods creates it
  ([ADR 0024](docs/adr/0024-mission-framing-and-the-labelled-3x-line.md),
  replacing ADR 0017's no-3x rule).
- **Judge home** says "Why Local Goods exists" instead of "What Local Goods
  is", and the README opens with the same mission. Both say the AI
  functions assist human decisions and don't make them. Prototype, Stripe
  test mode and demo-data labels are unchanged.
- **Tests** now fail if "3x", "three times" or "multiplier" appears on a
  public page (signed out or as the public judge) or in a README or
  TRANSPARENCY section without the label and source; on "Local Goods
  delivers/creates 3x" or "guaranteed"; on fee, revenue or interchange
  figures; and if the old "What Local Goods is" heading comes back.
- No migration; no change to money, Stripe, the fraud prompt or eval data.

## 2026-10-22: Product menus at the demo merchants

- **Pick from a menu.** Each of the 8 fictional demo merchants has a short
  menu (4 to 8 products, downtown DC prices). On the wallet, an employee
  chooses a merchant, presses **Show menu**, picks quantities with − and +
  and sees the running total, then confirms. **Other amount** is still
  there for anything not listed, and works alone exactly as before.
- **Shown wherever the redemption appears**: the wallet, the merchant
  dashboard ("2× Latte, 1× Croissant"), Fraud review and the employer's
  returned-credit rows, employees by initials as before.
- **Priya can change her menu** at `/merchant/menu`: name, price,
  description, take a product off the menu or put it back, add one (up to
  12). Past redemptions keep the name and price they had. Judges see it
  read-only. Reset demo puts the original menus back.
- **Display only.** The amount is the total, and the money logic is
  unchanged. Redemption Fraud Detection sees exactly what it saw before;
  products are never sent to it (a test checks the AI's input is
  identical) ([ADR 0023](docs/adr/0023-product-menus-are-display-only.md)).
- **One migration** (two new tables, additive). Real DC businesses never
  get products.

## 2026-10-22: Every demo merchant can receive Stripe test transfers

- **Connect demo merchants to Stripe (test mode).** A new platform-admin
  button on `/admin/reconciliation`. It gives each fictional demo merchant
  that has no login a Stripe test account, so a cleared, Stripe-funded
  redemption at any of them becomes a test transfer to that merchant's own
  account, not only at Farragut Grind. Pressing it again creates nothing
  new. It refuses with live keys, and judges can't run it.
- **Honest about who set it up.** Stripe won't let a platform accept its
  terms for an Express account, so these are test accounts with no Stripe
  dashboard, filled in by Local Goods with Stripe's test values. Each one
  reads "Demo account set up by Local Goods in test mode". Real merchants,
  and any merchant with a login, still onboard themselves through Stripe's
  Express pages. Real DC businesses never get a Stripe account
  ([ADR 0022](docs/adr/0022-platform-set-up-stripe-accounts-for-demo-merchants.md)).
- **One migration** (`merchants.stripe_set_up_by_platform`, additive).
  Reset demo keeps these accounts, like Farragut Grind's.
- **No more timeouts.** The first version did all seven merchants in one
  request, about 49 seconds. That timed out on the live site (CloudFront
  waits 30 seconds) and locally. Now each press connects one merchant
  (about 7 seconds), and the page continues by itself, showing "3 of 7
  demo merchants connected, continuing…". Without JavaScript, press
  **Continue connecting**. A press cut off midway is finished by the next
  one with the same Stripe account, never a second one.

## 2026-10-21: Buy credits in one step

- **One step for HR.** Enter an amount and press **Buy credits**. In Stripe
  test mode, Stripe's card form opens right there in the Buy credits card
  (embedded Checkout); there is no separate "Request purchase", and no
  hunting for the purchase in the list. Without Stripe keys, the same press
  adds the credits at once (simulated, no real money).
- **Clear states.** While paying: the amount, the test card and Stripe's
  form. After paying: "Confirming with Stripe…", which updates by itself
  and turns into the green "$400.00 in credits added (Stripe test mode,
  MPG-…). Ready to give." **Cancel payment** says nothing was charged and
  offers **Try again** with the amount filled in. If the form can't start,
  it says why, also with Try again.
- **Money rules unchanged.** The ledger is still written only by Stripe's
  verified webhook, once; Stripe objects keep their environment labels;
  credits stay locked until paid; reconciliation still matches (checked
  against real Stripe test mode on 2026-10-21). A purchase is only
  cancelled once Stripe confirms its Checkout can't take a payment any
  more.
- **Platform fee, ready but off.** A `PLATFORM_FEE_PERCENT` setting,
  unset = no fee, and then nothing mentions fees. When it is set, HR sees
  "Credits $X + platform fee $Y = total $Z" before paying, the fee gets its
  own ledger line on a new platform account, and reconciliation includes
  it ([ADR 0021](docs/adr/0021-one-step-buy-credits-and-a-platform-fee-account.md)).
  It stays off.
- One migration (adds a fee column, default 0, and an empty platform marker
  on ledger lines; nothing existing changes).

## 2026-10-21: Clear confirmation after every action

- **One message at the top of the screen after every action**, so nobody
  has to open a history list to see whether it worked. Green with a ✓ when
  it worked, amber when something is still waiting (held for review,
  purchase still pending), and the warning color when nothing happened. It
  can be dismissed with × or Escape. Green and amber ones fade out after 8
  seconds, unless the device asks for reduced motion; errors stay.
- **Specific wording.** "$50.00 sent to J.R. They can spend it now.",
  "$500.00 in credits added (simulated, no real money). Ready to give.",
  "$8.00 at Farragut Grind held for a quick review: no money moves until a
  BID admin decides." Employees appear by initials only.
- **Approvals say where the money went.** "Stripe test transfer sent.", or
  that it's owed through Stripe because the merchant hasn't finished
  onboarding or the transfer couldn't be made yet, or "Now owed to the
  merchant (simulated, no money moved)." It never says "paid" unless a
  Stripe test transfer was sent.
- **Fixed:** after a redirect to a section further down a page (buying
  credits, giving credits), the message didn't get keyboard and screen
  reader focus in Chrome. Now it does.
- Display only: no change to money, the ledger, AI prompts or eval data.

## 2026-10-21: Merchant data is public business information only

- **No real people in the AI's reasoning.** Merchant Eligibility Matching had
  named a restaurateur and two chefs from memory, and described one café's
  founders. Those words are now "[name removed]" (or "local founders") in
  the review queue, the eval page and every eval file, each with a
  "redacted" note. Classifications, confidence and every result are
  unchanged. A new test fails if a merchant output names a person again.
- **No owner names anywhere.** The "DC license record" link no longer asks
  DC for the legal owner's name, and the map points file no longer lists
  license numbers.
- **219 candidates instead of 224.** Five whose names are a person's were
  removed. None was labeled, so the labels, the lock and the results are
  unchanged. Ordinary shop names like "Henry's Salon" stay.
- **Evidence links without tracking IDs.** Eight of Finn's evidence links had
  Finn's own Google ad-click IDs; they are stripped wherever the links are
  shown. The locked label file keeps them so the lock stays intact.
- Earlier versions remain in the public git history (see TRANSPARENCY.md).

## 2026-10-19: Shorter blind adjudication, in batches of 10

- **About 5 minutes instead of 15.** Each reviewer gets 10 cases (about 7
  disputed, 3 controls) in an order of their own. After submitting:
  "Thanks! Want to do 10 more?" It's optional, never repeats a case, and
  stops once they've seen all 32.
- **Balanced.** Each batch takes the cases with the fewest votes so far in
  the reviewer's group, so every case ends up with a similar number of
  votes. BID staff and contest judges are balanced separately from
  personal contacts.
- **Every batch counts,** even if the reviewer stops after one.
- **Results show their votes.** Each case and each figure says how many
  votes are behind it, and a case with fewer than 3 votes is marked "too
  few votes to call" ([ADR 0020](docs/adr/0020-adjudication-in-balanced-batches-of-10.md)).
- The published case set, what reviewers see, the opening question and
  every existing vote are unchanged. One new table (a migration).

## 2026-10-19: Personal contacts can help check the AI

- **A new reviewer type, "Personal contact (knows Finn)",** for friends,
  family and classmates. Each gets their own magic link on
  `/admin/reviewers` and votes blind on the same adjudication cases (one
  vote per case), after the same "seen the eval page before?" question.
- **Their votes are reported on their own.** The adjudication result on
  `/eval` and the admin page still counts only BID staff and contest
  judges. Personal contacts get a separate section, "Personal contacts
  (know Finn): not counted in the result above", with their own numbers.
  The two are never added together
  ([ADR 0019](docs/adr/0019-personal-contacts-vote-separately.md)).
- **Their link opens only the voting page** and the feedback button: no
  reviewer tour, no "view as", no eval or admin pages. Their names never
  appear on public or reviewer pages; admin pages show initials only.
- The published case set and existing votes are unchanged. The public
  judge link still can't vote.

## 2026-10-19: Security audit and test gaps

- **No secrets in the history.** Every commit on every branch was searched
  for keys and passwords; nothing real was found and no key needed
  rotating. The scan is a script that never prints what it finds, and CI
  now runs it on the full history.
- **Only CloudFront reaches the server.** Requests that skip CloudFront get
  "Forbidden" ([ADR 0018](docs/adr/0018-only-cloudfront-reaches-beanstalk.md)),
  with a Beanstalk setting to switch that off without a redeploy.
- **A deploy with unsafe settings stops** (debug on, not production,
  cookie not HTTPS-only, CloudFront secret missing), and production never
  shows a debug page.
- **Rate limits** on redeeming (each one is an AI call), the sign-in forms,
  Reset demo and the review buttons; each limit now counts on its own.
- **Tests:** the public judge account's view of every page, and the
  refusals in the money and AI code. They found no bugs there.

## 2026-10-19: Honest claims about Stripe and impact

- **Stripe:** the landing page and judge home no longer say payouts "come
  in a later build". They say what is true: payments and merchant payouts
  run on Stripe in test mode, real Stripe transactions, no real money.
- **No "3x" claim in the README.** The study figure is now the same
  labelled outside estimate as the impact page: Civic Economics / American
  Booksellers Association Indie Impact study, 52.3% against 15.8%
  recirculated locally, 2011–2012, 134 businesses in 10 U.S. communities,
  none in DC, not measured by Local Goods.
- **Tests** check every public page and the README for "3x", "three times"
  and "multiplier" claims, and that no public page calls Stripe missing or
  live. The fraud check's own "3x or more the usual amount" signal (one
  employee's spend against their usual) is not an economic claim and stays.

## 2026-10-19: Accessibility and UX pass

- **Checked every page** with keyboard only, at phone width (375px and
  320px) and with the axe accessibility checker; what ran, what was fixed
  and what wasn't tested (including: not tested with a screen reader) is
  in [docs/accessibility.md](docs/accessibility.md).
- **Forms:** every error is tied to its field, and after a form comes back
  focus starts on the first error or on the message saying what happened,
  so screen readers announce it. Buttons say what is happening while the
  next page loads ("Checking with Redemption Fraud Detection…") and can't
  be pressed twice.
- **Friendly error pages** for 403, 404, "page expired", "too many tries",
  500 and 503, each with a way back.
- **Phones:** wide tables scroll inside a box keyboard users can reach;
  nothing is wider than a 320px screen; focus no longer hides behind the
  bottom tab bar; merchant names no longer get cut off.
- **Less motion** for people who ask their system for it.
- **Empty states** everywhere, checked by a test that opens every page on
  an empty database.
- **Fixed:** `/admin/reviewers` crashed in a browser that had used a judge
  link; `/user/confirm-password` was a server error.
- **Wording:** the participation page says "Spent at independent
  merchants" instead of "Dollars recirculated" (ADR 0017 keeps
  "recirculation" for the outside estimate).
- **New tests** check labels, alt text, names, error wiring, scroll boxes,
  table captions and more on every page for every role.

## 2026-10-18: BID impact dashboard

- **BID impact page** (`/bid-admin/impact`, BID and platform admins;
  judges can look): dollars spent at independent merchants, merchants
  reached, employees participating (a count, never names), employers
  participating, and a weekly trend. All of it is read from the credit
  ledger, and every number has a "How we count this" note
  ([ADR 0017](docs/adr/0017-bid-impact-from-the-ledger.md)).
- **Agrees with HR.** Employer engagement is rolled up from each employer
  dashboard with the same definitions, so the BID and HR see the same
  numbers. Tests check the totals against the ledger.
- **No "3x" claim.** One boxed estimate, labelled "Estimate from an outside
  study, not measured by Local Goods", using Civic Economics' Indie Impact
  national summary (52.3% of independents' revenue recirculated locally,
  2011–2012, none of it in DC), with the source linked.
- **Landing page:** a strip of the demo's real ledger totals, labelled
  "Demo data: all people and transactions are fictional." No merchant
  names.
- **Clearer fraud signal label:** "Round number" now reads "Round amount (a
  multiple of $10, and at least $20)", exactly what the check does. The
  check itself, the AI prompt and the eval are unchanged.

## 2026-10-17: Redemption Fraud Detection gates Stripe transfers

- **One gate for all money.** A merchant is paid only for a redemption the
  AI cleared, or one a BID admin approved after the AI held it. Held,
  denied or unreviewed: no transfer, ever. If the AI check fails, the
  redemption is held and only a person can release it
  ([ADR 0016](docs/adr/0016-fraud-detection-gates-stripe-transfers.md)).
- **Approve or Deny with a reason.** Every decision on the fraud review
  page needs a short reason and records who, when and what the AI said.
  Deny asks why: suspected abuse sends the credit back to the employer;
  "no purchase happened" gives it back to the employee. "Rejected" is now
  "Denied" everywhere.
- **Never two transfers.** One Stripe idempotency key per redemption, one
  decision per redemption; double clicks and retries change nothing.
- **Audit trail on screen:** each redemption shows its path, e.g. "AI held
  (risk 88) → approved by BID admin S.T. → transferred tr_…", on the review
  page and on the merchant dashboard.
- **Run a collusion example** (BID and platform admins only): a fresh
  synthetic credit-to-cash redemption at Farragut Grind, checked by the
  real AI, result shown as it is. Judges don't see it.
- **Returned credit on the employer's Credits page.** Under "Ready to give":
  "Includes $X returned from N denied redemptions", and a "Returned credit"
  row for each suspected-abuse denial (redemption number, who decided,
  employee initials). Returned credit stays covered by the Stripe payment
  that funded it, so when it's given out and spent again it still
  transfers through Stripe.
- Reviewer decisions are stored apart from the eval and never change the
  `/eval` numbers. The fraud prompt, signals and eval data are unchanged.

## 2026-10-16: Stripe Connect in test mode

- **Employers pay through Stripe Checkout (test mode).** "Pay with Stripe
  (test mode)" replaces "Pay now" when Stripe keys are set, with the test
  card hint on the page (4000 0000 0000 0077). The purchase stays pending
  until Stripe's signed webhook confirms the payment; only then is the
  ledger entry written, once ([ADR 0015](docs/adr/0015-stripe-connect-test-mode-separate-charges-and-transfers.md)).
- **Merchants onboard with Stripe Express.** Priya can connect Farragut
  Grind from the merchant dashboard; status shows there and on the BID
  roster.
- **Cleared redemptions become Stripe transfers**, with the purchase's
  `transfer_group` and charge, but only Stripe-funded ones. Seeded and
  simulated money owed stays simulated and is never sent to Stripe; the
  merchant dashboard shows "Owed via Stripe test mode" and "Owed,
  simulated" separately. Held or rejected redemptions never transfer; a
  merchant who hasn't finished onboarding stays owed until they do.
- **Webhook** at `/stripe/webhook`: signature required, every event handled
  once, no session or CSRF, works behind CloudFront.
- **Reconciliation** for the platform admin: the ledger next to Stripe,
  every mismatch in plain English.
- **Each environment reconciles only its own Stripe objects.** Payments,
  transfers and connected accounts are tagged with the environment that
  made them, so local testing in a shared Stripe sandbox no longer shows up
  as production mismatches. Others are listed as "From another
  environment, not counted", and the webhook ignores their events.
- **Badges everywhere money moves:** "Stripe test mode" or "Simulated". With
  no keys the demo works exactly as before, labeled simulated. Judges see
  the badges and Stripe ids, never a button that pays or onboards.
- How and why: [docs/STRIPE.md](docs/STRIPE.md).

## 2026-10-15: The money loop on screen

- **Employer/HR dashboard** at `/employer`: "Your team got out into the
  neighborhood." This month's share of employees who redeemed, visits out of
  the office, local businesses visited and first-time visits, dollars spent
  locally, top categories and an eight-week trend (with a table view). Every
  number says how it is counted, and only cleared redemptions count.
- **Buying credits is locked until paid.** A purchase starts pending and
  writes nothing to the ledger; "Pay now (simulated, no real money)" marks it
  paid and is the only thing that adds the credits. Test-mode payments come
  in a later build ([ADR 0014](docs/adr/0014-employer-program-pending-purchases-and-initials.md)).
- **Giving credits** to one person or many at once, for the monthly allowance
  or an occasion (new-hire welcome, birthday, recognition award), all or
  nothing and within the monthly budget set in **Program setup**.
- **Wallet:** search and category filters over the participating merchants,
  a fuller confirmation screen (Approved or Under review, with a confirmation
  number), and full transaction history from the ledger. The fraud check and
  "Try a suspicious redemption" work exactly as before.
- **Merchant dashboard** at `/merchant`: owed now (cleared redemptions only),
  held ones shown as "waiting for review, not owed yet", redemptions received
  and payout history, marked "simulated until Stripe test mode".
- **BID participation** at `/bid-admin/participation`: employers, employees
  active, merchants, dollars recirculated and a merchant roster.
- **Initials only:** outside an employee's own wallet, people are shown as
  "H.M." (plus a staff number for HR), including on the fraud review. A test
  opens every page as every role and fails on any full name.
- **Judges:** the employer and merchant steps of the tour are live, read-only,
  with every write button switched off.

## 2026-10-15: Merchant eligibility prompt frozen, holdout measured

- **The AI is a triage tool: it screens out obvious chains and franchises, routes anything it can't verify to a person, and never approves a merchant on its own.** An AI "eligible" shows as "AI recommends eligible —
  confirm before approving". A decision can only be saved by a BID or
  platform admin, and a test proves no other code path writes one.
- **Prompt frozen as `458e8c2d`** after one dev-only change: answer
  independent only when sure of local ownership and five or fewer locations.
- **Holdout, run once:** 0 of 17 chains and franchises wrongly called eligible,
  15 of 17 called not eligible, 14 of 20 cases sent to a person. Agreement
  (17 of 20) equals the always-"not eligible" baseline, and it found 0 of 3
  independents. On dev it found 2 of 11 without web access. Web search is
  future work.
- **New triage measures and a category-disagreement note** in every merchant
  eval report.

## 2026-10-15: Merchant Eligibility Matching

- **The second AI function is built.** For a real candidate business, Claude
  sees only its name, address, category and license business activity, plus
  the independence rule word for word, and returns eligible yes/no, a
  classification, a 0–1 confidence and its reasoning. It has no web access,
  and every page that shows its reasoning says so. A failed or invalid answer
  becomes "undetermined" for a person, never a guess
  ([ADR 0013](docs/adr/0013-merchant-eligibility-matching.md)).
- **BID review queue** at `/bid-admin/merchants`: the BID admin approves,
  rejects or marks "needs research", with an optional note. Every decision is
  a new row in an append-only log with who and when. Approving never creates
  a merchant or a transaction. Judges see it read-only.
- **`php artisan merchant:eval`** measures it against the 60 locked labels.
  Dev and holdout are scored separately, and the holdout once per prompt
  version. It refuses to run if a label changed. Agreement is shown next to
  the always-"not eligible" baseline, with how many independent businesses
  the AI found and how many of its "eligible" calls were right. Results are at
  `/eval/merchants`, linked from `/eval` and the judge tour.
- **No real names on public pages:** logged-out visitors and the shared public
  judge account see totals only.
- **License business activity** for every candidate, from DC Open Data
  (`php artisan eligibility:fetch-activity`).

## 2026-10-15: Merchant labels locked

- **60 hand-labeled merchants** (40 dev, 20 holdout) in
  `data/merchant_labels.csv`: 14 independent, 46 not independent.
- **Locked before any AI run** in `data/merchant_labels_lock.json`: a
  SHA-256 per row and the labeling commit. `php artisan merchant:lock`
  writes it; CI fails if a labeled row changes, disappears or is added.

## 2026-10-14: Groundwork for Merchant Eligibility Matching

- **40 synthetic Meridian employees** (of 120 staff) and eight weeks of
  synthetic history at the fictional merchants, with monthly credit purchases,
  allowances on the 1st and simulated Friday payouts. Two collusion patterns
  are planted for a future check. Reset demo restores exactly this state.
- **Real merchant candidates:** 224 consumer-facing businesses (219 since
  2026-10-21, when 5 named after a person were removed) inside the
  Golden Triangle BID, from DC Open Data business licenses and the DC GIS
  boundary, each checked three ways to be inside it
  (`php artisan eligibility:build-candidates`). They are candidates only, not
  Local Goods participants, and never appear in the demo.
- **Label file** `data/merchant_labels.csv`: 60 candidates (40 dev, 20
  holdout) for Finn to label by hand before any AI run.

## 2026-10-14: Purchases, allocations and merchant payouts in the ledger

- **The employer side and the merchant side** are now in the same
  append-only ledger as the employee wallet: the employer buys credits, hands
  them to employees, and merchants are owed each redemption once it clears
  ([ADR 0012](docs/adr/0012-one-ledger-three-account-kinds.md)).
- **The fraud gate shows in the books:** a held redemption earns the merchant
  nothing until a person approves it.
- **Merchant payouts** can never exceed what the merchant is owed. Demo
  payouts are marked simulated, since Stripe Connect is not built yet.
- **Safe for live data:** the migration only adds columns, and a test proves
  every existing employee's balance is unchanged.

## 2026-10-14: Blind adjudication of the fraud eval

- **BID staff and contest judges check the disagreements.** Where Finn's label
  and the AI disagree, named BID and contest-judge reviewers vote, blind, on
  who was right ([ADR 0011](docs/adr/0011-blind-adjudication-of-eval-disagreements.md)).
- **Admin setup** at `/admin/adjudication`: the draft starts with every
  disputed case (all splits, grouped by split) plus as many random agreed cases
  as blind controls. Publishing locks it; changes need a new version.
- **"Help check the AI, about 15 minutes"** on the reviewer home page: one
  opening question ("Have you looked at the eval results page before today?"),
  then one case at a time with only the facts, a required reason, progress
  like "7 of 32", and Submit all.
- Voting is the only new write a reviewer can make. The public judge account,
  friends and other reviewer types can look but get 403 on voting.
- **"Independent check of the AI"** card for reviewers who can't vote (the
  public judge link, friends): what the blind check is, how many adjudicators
  have submitted so far, voting closes Nov 13, and a link to the adjudication
  section of `/eval`. A new guided-tour step points to it.
- **Results** on the admin page and in a separate section of `/eval`: per-case
  votes and majority, "sided with the AI in X, with Finn in Y, split in Z" for
  all adjudicators and for those who hadn't seen the eval page, agreement on
  control cases, and breakdowns by split and pattern. The headline and labels
  are unchanged.

## 2026-10-14: A bigger, locked fraud eval

- **50 new hand-labeled cases** (`holdout_3`, FE-41 to FE-90, 26 labeled
  suspicious), labeled one at a time before the AI saw any of them.
- **Locked:** [`data/fraud_eval_lock.json`](data/fraud_eval_lock.json) stores a
  SHA-256 of every holdout_3 row and the labeling commit. `fraud:eval` refuses
  to run if a locked row changes, and CI fails.
- The eval now reports **catch rate** and **hold precision** with 95% Wilson
  confidence intervals, and groups misses and false alarms by pattern tags
  computed from the facts only.
- **New headline**, same frozen prompt `8d5e1e70`: caught 17 of 26 suspicious
  cases; 17 of 19 holds were suspicious. The old "0 suspicious cases missed"
  was measured on only 4 suspicious cases and is no longer claimed.

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
