# Local Goods business model

> **Projection, not results.** Every figure here is worked out from stated
> assumptions, each with a public source or labelled "Assumption: to validate
> in customer discovery". Local Goods has not run a pilot. Nothing here is a
> measurement, a forecast we stand behind, or a promise.

This page explains how the model works, lists every assumption, and says what
customer discovery has to check. The numbers are generated from
[`config/projections.php`](../../config/projections.php) by
`php artisan projections:docs`, so they always match the app. Signed-in
reviewers and admins can explore the same model on the **Business model** page
(`/business-model`), with low and high cases and a CSV download. Anyone can see
the district side, with no fee or revenue figures, on the public **Impact
projection** page (`/projection`). The decision record is
[ADR 0030](../adr/0030-business-model-projections.md).

## How the model works

The calculator (`App\Support\Projections\BusinessModel`) is pure arithmetic.
It works month by month for 72 months: the P&L shows the first 48 (4 years),
and the extra months are only used to find the break-even month. It never
reads the live fee settings, the credit ledger or Stripe, and nothing in the
live product reads it.

1. **BIDs launch.** The first BID (the Golden Triangle) launches in month 1.
   Each year's new BIDs are spread evenly across that year (scenarios below).
2. **Employers and merchants enroll.** Each BID enrolls its employers in a
   straight line over 24 months, and its merchants over 12 months.
3. **Spending.** Participating employees = employers × employees per employer.
   Credits bought = employees × monthly credit. Spending at merchants =
   credits × the share spent. Visits (and AI fraud checks) = spending ÷ the
   average purchase.
4. **Revenue** is four separate lines, each of which can be 0:
   - **BID initiation fee:** once, when a BID launches.
   - **Employer platform fee:** per participating employee per month, paid
     by the employer on top of the credits.
   - **Merchant fee:** a share of each sale. The live product's merchant fee
     is 0 (ADR 0029); the scenario assumes a rate.
   - **Interchange:** only in an optional card-issuing phase, which is off.
     The MVP has no card program.
5. **Cost of revenue:**
   - Stripe fees on each employer purchase (ACH or card).
   - Stripe Connect fees per active merchant account and per payout.
   - The Claude API cost of one Redemption Fraud Detection check per
     redemption.
   - Hosting.
   - The BID partner's share of the employer fee.
6. **Operating expenses:**
   - Customer support.
   - Sales and marketing (ongoing, per BID launch and per new employer).
   - G&A with legal and compliance.
   - Payroll: a founder from month 1, then engineers, account managers and
     operations staff hired in steps as the program grows.
7. **EBITDA** = revenue − cost of revenue − operating expenses.
   - **Monthly break-even** is the first month from which monthly EBITDA stays
     above 0, looked for up to month 72.
   - **Peak cash needed** is the deepest point of cumulative EBITDA in those
     72 months: operating losses only.

**Low, base and high cases** change only the demand and pricing drivers
(employers, participation, credit, share spent, average purchase, merchants
and the three fees). Costs stay the same, so revenue, spending and visits only
go up from low to high. EBITDA can dip as a program grows, because payroll
rises in steps.

## Customer money is not revenue

Employers' credits pass through Stripe to merchants. They are **customer funds
held, a promise to pay**:
- employee balances not yet spent;
- cleared sales waiting for their weekly payout.

They are shown on their own and never counted as revenue or operating cash.

**Breakage is excluded from revenue by policy.** Credits employees don't spend
stay the employer's money. The model returns them to the employer after 3
months (ADR 0028 lets an employer ask for unspent credits back). Unspent
credits never earn Local Goods anything; a test checks that revenue doesn't
change with the share spent.

## Base-case results

<!-- projection:base-case:start -->
**Golden Triangle only** (base case)

