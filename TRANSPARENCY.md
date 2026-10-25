# Transparency

This page explains how Local Goods uses AI inside the product and how AI
(Claude Code) was used to build it. It describes what the code does today.
Anything not built yet is marked **planned**.

## AI inside the product

Local Goods has two named AI functions:

| Function | Status | What it does |
|---|---|---|
| **Redemption Fraud Detection** | Live | Scores every redemption for abuse, such as credit-to-cash collusion. |
| **Merchant Eligibility Matching** | Built, as a triage tool | Suggests whether a real business in the BID counts as independent under the program's rule, with reasoning and a confidence score. It never approves a business; a BID admin decides. See [Which merchants are eligible](#which-merchants-are-eligible). |

The sections up to "Checking the person's labels" cover Redemption Fraud Detection.

### What goes to Claude: anonymous signals only

When an employee redeems credits, the app calculates a small set of signals in
PHP ([`SignalCalculator`](app/Services/Fraud/SignalCalculator.php)) and sends
only those signals to Claude. **No names go to the AI. That includes the
employee, the employer and the merchant.** Claude sees:

- the amount and the balance before the redemption
- whether the amount is a round number, or uses nearly the whole balance
- how many times this employee visited this merchant in the last 7 and 30 days
- how many redemptions the employee has made before, and their median amount
- the merchant's category (cafe, restaurant, retail, salon, fitness) and the
  typical ticket range for that category
- the local hour, and whether it falls outside business hours

The full instructions Claude receives are in
[`resources/prompts/redemption-fraud-detection.md`](resources/prompts/redemption-fraud-detection.md).

### Product menus: display only

Employees can pick items from a demo merchant's menu when they redeem
([ADR 0023](docs/adr/0023-product-menus-are-display-only.md)). **Products
don't affect fraud detection yet.** The redemption is scored on its total
amount and the signals above, exactly as before menus; what was bought is
stored afterwards and only shown to people (the employee, the merchant,
the BID admin in Fraud review, the employer by initials). Fraud review
says so next to the items. A test checks that the AI's input is identical
with and without items. Using products to catch abuse would be a new
signal, so a new prompt version and a fresh unseen holdout. Only the
fictional demo merchants have menus; real DC businesses never do.