| | Year 1 | Year 2 | Year 3 | Year 4 |
|---|---|---|---|---|
| BIDs / employers / participating employees (year end) | 1 / 10 / 600 | 1 / 20 / 1,200 | 1 / 20 / 1,200 | 1 / 20 / 1,200 |
| Revenue | $28k | $74k | $96k | $96k |
| of which employer platform fees | $23k | $67k | $86k | $86k |
| of which merchant fees | $2k | $7k | $9k | $9k |
| of which BID initiation fees | $3k | $0 | $0 | $0 |
| Cost of revenue | $7k | $16k | $20k | $20k |
| Gross profit | $21k | $57k | $75k | $75k |
| Operating expenses | $219k | $186k | $172k | $172k |
| of which payroll | $90k | $90k | $90k | $90k |
| EBITDA | −$198k | −$129k | −$97k | −$97k |
| Spent at independent merchants | $166k | $472k | $612k | $612k |
| Visits | 11,839 | 33,696 | 43,714 | 43,714 |
| Customer funds held at year end (not revenue) | $18k | $38k | $39k | $39k |
| Gross margin | 74% | 78% | 79% | 79% |

**DC-wide** (base case)

| | Year 1 | Year 2 | Year 3 | Year 4 |
|---|---|---|---|---|
| BIDs / employers / participating employees (year end) | 2 / 11 / 650 | 6 / 49 / 2,950 | 10 / 117 / 7,000 | 12 / 185 / 11,100 |
| Revenue | $31k | $145k | $405k | $748k |
| of which employer platform fees | $24k | $122k | $357k | $672k |
| of which merchant fees | $3k | $13k | $38k | $71k |
| of which BID initiation fees | $5k | $10k | $10k | $5k |
| Cost of revenue | $8k | $30k | $82k | $149k |
| Gross profit | $24k | $115k | $323k | $599k |
| Operating expenses | $240k | $425k | $730k | $771k |
| of which payroll | $99k | $227k | $452k | $479k |
| EBITDA | −$216k | −$310k | −$407k | −$173k |
| Spent at independent merchants | $168k | $863k | $2.53M | $4.76M |
| Visits | 11,991 | 61,625 | 180,777 | 339,848 |
| Customer funds held at year end (not revenue) | $19k | $89k | $218k | $353k |
| Gross margin | 76% | 79% | 80% | 80% |

**National rollout** (base case)

| | Year 1 | Year 2 | Year 3 | Year 4 |
|---|---|---|---|---|
| BIDs / employers / participating employees (year end) | 4 / 23 / 1,350 | 24 / 168 / 10,050 | 60 / 580 / 34,800 | 100 / 1,248 / 74,900 |
| Revenue | $56k | $453k | $1.83M | $4.52M |
| of which employer platform fees | $42k | $364k | $1.57M | $4.00M |
| of which merchant fees | $4k | $39k | $167k | $425k |
| of which BID initiation fees | $10k | $50k | $90k | $100k |
| Cost of revenue | $12k | $88k | $362k | $896k |
| Gross profit | $44k | $365k | $1.47M | $3.63M |
| Operating expenses | $359k | $1.17M | $2.76M | $4.67M |
| of which payroll | $173k | $580k | $1.37M | $2.51M |
| EBITDA | −$315k | −$809k | −$1.29M | −$1.04M |
| Spent at independent merchants | $295k | $2.58M | $11.13M | $28.32M |
| Visits | 21,098 | 184,268 | 794,902 | 2,023,000 |
| Customer funds held at year end (not revenue) | $40k | $300k | $1.07M | $2.34M |
| Gross margin | 79% | 81% | 80% | 80% |
<!-- projection:base-case:end -->

## Break-even and peak cash, every scenario and case

<!-- projection:break-even:start -->
| Scenario | Case | Monthly break-even | Peak cash needed |
|---|---|---|---|
| Golden Triangle only | low | not within 72 months | $1.02M (still growing at month 72) |
| Golden Triangle only | base | not within 72 months | $716k (still growing at month 72) |
| Golden Triangle only | high | month 19 | $104k (month 10) |
| DC-wide | low | not within 72 months | $2.84M (still growing at month 72) |
| DC-wide | base | month 56 | $1.14M (month 55) |
| DC-wide | high | month 19 | $165k (month 18) |
| National rollout | low | not within 72 months | $12.10M (still growing at month 72) |
| National rollout | base | month 50 | $3.48M (month 49) |
| National rollout | high | month 16 | $208k (month 15) |
<!-- projection:break-even:end -->

## What it takes

One Golden Triangle BID on its own (scenario A), fully enrolled, in an ordinary
month with no launches or new employers. The table shows two things:
- the fewest participating employees (and employers, at the case's employees
  per employer) for monthly EBITDA to reach 0 at the case's fees;
- the employer platform fee that reaches 0 at the case's own participation.

Both are searched rather than solved, because payroll rises in steps. The
first engineer is hired at 2,500 participating employees, so needing just over
that many means paying for an engineer too.

<!-- projection:what-it-takes:start -->
| Case | Employer fee now | Participating employees needed | Employers needed | Employer fee that breaks even | At participating employees |
|---|---|---|---|---|---|
| low | $4 | 9,461 | 316 at 30 each | $53.82 | 300 |
| base | $6 | 5,341 | 90 at 60 each | $13.54 | 1,200 |
| high | $10 | 1,364 | 12 at 120 each | $6.83 | 3,600 |
<!-- projection:what-it-takes:end -->

**What this says, at the assumptions checked on 2026-10-28:**
- The Golden Triangle alone is a pilot, not a business.
- The model works only at DC-wide scale or larger, and mostly on the employer
  fee.
- An employer fee above the low end of the benefit-platform range, or more
  participating employees per employer, moves break-even forward a lot.

That is what customer discovery has to test first.

## Assumptions

Every assumption, with its low, base and high value and its source. "Assumption:
to validate in customer discovery" means no public source; it is a judgement to
check with real employers, BIDs and merchants.

<!-- projection:assumptions:start -->
| Key | Assumption | Unit | Low | Base | High | Source |
|---|---|---|---|---|---|---|
| `employers_per_bid` | Employers per BID, once enrolled | employers | 10 | 20 | 30 | Assumption: to validate in customer discovery. The reference model assumed 20 to 30 employers per BID. Enrolled in a straight line from the BID's launch. Checked 2026-10-28. |
| `employer_ramp_months` | Months to enroll those employers | months | 24 | 24 | 24 | Assumption: to validate in customer discovery. From the month the BID launches. Checked 2026-10-28. |
| `employees_per_employer` | Participating employees per employer | people | 30 | 60 | 120 | Assumption: to validate in customer discovery. Employees who receive credits each month. The demo employer has 40 enrolled out of 120 staff; major employers in the district are larger. Checked 2026-10-28. |
| `monthly_credit_per_employee` | Monthly credit per employee | dollars | $25 | $50 | $100 | Assumption: to validate in customer discovery. Set by each employer. The landing page's worked example uses $50. Checked 2026-10-28. |
| `utilization` | Share of credits spent | share | 70% | 85% | 95% | Assumption: to validate in customer discovery. The rest stays the employer's money and is returned or rolled over. It is never Local Goods revenue. Checked 2026-10-28. |
| `average_ticket` | Average purchase | dollars | $10 | $14 | $18 | Assumption: to validate in customer discovery. A lunch, a coffee or a small retail purchase. Sets the number of visits and AI checks. Checked 2026-10-28. |
| `merchants_per_bid` | Participating merchants per BID | merchants | 20 | 40 | 60 | [Golden Triangle BID: 44 blocks, nearly 6,000 businesses, around 200 restaurants](https://goldentriangledc.com/locate-here/why-golden-triangle). How many of those sign up is an assumption: 40 is about a fifth of the restaurants alone. Checked 2026-10-28. |
| `merchant_ramp_months` | Months to sign up those merchants | months | 12 | 12 | 12 | Assumption: to validate in customer discovery. From the month the BID launches. Checked 2026-10-28. |
| `bid_initiation_fee` | BID initiation fee | dollars once per BID | $0 | $2,500 | $5,000 | Assumption: to validate in customer discovery. The reference model charged each BID $2,500 to start. Checked 2026-10-28. |
| `employer_platform_fee` | Employer platform fee | dollars per participating employee per month | $4 | $6 | $10 | [Vendr pricing data for Benepass, a benefits platform: about $4 to $12 per employee per month](https://www.vendr.com/marketplace/benepass). Third-party estimates; benefit platforms don't publish prices. Paid by the employer on top of the credits. The live product's platform fee setting is a percent and is off (ADR 0021); this is a model lever only. Checked 2026-10-28. |
| `merchant_fee_rate` | Merchant fee | share of each sale | 0% | 1.5% | 3% | Assumption: to validate in customer discovery. The live merchant fee is 0 (ADR 0029). The scenario assumes a rate below card processing. Checked 2026-10-28. |
| `interchange_rate` | Interchange (card-issuing phase only) | share of card spend | 1.35% | 1.35% | 1.35% | Assumption: to validate in customer discovery. The 2013 reference model's rate. Stripe Issuing's interchange share is not public. Counted only when card issuing is turned on, which it is not. Checked 2026-10-28. |
| `ach_share` | Employer purchases paid by ACH | share of purchases | 80% | 80% | 80% | Assumption: to validate in customer discovery. The rest are paid by card. Large employers usually pay by bank transfer. Checked 2026-10-28. |
| `stripe_ach_rate` | Stripe ACH Direct Debit | share of the purchase | 0.8% | 0.8% | 0.8% | [Stripe pricing: ACH Direct Debit 0.8%, $5.00 cap](https://stripe.com/pricing). Paid by Local Goods on each employer purchase. Checked 2026-10-28. |
| `stripe_ach_cap` | Stripe ACH Direct Debit cap | dollars per purchase | $5 | $5 | $5 | [Stripe pricing: ACH Direct Debit 0.8%, $5.00 cap](https://stripe.com/pricing). One purchase per employer per month. Checked 2026-10-28. |
| `stripe_card_rate` | Stripe card payments, percent | share of the purchase | 2.9% | 2.9% | 2.9% | [Stripe pricing: domestic cards 2.9% + 30¢](https://stripe.com/pricing). Paid by Local Goods when an employer pays by card. Checked 2026-10-28. |
| `stripe_card_fixed` | Stripe card payments, fixed part | dollars per purchase | $0.30 | $0.30 | $0.30 | [Stripe pricing: domestic cards 2.9% + 30¢](https://stripe.com/pricing). Checked 2026-10-28. |
| `connect_active_account` | Stripe Connect active account | dollars per merchant per month | $2 | $2 | $2 | [Stripe Connect pricing: $2 per monthly active account](https://stripe.com/connect/pricing). Every participating merchant is assumed to receive a payout each month. Checked 2026-10-28. |
| `connect_payout_rate` | Stripe Connect payout, percent | share of each payout | 0.25% | 0.25% | 0.25% | [Stripe Connect pricing: 0.25% + 25¢ per payout sent](https://stripe.com/connect/pricing). Checked 2026-10-28. |
| `connect_payout_fixed` | Stripe Connect payout, fixed part | dollars per payout | $0.25 | $0.25 | $0.25 | [Stripe Connect pricing: 0.25% + 25¢ per payout sent](https://stripe.com/connect/pricing). Checked 2026-10-28. |
| `payouts_per_merchant_per_month` | Payouts per merchant | payouts per month | 4.33 | 4.33 | 4.33 | Assumption: to validate in customer discovery. Weekly payouts to each merchant's bank. Checked 2026-10-28. |
| `ai_input_tokens_per_check` | AI fraud check input | tokens per redemption | 1,500 | 1,500 | 1,500 | Assumption: to validate in customer discovery. Estimated, not measured: the Redemption Fraud Detection prompt is 2,623 bytes, plus the answer format and the anonymous signals. Checked 2026-10-28. |
| `ai_output_tokens_per_check` | AI fraud check output | tokens per redemption | 800 | 800 | 800 | Assumption: to validate in customer discovery. Estimated, not measured: the JSON answer plus thinking at effort "low". Usage is not logged yet. Checked 2026-10-28. |
| `claude_input_price` | Claude Opus 5.5 input price | dollars per million tokens | $4 | $4 | $4 | [Anthropic pricing: Claude Opus 5.5, $4 per million input tokens](https://platform.claude.com/docs/en/about-claude/pricing). The model the fraud check uses (ANTHROPIC_MODEL default). Checked 2026-10-28. |
| `claude_output_price` | Claude Opus 5.5 output price | dollars per million tokens | $20 | $20 | $20 | [Anthropic pricing: Claude Opus 5.5, $20 per million output tokens](https://platform.claude.com/docs/en/about-claude/pricing). Checked 2026-10-28. |
| `hosting_monthly` | Hosting (AWS), base | dollars per month | $150 | $150 | $150 | Assumption: to validate in customer discovery. Elastic Beanstalk, RDS MySQL and CloudFront. A guess: replace it with the real AWS bill. Checked 2026-10-28. |
| `hosting_per_bid_monthly` | Hosting (AWS), per BID | dollars per BID per month | $25 | $25 | $25 | Assumption: to validate in customer discovery. Grows slowly with use. Checked 2026-10-28. |
| `bid_revenue_share` | BID partner revenue share | share of the employer platform fee | 10% | 10% | 10% | Assumption: to validate in customer discovery. Makes the BID a paid partner. The reference model paid each BID $0.50 per card; 10% of a $6 fee is $0.60. Checked 2026-10-28. |
| `support_per_employee` | Customer support | dollars per participating employee per month | $0.30 | $0.30 | $0.30 | Assumption: to validate in customer discovery. Help desk tools and part-time help. Checked 2026-10-28. |
| `marketing_monthly` | Marketing, ongoing | dollars per month | $1,000 | $1,000 | $1,000 | Assumption: to validate in customer discovery. Website, materials and events. Checked 2026-10-28. |
| `bid_launch_cost` | Cost to launch a BID | dollars per new BID | $10,000 | $10,000 | $10,000 | Assumption: to validate in customer discovery. Under review: in this model the BID partner does the on-the-ground recruiting and is paid a revenue share, so this cost may be lower. Checked 2026-10-28. |
| `sales_cost_per_new_employer` | Sales cost per new employer | dollars per new employer | $1,500 | $1,500 | $1,500 | Assumption: to validate in customer discovery. Under review: in this model the BID partner does the on-the-ground recruiting and is paid a revenue share, so this cost may be lower. Checked 2026-10-28. |
| `ga_monthly` | G&A, base | dollars per month | $5,000 | $5,000 | $5,000 | Assumption: to validate in customer discovery. Accounting, insurance, software and a legal retainer. Checked 2026-10-28. |
| `ga_per_bid_monthly` | G&A per BID | dollars per BID per month | $500 | $500 | $500 | Assumption: to validate in customer discovery. Contracts, reporting and compliance per district. Checked 2026-10-28. |
| `legal_setup` | Legal and compliance setup | dollars once, in month 1 | $25,000 | $25,000 | $25,000 | Assumption: to validate in customer discovery. A legal review of closed-loop credits and money transmission, and the terms of service. Checked 2026-10-28. |
| `founder_salary` | Founder salary, loaded | dollars per year | $90,000 | $90,000 | $90,000 | Assumption: to validate in customer discovery. Paid from month 1. The 2013 reference model assumed no payroll at all. Checked 2026-10-28. |
| `engineer_salary` | Engineer salary, loaded | dollars per year | $160,000 | $160,000 | $160,000 | Assumption: to validate in customer discovery. DC market rate with benefits and taxes. Checked 2026-10-28. |
| `first_engineer_at_employees` | First engineer hired at | participating employees | 2,500 | 2,500 | 2,500 | Assumption: to validate in customer discovery. Checked 2026-10-28. |
| `employees_per_extra_engineer` | One more engineer per | participating employees | 25,000 | 25,000 | 25,000 | Assumption: to validate in customer discovery. Checked 2026-10-28. |
| `account_manager_salary` | Account manager salary, loaded | dollars per year | $110,000 | $110,000 | $110,000 | Assumption: to validate in customer discovery. Looks after BIDs and their employers. Checked 2026-10-28. |
| `account_manager_bids_per_manager` | BIDs per account manager | BIDs, after the first | 5 | 5 | 5 | Assumption: to validate in customer discovery. Under review: in this model the BID partner does the on-the-ground recruiting and is paid a revenue share, so this cost may be lower. The founder looks after the first BID. Checked 2026-10-28. |
| `ops_salary` | Operations salary, loaded | dollars per year | $85,000 | $85,000 | $85,000 | Assumption: to validate in customer discovery. Support escalations, fraud review and payouts. Checked 2026-10-28. |
| `ops_employees_per_person` | One operations person per | participating employees | 30,000 | 30,000 | 30,000 | Assumption: to validate in customer discovery. Checked 2026-10-28. |
| `compliance_hire_at_bids` | Compliance hire at | BIDs | 20 | 20 | 20 | Assumption: to validate in customer discovery. One operations person for compliance once the program is in 20 or more districts. Checked 2026-10-28. |
| `unspent_return_months` | Unspent credits held before going back to the employer | months | 3 | 3 | 3 | Assumption: to validate in customer discovery. Unspent credits are never Local Goods revenue. They stay the employer's money and can be refunded (ADR 0028). Checked 2026-10-28. |
| `payout_lag_days` | Days a cleared sale waits for its payout | days | 7 | 7 | 7 | Assumption: to validate in customer discovery. Weekly payouts. Checked 2026-10-28. |
<!-- projection:assumptions:end -->

## Scenarios

<!-- projection:scenarios:start -->
| Scenario | BIDs at the end of years 1–4 | Source |
|---|---|---|
| Golden Triangle only | 1, 1, 1, 1 | Assumption: to validate in customer discovery. The pilot district alone. |
| DC-wide | 2, 6, 10, 12 | [DC Department of Small and Local Business Development, Business Improvement Districts](https://dslbd.dc.gov/service/business-improvement-districts-bids). Every DC BID by the end of year 4. DSLBD says "There are currently 12 BIDs in the District of Columbia" but its own list names 13; we use 12. |
| National rollout | 4, 24, 60, 100 | [Business improvement districts in the United States (2010 census figure)](https://en.wikipedia.org/wiki/Business_improvement_districts_in_the_United_States). The same shape as the 2013 reference model (4, 24, 60, 100 districts). 100 is about a tenth of the roughly 1,000 U.S. BIDs counted in a 2010 census funded by the International Downtown Association (dated). |
<!-- projection:scenarios:end -->

## Under review

Three go-to-market costs are under review with an outside adviser:
- `account_manager_bids_per_manager` (one account manager per 5 BIDs after
  the first);
- `sales_cost_per_new_employer` ($1,500);
- `bid_launch_cost` ($10,000).

In this model the BID partner does the on-the-ground recruiting and is paid a
revenue share, so these may be lower. Each is one key in
`config/projections.php`. Change it there, run `php artisan projections:docs`,
and the README, this page, the Business model page and the CSV all follow
(`BusinessModelDocsTest` fails until the docs are regenerated).

## Differences from the reference model

The model was shaped with a guideline:
a 2013 prepaid-card model shared by a mentor.
It sold prepaid employer cards through BIDs, charging the following:
- a $2,500 BID initiation fee;
- a $5 employer fee per card, about 93% of its revenue;
- 1.35% interchange.

It grew from 4 to 100 BIDs over 4 years and projected net results of −$112k,
+$439k, +$2.76M and +$4.02M. Nothing was copied. What changes here:

- **Digital credits, not plastic cards.** No card production, and no
  interchange unless a later card-issuing phase is added (off here).
- **Stripe Connect replaces the bank and the processor.** There is no bank
  setup fee ($21,750 in the reference) and no processor contract ($10,000
  plus $250 a month). Stripe charges per active account and per payout
  instead.
- **An AI cost per redemption.** Every redemption gets a Redemption Fraud
  Detection check. It is small next to Stripe, but it is new.
- **Breakage is never revenue.** Unspent credits stay the employer's money.
- **Real payroll.** The reference assumed no payroll at all, which is a large
  part of why it showed profit in year 2. This model pays a founder from
  month 1 and hires as the program grows.
- **The BID stays a paid partner,** as in the reference ($0.50 per card).
  Here it gets a share of the employer fee (10% of $6 is $0.60).

## What customer discovery must validate

In order of how much they move the result:

1. **The employer platform fee:** will HR buyers pay about $6 per
   participating employee per month on top of the credits? The
   benefit-platform range ($4 to $12) comes from third-party estimates, not
   from published prices.
2. **Participation:** how many employees per employer take part, and how
   many employers per BID enroll, and how fast.
3. **Monthly credit and share spent:** what employers fund, and how much
   employees actually spend.
4. **The BID partnership:** whether BIDs will recruit employers for a revenue
   share, and what launching a BID really costs.
5. **The merchant fee:** whether merchants accept a fee below card processing
   (1.5% assumed; 0 today).
6. **Costs to replace with real numbers:**
   - the AWS bill (hosting is a guess);
   - measured AI tokens per fraud check (estimated from the prompt's size);
   - support load.
7. **Facts to recheck:**
   - DC's BID count (DSLBD says 12 but lists 13);
   - Stripe's and Anthropic's prices before any real decision.