Since sales tax display ([ADR 0027](docs/adr/0027-dc-sales-tax-shown-at-redemption.md)),
the total of a menu order includes DC tax on its items, and that total is
what the AI scores. Tax itself is never sent to it (see "Sales tax at
checkout" below).

### Paying in person: what the AI does and doesn't see

Employees can also pay at the counter with a one-time pay code that the
merchant types in and confirms
([ADR 0026](docs/adr/0026-pay-in-person-with-pay-codes.md)). **The AI sees
exactly the same as for ordering to go.** Confirming makes an ordinary
redemption: the same anonymous signals, the same prompt, the same hold or
clear, the same gate on Stripe transfers. Whether it was to go or in
person is stored for people to see and is never sent to Redemption Fraud
Detection; a test checks the AI's input is identical either way. The pay
code itself never reaches the AI, the logs or a URL.

How the codes are kept safe: 6 characters with nothing that reads two
ways (no 0/O, 1/I/L), 10 minutes, one use, one employee, one merchant and
one amount. Stored only as a keyed hash plus an encrypted copy, never in
plain text. A shop that types someone else's code gets the same "Code not
valid." as for a made-up one, and 5 wrong tries a minute make it wait.
Nothing moves until the merchant presses Confirm payment, and the balance
is checked again then.

If the AI holds an in-person payment, both screens say so in neutral words
and no money moves. If the customer then pays another way, the shop
presses **Cancel this payment** on its dashboard: the credits go back to
the employee through the same path as a "no purchase happened" denial, no
transfer ever happens, and it can't be approved later. Only that
payment's own merchant (or a BID or platform admin) may record a cancel;
the employee can't, and their screen tells them the shop can. It is
recorded as a cancel, not as a fraud decision, and like every review
decision it is never used as an eval label or in adjudication.

### Sales tax at checkout

When an employee pays from a merchant's menu, the wallet shows the DC sales
tax before they pay, for example "Latte $5.75, DC meals tax 10% $0.58,
total $6.33" ([ADR 0027](docs/adr/0027-dc-sales-tax-shown-at-redemption.md)).
No AI is involved.

**How it's calculated.** Each menu product has a tax category, which its
merchant can change: prepared food and drinks, general merchandise and
taxable services, or not taxed. Tax is worked out per category, on that
category's subtotal, rounded half up to the cent, in whole cents only, at
the rate in effect at that moment. A typed-in amount (alone, or as an
"Other amount" next to menu items) is the shop's own total with any tax
already in it, so nothing is added. The rate used is saved on each
redemption, so a later rate change never rewrites a receipt. Redemptions
from before tax display say "Tax not itemized (before tax display)".

**The rates and where they come from.** Checked on 2026-10-24 against DC's
Office of Tax and Revenue (OTR) and the DC Code, and kept in
`config/tax.php` with the date each took effect:

| Category | Rate | From |
|---|---|---|
| Prepared food and drinks ("DC meals tax") | 10% | in effect on 2025-10-01 |
| General merchandise and taxable services | 6% | 2025-10-01 |
| General merchandise and taxable services | 7% | 2027-10-01 |
| Not taxed (for example groceries, most personal services) | 0% | 2025-10-01 |

- [OTR Sales and Use Tax FAQs](https://otr.cfo.dc.gov/page/sales-and-use-tax-faqs):
  food and drink prepared for immediate consumption at 10%, the general
  rate at 6%, and "The general sales tax rate remains 6% through
  September 30, 2027."
- [OTR notice of October 1, 2025 tax changes](https://otr.cfo.dc.gov/release/notice-oct-1-2025-tax-changes):
  its banner says the increase to 7% is postponed to that date. The older
  body text of the same notice still says 7% from October 1, 2026; we
  follow the newer banner and FAQ, and will check again before
  October 2027.
- [OTR taxable and non-taxable services](https://otr.cfo.dc.gov/page/taxable-and-non-taxable-services):
  health-club services are taxed at the general rate, so the demo cycle
  studio's classes are taxed.
- [DC Code § 47-2001](https://code.dccouncil.gov/us/dc/council/code/sections/47-2001):
  groceries (SNAP "eligible foods") and personal service transactions are
  not retail sales, so salons start as not taxed. No OTR page says this
  about salons directly.

Not modelled: DC's soft drink rate and the rental vehicle, off-premises
alcohol, lodging and parking rates; no demo menu needs them. Two demo
items are judgment calls: the deli's fountain drink is taxed as part of a
prepared meal, and the cycle studio's bottled water is not taxed as a
grocery item.

**Where the money goes.** The total (subtotal plus tax) is what the
employee's credits pay, what the ledger records, what the merchant is owed
and what a Stripe test-mode transfer sends, exactly as for any amount. The
ledger rules, reconciliation and the gate on transfers didn't change.

**The AI.** Redemption Fraud Detection sees the amount charged, which for a
menu order now includes tax. It never sees tax, subtotal or category: a
test checks that a taxed menu order and a typed-in amount of the same
total give the AI identical input. One side effect, documented rather than
changed (the prompt is frozen): the "compared with this employee's usual
amount" signal now compares new taxed totals with older amounts, a
difference of at most the 10% rate.

**What this is and isn't.** It is a display for receipts and records, at
DC's published rates. It is not tax advice. The merchant files and remits
DC sales tax, never Local Goods, and the merchant dashboard says so next
to "Tax collected" (cleared menu orders, this month and last, plus a CSV).
Each product's category comes with "Confirm this with your accountant."
The employer dashboard carries one line with no numbers: "Credits that
work like cash are generally taxable wages for employees. Confirm the
treatment with your tax adviser."

### What Claude decides

Claude replies in a fixed JSON format with three things:

- a **risk score** from 0 to 100
- a **decision**: `clear` or `hold`
- a **plain-English explanation** that an HR manager can read

The app saves all three, along with the signals and the model that scored it.
The BID admin view (after [logging in](https://d4ghrpm72uczx.cloudfront.net/login))
shows them for every redemption, so the AI's reasoning is never hidden.

### The score-70 override

The app does not simply accept Claude's decision. If Claude says `clear` but
gives a risk score of **70 or higher**, the app holds the redemption anyway
([`config/fraud.php`](config/fraud.php), `hold_if_score_at_least`). This
means a high-risk score can never be approved because of an inconsistent reply.

### Failing closed

When the AI call fails, Local Goods holds the redemption instead of approving
it. Any of these counts as a failure:

- an API error or timeout
- a missing API key or a refusal
- malformed JSON
- a score out of range or an empty explanation

In each case the app records a hold with score 100, marks it `scored_by =
fallback`, and shows the explanation: *"Automatic hold: the AI check could not
be completed, so a person will review this redemption."* No money moves
until a person approves it (see below). See
[`RedemptionFraudDetector`](app/Services/Fraud/RedemptionFraudDetector.php)
and [ADR 0003](docs/adr/0003-fail-closed-fraud-detection.md).

### Human review of holds, and the gate on money

A held redemption is never thrown away and never approved automatically.
**No money moves for it until a person decides**
([ADR 0016](docs/adr/0016-fraud-detection-gates-stripe-transfers.md)):

- A held redemption is *under review*. The employee's credits stay
  reserved. The BID admin view shows the AI's score, decision, explanation
  and the anonymous signals it was given.
- A BID admin or the platform admin presses **Approve** or **Deny** and
  must write a short reason. Deny also asks why, which decides where the
  credit goes: *suspected abuse* sends it back to the employer (so a
  suspected colluder can't simply try again), *no purchase happened* gives
  it back to the employee. Every decision records who, when, the reason
  and what the AI originally said, and a redemption can only be decided
  once. Judges can see all of this but can't decide.
- **One gate for all money.** A transfer to a merchant happens only for a
  redemption the AI cleared itself or a person approved after a hold
  (`TransferGate`). Held, denied, unreviewed: no transfer. If the AI call
  failed, the redemption was held, so only a person can release it. In
  Stripe test mode an approved, Stripe-funded redemption sends exactly one
  test-mode transfer; otherwise payouts are simulated. Every money page
  says which. Nothing sent to Stripe names an employee. See
  [docs/STRIPE.md](docs/STRIPE.md).
- **Demo merchants' Stripe accounts say who set them up.** Stripe won't
  let a platform accept its terms for an Express account. So the
  fictional demo merchants with no login get test accounts that Local
  Goods filled in with Stripe's test values, and each reads "Demo account
  set up by Local Goods in test mode". Real merchants always onboard
  themselves through Stripe's Express pages, and real DC businesses never
  get a Stripe account (ADR 0022).
- Each redemption shows its path, e.g. *AI held (risk 88) → approved by
  BID admin S.T. → transferred tr_…*, on the review page and the merchant's
  dashboard. People are shown by initials only.
- **Reviewer decisions are not eval labels.** They are kept in their own
  table and never feed the fraud eval, holdout_3, adjudication or the
  `/eval` numbers. A person deciding a live case knows things the AI
  didn't (the merchant, the receipt), so counting their decisions as
  labels would change the score without the AI getting any better.
- **What the demo can and can't show.** BID and platform admins have a "Run
  a collusion example" button: one synthetic credit-to-cash redemption at
  Farragut Grind, checked by the real AI, with the real answer shown, even
  if the AI clears it. The two collusion patterns planted in the seed look
  ordinary one redemption at a time; one of them (three people at one
  salon within minutes) isn't visible in the signals the AI gets at all.
  The tests prove that a hold moves no money, not that the AI catches
  those patterns. Catching them needs a check across redemptions, which
  would mean new signals, a new prompt version and a fresh holdout.

### Refunds and corrections: how money mistakes get fixed

Mistakes happen: a wrong amount at a café, a transfer that never reached a
merchant, credits an employer no longer needs. Local Goods fixes them the
way a payments company would
([ADR 0028](docs/adr/0028-refunds-and-corrections.md)). No AI is involved.

**Who asks.** An employee ("Request a correction" on a cleared
redemption), a merchant ("Customer was charged wrongly" on a received
redemption, "I wasn't paid" on a Stripe payout) or the employer's HR
("Refund unspent credits"). They pick what went wrong and may add a note.
Only their own items; at most one open request per item (the database
enforces it); a few a minute. Asking moves no money.

**Who decides.** The Local Goods platform admin, never automatically and
always with a written reason, which the person who asked sees on the item.
The BID admin and judges can look but not decide. A request that can't go
through is rejected with that reason; a Stripe step that failed shows
Stripe's error in plain English and is retried safely or closed.

**What actually happens to the money.**

- *Refund of a redemption:* the credits go back to the employee. If the
  merchant was paid through Stripe, Local Goods reverses exactly that part
  of the merchant's Stripe transfer. If it was simulated (all seeded
  history), it is deducted from what the merchant is owed. Menu items are
  refunded with the tax that was charged on them, from the receipt's own
  snapshot, and never more tax than was charged. A refund waits until the
  merchant's Stripe transfer has gone out; a transfer reversed by hand in
  the Stripe dashboard is sorted out by hand.
- *Merchant adjustment:* a Stripe transfer the merchant is owed but never
  got is sent through the same path as every transfer (and the same gate),
  for exactly the amount the ledger shows.
- *Refund of unspent credits:* only credits not yet given to employees.
  They are held at once, so they can't be handed out meanwhile, and go back
  to the card as Stripe refunds on the original payments, newest payment
  first, never more than is left of each payment and never the platform
  fee. Simulated purchases are returned as simulated.

**The rules underneath.** Whole cents only. The ledger is append-only:
every fix is a new line linked to the original redemption or purchase and
to the request. The approval locks the item and re-checks every earlier
refund on it, so two approvals at the same moment can never refund more
than was paid. Every Stripe call has its own idempotency key, so a retry
can't act twice. The ledger lines for a Stripe refund are written when
Stripe's signed webhook confirms it, and the reconciliation page matches
every reversal and refund against Stripe. A refunded taxed order lowers
the merchant's "Tax collected" in the month the refund completed.

**Not the AI.** Corrections are never sent to Redemption Fraud Detection
and never change a fraud decision. A refund puts credits back in the
employee's balance, which the AI already sees, exactly like an allowance.

**Spending figures are net of refunds.** The BID impact, participation,
employer and landing pages take a completed refund off the dollars spent in
the week and month it completed, and say "net of refunds" where one did.
A refund still processing at Stripe isn't taken off yet; visits and people
counts don't change (the visit happened).

**Known limits.** A payout someone reversed
in the Stripe dashboard is handled by hand, outside Local Goods. The demo
has one completed partial refund and one rejected request, both synthetic.

### How we measure it

We test Redemption Fraud Detection against a person's judgment, not our own
expectations:

1. We wrote 90 synthetic redemptions
   ([`data/fraud_eval_cases.csv`](data/fraud_eval_cases.csv)): clearly normal,
   clearly suspicious, and some genuine close calls.
2. **A person labeled every case** *normal* or *suspicious*, with a short
   reason, **before the AI saw any of them**. The labels were committed to Git
   first, so the history shows they came before the results.
3. **The newest 50 cases (`holdout_3`) are locked.**
   [`data/fraud_eval_lock.json`](data/fraud_eval_lock.json) holds a SHA-256 of
   every row (facts, label and note) and the commit the labels were made in
   (`5f594c1`). It was committed before the AI saw those cases.
   `php artisan fraud:eval` refuses to run if any locked row changes, and a
   test fails in CI if one does.
4. `php artisan fraud:eval` sends each case through the **real production
   check**: the same prompt file, the same model, and the same signal rules
   ([`SignalCalculator::fromFacts`](app/Services/Fraud/SignalCalculator.php)).
   A `hold` counts as suspicious and a `clear` counts as normal.
5. The cases come in groups. We may change the prompt to fix mistakes on the
   20 **dev** cases, but **never** because of a holdout case. `holdout_2` (10)
   and `holdout_3` (50) were labeled after the last prompt change and never
   seen while changing it.
6. We report two numbers, not just agreement. **Catch rate:** of the cases the
   person labeled suspicious, how many the AI held. **Hold precision:** of the
   cases the AI held, how many the person labeled suspicious. Each comes with a
   95% Wilson confidence interval and always says how many suspicious cases it
   was measured on.
7. Every miss and false alarm is grouped by **pattern tags** worked out from
   the case's facts only, never from the label or the AI's answer: round
   amount, 3x or more the usual amount, 90%+ of the balance, a merchant not
   visited in 30 days, outside business hours, 5+ visits in 7 days, above the
   category's typical range, and the merchant category.
8. We publish the real numbers, low or not, with every disagreement shown next
   to the person's reason. Every run is kept with its date, model and prompt
   version (a fingerprint of the prompt file).

**Headline: caught 17 of 26 suspicious cases; 17 of 19 holds were
suspicious.** Measured on the 50 locked `holdout_3` cases (26 labeled
suspicious) with the current prompt `8d5e1e70`. 95% confidence intervals:
catch rate 46–81%, hold precision 69–97%. Overall agreement 78% (39 of 50).

**The weak spot is misses, not false alarms.** The AI cleared 9 of the 26
suspicious cases, mostly ones with a single strong signal (a big jump over the
employee's usual spend, or nearly the whole balance) at normal hours and
inside the category's typical range. 4 of the 9 were at the salon, whose wide
$25–$90 typical range made amounts 2 to 3.5 times the employee's usual look
normal. A cleared redemption goes through without a person looking at it, so
a miss is the costlier mistake. The 2 false alarms are frequent visitors
spending a round amount well above their usual, which the prompt treats as
possible credit-to-cash collusion. A held redemption waits for a person, so a
false alarm costs a delay, not a wrong payout. The full per-pattern table, with
the AI's reasoning for each, is in the
[results](docs/evals/fraud-eval-results.md).

The prompt was frozen for this measurement and will not be changed because of
these results without a fresh set of unseen cases to measure the change.

Every run is kept (model `claude-opus-5-5`). Each cell is agreement, then how
many of the suspicious cases the AI caught:

| Run | Prompt | Dev | Holdout | Holdout 2 | Holdout 3 | Note |
|---|---|---|---|---|---|---|
| 1 | `e5bcf207` (original) | 85% (17/20), caught 6 of 7 | 80% (8/10), caught 3 of 4 | n/a | n/a | First run. Its holdout cases were unseen at the time. |
| 2 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | n/a | n/a | Holdout not fully independent (see below). |
| 3 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | n/a | The earlier headline. Holdout 2 had only 4 suspicious cases. |
| 4 | `8d5e1e70` (change 1) | 90% (18/20), caught 7 of 7 | 90% (9/10), caught 4 of 4 | 80% (8/10), caught 4 of 4 | **78% (39/50), caught 17 of 26** | **Headline.** 50 locked cases, 26 suspicious. |

Run 3's "0 suspicious cases missed" was measured on only 4 suspicious cases.
On 26 suspicious cases, the same prompt missed 9, which is why the headline
moved to `holdout_3`.

Change 1 added one rule to the prompt: using all or nearly all of the balance
at a merchant not visited in the last 30 days is enough to hold on its own. It
was written to fix one dev case (FE-18). **But the holdout results from run 1
had already been seen when it was made**, and holdout case FE-23 is a similar
pattern. So the 90% holdout score in runs 2 to 4 is not a fully independent
test. That is why the `holdout_2` and `holdout_3` cases were added.

Full results: [`docs/evals/fraud-eval-results.md`](docs/evals/fraud-eval-results.md),
also shown on the public `/eval` page. Limits: 50 cases is still a small
sample (the confidence intervals above are wide), the cases are synthetic, and
one person's labels are one opinion. Agreement shows how closely the AI matches
that person, not whether either of them is right about real fraud.

### Checking the person's labels: blind adjudication

One person's labels are one opinion, and where the person and the AI
disagree, the person may be the one who is wrong. So BID staff and contest
judges check the disagreements, blind
([ADR 0011](docs/adr/0011-blind-adjudication-of-eval-disagreements.md)):

- **What they vote on:** every case, in every split, where Finn's label and the
  AI's call disagree, mixed with the same number of randomly chosen cases where
  both agreed, so nobody can tell which cases are disputed. Finn can adjust the
  set before publishing it; after that it is locked.
- **What they see:** only the facts of each redemption, one at a time, in a
  random order of their own. Never Finn's label or note, the AI's decision,
  score or explanation, or anyone else's vote. They choose normal or
  suspicious and write a short reason, and can change their mind until they
  submit the batch.
- **How cases are assigned:** each person gets a batch of 10 cases (about 5
  minutes): 7 disputed and 3 controls while enough are left, never a case they
  have had before, shown in an order of their own. Within each kind, the
  batch takes the cases with the fewest votes so far in that person's group,
  so votes spread evenly. BID staff and contest judges are balanced among
  themselves, and personal contacts separately. A batch counts as soon as it
  is submitted. Then "Thanks! Want to do 10 more?" is optional; it stops
  when they have seen all 32. Cases handed out but never submitted still count
  towards the balance, so an abandoned batch can leave a few cases slightly
  short. Disputed cases get more votes than controls on purpose: they are what
  the check is for. Anyone who pressed Submit all before batches existed keeps
  every vote they made.
- **Who votes:** BID staff and contest judges, each with their own personal
  link; their votes make the result. Personal contacts (friends, family and
  classmates who know Finn) also vote, each with their own link and one vote
  per case, on the same cases and just as blind
  ([ADR 0019](docs/adr/0019-personal-contacts-vote-separately.md)). Because
  they know Finn, their votes are **never counted in the result**: they are
  reported on their own (see below). Their link opens only the voting page and
  the feedback button: no reviewer tour, no "view as", no eval or admin pages.
  The public judge link, reviewers of the "Friend" type and Finn can't vote.
  Reviewers who can't vote see an "Independent check of the AI" card on the
  reviewer home page instead: what the check is, how many BID and
  contest-judge adjudicators have submitted, when voting closes (shown as
  November 13, 2026; Finn ends voting by pressing Close voting) and a link to
  the results on `/eval`.
- **One question first:** "Have you looked at the Local Goods eval results
  page before today?" (personal contacts too). Someone who has may already
  know the answers, so results are shown for everyone and again for only those
  who said no, in each group. While voting is
  open, the eval pages hide the per-case answers from adjudicators who haven't
  submitted. Anyone not signed in can still see them, which is why the question
  is asked.
- **How it's reported:** a separate section on `/eval`, never mixed into the
  headline. "On N disputed cases, adjudicators sided with the AI in X, with
  Finn in Y, split in Z", how many adjudicators voted, how often they agreed
  with the shared label on the control cases (a reliability check), and
  results by split and by pattern. Each number shows how many votes are behind
  it, and each case shows its vote count. A case with fewer than 3 votes in a
  group is marked **"too few votes to call"** and isn't counted as siding with
  anyone. This counts **only BID staff and contest judges.** Below it, a section headed "Personal contacts (know Finn): not
  counted in the result above" gives the same numbers for personal contacts
  alone (their own "sided with the AI / with Finn" tallies, controls and
  "hadn't seen the eval page" view). The two groups are never added together
  in any total. Each case's votes and reasons, without names, appear once
  voting closes. Personal contacts' names never appear on public or reviewer
  pages; the admin pages show only their initials. **Adjudication never
  changes Finn's labels or any headline number.**

### Which merchants are eligible

**The AI is a triage tool: it screens out obvious chains and franchises, routes anything it can't verify to a person, and never approves a merchant on its own.**

Merchant Eligibility Matching suggests whether a real business inside the
Golden Triangle BID is independent under the program's rule. **A person at the
BID makes every decision.** An AI "eligible" is shown to the BID admin as "AI
recommends eligible — confirm before approving", and nothing is approved until
they press Approve. This is enforced in code: a decision can only be saved by
a BID or platform admin (`MerchantReviewDecision`), and a test checks that the
review queue is the only code that writes one and that an eval run writes
none. Today employees can still only redeem at the demo's
fictional merchants: approving a real candidate records a decision and nothing
else ([ADR 0013](docs/adr/0013-merchant-eligibility-matching.md)).

**Real candidates from public data.** `data/merchant_candidates.csv` lists
219 real, consumer-facing businesses inside the BID, built by
`php artisan eligibility:build-candidates` from DC Open Data (the BID boundary
from DC GIS, and active Basic Business Licenses). A business is kept only if
DC's spatial query, the license's own BID field and our own point-in-polygon
check all place it inside the boundary
(`data/sources/merchant_candidates_report.md` has the counts). Each license's
business activity (for example "Restaurant" or "Barber Shop") is in
`data/sources/merchant_candidate_activity.csv`. Owner and agent names are
never requested, and a license whose only name is a person's is left out
(see "Merchant data: public business information only" below).
**None of these businesses has joined Local Goods**, none appears in the
demo's synthetic transactions, and every page that shows one labels it
"Candidate — not a Local Goods participant."

**What goes to Claude: four public facts.** The business's name, address,
category and license business activity, plus the independence rule word for
word ([prompt](resources/prompts/merchant-eligibility.md)). Nothing else. Finn's
labels, notes and sources are in a different file that the check never reads,
and tests check that none of them reaches the AI or the prompt.

**The AI has no web access.** It judges from what it already knows about the
business from its training. That knowledge may be out of date, and it knows
little or nothing about many small local businesses. The prompt tells it to
answer *undetermined* instead of guessing when it doesn't recognize a
business, and never to call a business independent just because it hasn't
heard of other locations. Wherever its reasoning is shown, a fixed notice says
it did not search the web and may not know about recent sales, closures or
new owners.

**What Claude returns.** `eligible` (yes or no), a `classification`
(independent, local chain, franchise, national chain, outside control or
undetermined), a `confidence` from 0 to 1, a few sentences of `reasoning`, and
`needs_human_review`. The app rejects an answer whose `eligible` contradicts
its classification, whose confidence is out of range, or whose reasoning is
blank. *Undetermined*, or a confidence below 0.7, is always flagged for a
person, whatever the AI said.

**It fails closed.** If the API call fails, the model declines, or the answer
is invalid, the business is marked *undetermined* and not eligible, with
confidence 0, and flagged for a person. It is never a guess.

**A person decides.** The BID admin's review queue lists every assessed
candidate with the AI's classification, confidence and reasoning, flagged ones
first. They choose Approve, Reject or Needs research, with an optional note.
Each decision is a new row in an append-only log with who, when and which AI
answer they saw. A change of mind is a new row; nothing is edited or deleted.
Finn's labels are not shown in the queue, so they can't steer the decision.
Judges see the queue read-only.

**How we measure it.** Finn labeled 60 of the candidates by hand in
`data/merchant_labels.csv` (independent, not independent, or undetermined, with
the reason and a public source), split 40 dev and 20 holdout. The labels were
locked in `data/merchant_labels_lock.json` (a SHA-256 per row and labeling
commit `30b610e`) before the AI saw any of them, and CI fails if a labeled row
changes. `php artisan merchant:eval` refuses to run if the labels don't match
the lock, and:

- **Dev and holdout are scored and reported separately, never pooled.** The
  prompt may be changed using dev cases only. The holdout is scored **once
  per prompt version**: a second holdout run with the same prompt is refused,
  and the results list every prompt version that has seen the holdout.
- **The labels are imbalanced** (14 independent, 46 not), so always answering
  "not eligible" already scores well. Next to eligible yes/no agreement, each
  split shows that baseline, **how many of Finn's independent businesses the
  AI found**, and **how many of the AI's "eligible" calls were right**, each as
  X of Y with a 95% Wilson interval.
- Also per split: a confusion matrix, matches by the reason Finn gave
  (franchise, local chain, national chain, outside control), how often the AI
  said undetermined on a business Finn could decide, and every disagreement
  with Finn's note and the AI's reasoning. No business is labeled undetermined,
  so "when Finn said undetermined, did the AI too, or did it guess?" can't be
  measured yet, and the results say so.
- Answers are cached by model, prompt and input, so re-running costs nothing.
  `--dry-run` reports the number of API calls first.

**Results.** The prompt went through one change, made from dev cases only:
answer *independent* only when sure of both local ownership and five or fewer
locations. It was then frozen as `458e8c2d` (CI fails if it changes), and the
holdout was run once with it.

| | Dev (40) | Holdout (20, run once) |
|---|---|---|
| Eligible yes/no agreement | 28 of 40 (70%) | 17 of 20 (85%) |
| Baseline: always "not eligible" | 29 of 40 (72.5%) | 17 of 20 (85%) |
| Independent businesses the AI found | 2 of 11 (18%, 95% CI 5–48%) | 0 of 3 (0%, 0–56%) |
| AI "eligible" calls that were right | 2 of 5 (40%, 12–77%) | none made |
| Chains and franchises correctly called not eligible | 22 of 29 (76%, 58–88%) | 15 of 17 (88%, 66–97%) |
| Chains and franchises wrongly called eligible | 3 of 29 (10%, 4–26%) | 0 of 17 (0%, 0–18%) |
| All cases routed to a person | 32 of 40 (80%) | 14 of 20 (70%) |

Read honestly: **agreement is no better than always saying "not eligible"**,
because the AI rarely confirms that a business is independent. Without web
access it recognized only 2 of 11 independent businesses on dev, and 0 of 3 on
the holdout; most came back *undetermined* and went to a person. What it does
well is screen out chains and franchises: on the holdout it called none of
them eligible. On dev it called 3 small local chains eligible, which is why
an "eligible" is only ever a recommendation for a person to confirm. Many
disagreements are only about the category (for example "franchise" vs
"national chain" for a company-operated store), where both answers mean "not
eligible". Finn notes that some of the fail_reason labels may be inaccurate
(for example, company-operated locations labeled franchise). The labels stay
locked and unchanged.

**Future work: web search.** A vetted web search tool would let the AI check
ownership and location counts instead of relying on memory. That needs a new
prompt version and a fresh set of unseen labeled businesses to measure it.

Full results: [`docs/evals/merchant-eval-results.md`](docs/evals/merchant-eval-results.md)
and `/eval/merchants`.

**Real names stay off public pages.** These are real businesses that never
joined Local Goods. The public `/eval/merchants` page shows totals only, with
no names, notes, sources or per-business tables. The same goes for the shared
"Public judge access" account, whose link is in the README. Named reviewers
and admins see the businesses, each labeled as a candidate. The committed
files in the repo (`data/merchant_candidates.csv`, `data/merchant_labels.csv`,
`data/merchant_assessments.json` and the results under `docs/evals/`) do name
them, because the repo holds the public source data the eval is built from.

### Merchant data: public business information only

The contest rule (Professor Yoest): "public business information only, with
no owner names or personal details." The merchant candidates are real
businesses from public DC records, and nothing about real people is kept.
For each candidate we keep its trade name, premises address, our category,
the license's business activity, a map point and a link to the public DC
record. Nothing else.

**What we never keep.** Owner, registrant or agent names, email addresses or
phone numbers. The build reads each license's legal entity name for one
purpose only, to leave out sole traders whose only name is a person's (16 were
left out), and never writes it to a file. The link to the DC record asks DC
for the trade name, address, business activity and status only.

**Removed on 2026-10-21, after an audit:**

- **Real people named by the AI.** Merchant Eligibility Matching judges from
  its own memory, and in a few answers it named a restaurateur and two chefs,
  and described one café's founders as military veterans. Those words are
  replaced with "[name removed]" (or "local founders") in
  `data/merchant_assessments.json` and every eval output under `docs/evals/`.
  Each edited answer carries a "redacted" note, shown next to the reasoning.
  The classification and confidence are the AI's original answer: no metric
  changed and nothing was re-run. `MerchantOutputPrivacyTest` fails if a
  committed merchant output names an individual again. The prompt is frozen
  and unchanged; telling the AI not to name people would be a new prompt
  version with a fresh holdout.
- **Five candidates whose names are a person's** (a surname as the company
  name, a personal first name, a person's initials). None was labeled or
  assessed. The candidate list went from 224 to 219, and a rebuild skips them
  (`eligibility.excluded_candidates` holds only a hash, not the names).
- **The legal entity name in the DC record link** (for a sole trader that is a
  person's name, shown by DC's site) and **license numbers in the points
  file**.

**Kept on purpose.**

- **Shop names that include a first or family name** ("Henry's Salon",
  family-named jewelers and pharmacies) are the businesses' own public trade
  names, on their signs. Several of the 60 labeled businesses have one. They
  stay exactly as they are: they are business information, the AI was
  measured on these exact names, and changing a labeled row would break the
  label lock and the eval.
- **Finn's own tracking IDs in 8 evidence links.** Eight links in the locked
  label file (`data/merchant_labels.csv`) contain Finn's own Google ad-click
  and search tracking IDs (`gclid`, `gbraid`, `gad_*`, `utm_*`, `srsltid`),
  picked up while researching. They are Finn's data, no one else's. They are
  left in place to keep the label lock intact, and are stripped wherever the
  links are shown: on `/eval/merchants`, in the eval reports and in every eval
  output (`EvidenceUrl::withoutTracking`).

**Earlier versions remain in the public git history.** History was not
rewritten: the merchant label lock and the fraud eval lock point at specific
commits, and rewriting would break them. Commits before 2026-10-21 still
contain the AI's original reasoning, the five candidates, the license numbers
and the old link format.

### How reviewers get in

Judges and other reviewers never create an account or choose a password. Finn
makes each one a personal **magic link**; opening it signs them into a
read-only view of the app. The README also has one **public judge link** to a
shared, generic reviewer account.

- **What a link can do:** look at every screen and leave feedback. It can't
  change data, approve or reject a redemption, or open the admin pages that list
  reviewers' names and emails. It can only ever sign in to a reviewer account.
- **How long it lasts:** until judging closes (November 30, 2026, 11:59 PM
  Eastern), unless Finn switches it off or replaces it sooner. Either way, any
  open session ends on its next click.
- **What we keep:** a fingerprint (hash) of each link, never the link itself,
  plus when each reviewer first and last visited and the feedback they send. The
  link's secret part never reaches our server logs.
- **Attribution:** feedback from a personal link carries that reviewer's name.
  Feedback from the public link is anonymous, labeled "Public judge access",
  and limited to a few notes a minute so one visitor can't flood the inbox.

### Synthetic data

Every person, business and transaction in the demo is fictional. No real
employee, customer or card data is used. The demo has 40 synthetic Meridian
employees and eight weeks of synthetic redemptions at the eight fictional
merchants, including two planted collusion patterns for a future check to find
(described in `database/seeders/DemoHistorySeeder.php`). The real merchant
candidates above are kept out of all of it.

### Impact numbers

The BID impact page and the landing page strip count only cleared
redemptions in the credit ledger, using the same definitions as the employer
dashboard ([ADR 0017](docs/adr/0017-bid-impact-from-the-ledger.md)). Dollars
are net of completed refunds, in the week and month each refund completed
([ADR 0028](docs/adr/0028-refunds-and-corrections.md)). They are demo
numbers, labelled as fictional.

The landing page, the BID impact page and the README say that money spent at
independent local businesses tends to recirculate roughly 3x more in the
local economy than money spent at chains (the local multiplier). That is an
estimate from an outside study, not measured by Local Goods, and it is always
shown with that label and its source: Civic Economics, "Indie Impact Study
Series: National Summary Report" (with the American Booksellers
Association), data from 2011–2012, 134 businesses in 10 U.S. communities,
none in DC. Independents recirculated 52.3% of their revenue locally, chains
15.8%: about 3.3 times as much. Local Goods has not measured it here; real
results would be measured in a pilot. Nothing says Local Goods creates the
effect, and a test fails if the line appears without its label and source
([ADR 0024](docs/adr/0024-mission-framing-and-the-labelled-3x-line.md)).

### Accessibility

Every page was checked with keyboard only, at phone width and with the axe
accessibility checker, and tests check labels, alt text and error wiring on
every page for every role. It has not been tested with a screen reader or on
a real phone. What was checked, what was fixed and what is still a known limit
is in [docs/accessibility.md](docs/accessibility.md).

### Security

A security pass on October 19, 2026. This lists only what was actually
checked or built, and how.

- **No secrets in the code's history.** Every line ever committed, on every
  branch (207 commits that day), was searched for Stripe, Anthropic and AWS
  keys, Laravel app keys, database addresses, private keys and values
  assigned to our secret settings. Nothing real was found; the only matches
  were obvious test placeholders. No key needed rotating. The check is
  [`scripts/scan-history-for-secrets.sh`](scripts/scan-history-for-secrets.sh):
  it never prints what it finds, and CI runs it on the full history (a
  partial copy fails rather than passing).
- **No names on judges' pages.** A test opens every page, and every "view
  as" page, as the shared public judge account, and checks that no
  employee's full name, real candidate business name, reviewer's name or
  account email appears. The same sweep runs for every other role.
- **Production settings.** Before each deploy or settings change, the
  server checks that debug mode is off, the environment is production, the
  login cookie is HTTPS-only and the CloudFront secret is set; if not, the
  deploy stops and the running site is unchanged. If debug mode were ever on
  anyway, production switches it off.
- **Only CloudFront reaches the server.** Requests that skip CloudFront
  (without its secret header) get "Forbidden", except the server's own
  health check ([ADR 0018](docs/adr/0018-only-cloudfront-reaches-beanstalk.md)).
  Built and tested; it is live from the first deploy after this pass.
- **Rate limits**, each with a test that sends one request too many:

  | What | Limit |
  |---|---|
  | Log in | 5 a minute per email and visitor |
  | Other sign-in forms (password change, confirm, reset) | 10 a minute per person or visitor |
  | Magic link sign-in | 10 a minute per visitor |
  | Feedback | public judge: 3 a minute and 20 a day per visitor; others: 10 a minute |
  | Adjudication votes | 60 a minute per judge |
  | Redeem (one Redemption Fraud Detection call each) | 10 a minute per employee |
  | Run a collusion example (one AI call) | 5 a minute |
  | Reset demo | 5 a minute |

  A test also fails any form whose code can reach Claude without a limit.
  Each limit counts on its own, so a busy button can't lock out another.
- **Tests on the money and AI code's refusals**: the transfer gate, ledger
  guards (wrong payment amounts, double recording, Stripe-owed money paid as
  simulated), a balance spent twice at once, and every kind of malformed AI
  reply. These tests found no bugs in the money or AI code.
- **Dependencies:** `composer audit` reported no known vulnerabilities in
  the PHP packages.

**Not done:** no penetration test or outside security review; the JavaScript
packages (npm) were not audited; the history scan only finds
secrets that match its patterns; the server's network rules don't yet block
non-CloudFront traffic (the app refuses it instead); limits are counted on
the one server and start again from zero after each deploy.

## How I built Local Goods with Claude Code

I built Local Goods with [Claude Code](https://claude.com/claude-code), working
in small steps and reviewing every change before committing it.

- **A project brain.** [`CLAUDE.md`](CLAUDE.md) holds the brief that Claude
  Code reads at the start of every session. It covers the customers, the MVP
  scope (one BID, one employer), the exact independence rule, the stack and
  the hard rules: never commit secrets, synthetic data only, small commits,
  tests for business logic.
- **Small, descriptive commits.** Each working change (schema, fraud scorer,
  wallet, admin view, each deploy fix) went in as its own commit.
- **Tests without the real API.** Claude Code wrote feature tests that swap the
  real Claude client for [`FakeClaudeClient`](tests/Fakes/FakeClaudeClient.php).
  The fail-closed rule and the score-70 override are covered by tests that
  never call the API. GitHub Actions runs the tests on every push.
- **Deploying and debugging.** Claude Code helped write the Elastic Beanstalk
  config and deploy hooks, and helped diagnose live issues such as a 500 error
  on first deploy and a SQLite "database is locked" error.
- **Decisions on record.** The main design choices are written up as short
  decision records in [`docs/adr/`](docs/adr/).

I made the product decisions, reviewed the code and tested each change in
the browser. Claude Code did most of the typing.
