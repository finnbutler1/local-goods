<?php

/*
 * The business model's assumptions (ADR 0030): the only place a projection
 * number comes from. App\Support\Projections\BusinessModel reads this file
 * and nothing else; it never reads the live MerchantFee or PlatformFee
 * settings, the ledger or Stripe. Every figure it produces is a projection,
 * not a result, and every page that shows one says so.
 *
 * Each assumption has a low, base and high value. The low/base/high toggle
 * moves only the demand and pricing drivers ('varies' => true); costs stay
 * the same in every case, so revenue, spending and visits only ever go up
 * from low to high. A fact checked against a public page carries its
 * 'source_url'; everything else is labelled with 'assumption_label' and is
 * something customer discovery has to check. Unspent credits are never
 * Local Goods revenue: they stay the employer's money (ADR 0028).
 *
 * After changing a value, run `php artisan projections:docs` to update the
 * README table and docs/finance/MODEL.md (BusinessModelDocsTest fails until
 * you do).
 */

$assumption = 'Assumption: to validate in customer discovery';
$verified = '2026-10-28';

$stripePricing = 'https://stripe.com/pricing';
$connectPricing = 'https://stripe.com/connect/pricing';
$claudePricing = 'https://platform.claude.com/docs/en/about-claude/pricing';
$underReview = 'Under review: in this model the BID partner does the on-the-ground recruiting and is paid a revenue share, so this cost may be lower.';

// One assumption. A fixed one has the same value in all three cases.
$a = fn (string $group, string $label, string $unit, float|int $low, float|int $base, float|int $high, ?string $url, string $source, string $note, bool $public = false) => [
    'group' => $group,
    'label' => $label,
    'unit' => $unit,
    'low' => $low,
    'base' => $base,
    'high' => $high,
    'varies' => $low != $high,
    'source' => $url === null ? $assumption : $source,
    'source_url' => $url,
    'note' => $note,
    'public' => $public,
    'verified_on' => $verified,
];
$fixed = fn (string $group, string $label, string $unit, float|int $value, ?string $url, string $source, string $note) => $a($group, $label, $unit, $value, $value, $value, $url, $source, $note);

return [
    'label' => 'Projection, not results',

    'assumption_label' => $assumption,

    'verified_on' => $verified,

    // The P&L, charts and CSV cover 4 years. The break-even month is looked for up to month 72.
    'horizon_months' => 48,
    'break_even_search_months' => 72,

    'cases' => ['low' => 'Low', 'base' => 'Base', 'high' => 'High'],

    'scenarios' => [
        'a' => [
            'name' => 'Golden Triangle only',
            'short' => 'Golden Triangle',
            'bids_by_year_end' => [1, 1, 1, 1],
            'note' => 'The pilot district alone.',
            'source' => $assumption,
            'source_url' => null,
        ],
        'b' => [
            'name' => 'DC-wide',
            'short' => 'DC-wide',
            'bids_by_year_end' => [2, 6, 10, 12],
            'note' => 'Every DC BID by the end of year 4. DSLBD says "There are currently 12 BIDs in the District of Columbia" but its own list names 13; we use 12.',
            'source' => 'DC Department of Small and Local Business Development, Business Improvement Districts',
            'source_url' => 'https://dslbd.dc.gov/service/business-improvement-districts-bids',
        ],
        'c' => [
            'name' => 'National rollout',
            'short' => 'National',
            'bids_by_year_end' => [4, 24, 60, 100],
            'note' => 'The same shape as the 2013 reference model (4, 24, 60, 100 districts). 100 is about a tenth of the roughly 1,000 U.S. BIDs counted in a 2010 census funded by the International Downtown Association (dated).',
            'source' => 'Business improvement districts in the United States (2010 census figure)',
            'source_url' => 'https://en.wikipedia.org/wiki/Business_improvement_districts_in_the_United_States',
        ],
    ],

    // The BID annual program fee by district size (ADR 0043, ADR 0044). Every district pays from its program
    // start date; no free pilot year. The live charge (App\Support\BidProgramFee) reads its price from this list
    // only: the tier is the last one whose min_businesses the district's member count reaches.
    'bid_fee_tiers' => [
        ['name' => 'Small', 'businesses' => 'under 1,000 member businesses', 'min_businesses' => 0, 'fee' => 1000],
        ['name' => 'Medium', 'businesses' => '1,000 to 3,999 member businesses', 'min_businesses' => 1000, 'fee' => 2500],
        ['name' => 'Large', 'businesses' => '4,000 or more member businesses', 'min_businesses' => 4000, 'fee' => 5000],
    ],

    // Interchange exists only if Local Goods later issues cards through Stripe Issuing. Off: the MVP has no card program.
    'card_issuing' => [
        'enabled' => false,
        'start_month' => 25,
    ],

    'assumptions' => [
        // Demand: who takes part and how much they spend.
        'employers_per_bid' => $a('demand', 'Employers per BID, once enrolled', 'employers', 10, 20, 30, null, '', 'The reference model assumed 20 to 30 employers per BID. Enrolled in a straight line from the BID\'s launch.', true),
        'employer_ramp_months' => $a('demand', 'Months to enroll those employers', 'months', 24, 24, 24, null, '', 'From the month the BID launches.', true),
        'employees_per_employer' => $a('demand', 'Participating employees per employer', 'people', 30, 60, 120, null, '', 'Employees who receive credits each month. The demo employer has 40 enrolled out of 120 staff; major employers in the district are larger.', true),
        'monthly_credit_per_employee' => $a('demand', 'Monthly credit per employee', 'dollars', 25, 50, 100, null, '', 'Set by each employer. The landing page\'s worked example uses $50.', true),
        'utilization' => $a('demand', 'Share of credits spent', 'share', 0.70, 0.85, 0.95, null, '', 'Spent in the month it\'s given. Most of the rest is spent in later months; the share never spent is a separate estimate. None of it is ever Local Goods revenue.', true),
        'average_ticket' => $a('demand', 'Average purchase', 'dollars', 10, 14, 18, null, '', 'A lunch, a coffee or a small retail purchase. Sets the number of visits and AI checks.', true),
        'merchants_per_bid' => $a('demand', 'Participating merchants per BID', 'merchants', 20, 40, 60, 'https://goldentriangledc.com/locate-here/why-golden-triangle', 'Golden Triangle BID: 44 blocks, nearly 6,000 businesses, around 200 restaurants', 'How many of those sign up is an assumption: 40 is about a fifth of the restaurants alone.', true),
        'merchant_ramp_months' => $a('demand', 'Months to sign up those merchants', 'months', 12, 12, 12, null, '', 'From the month the BID launches.', true),

        // Revenue levers. Each is its own line and can be 0.
        // The BID annual program fee (ADR 0043, ADR 0044). Set by district size (bid_fee_tiers), the same tier in every case.
        // Every district pays from its program start: there is no free pilot year (ADR 0044).
        'bid_program_fee_pilot' => $fixed('revenue', 'BID annual program fee, pilot district (Large tier)', 'dollars per BID per year', 5000, 'https://goldentriangledc.com/locate-here/why-golden-triangle', 'Golden Triangle BID: 44 blocks, nearly 6,000 businesses, around 200 restaurants', 'Golden Triangle has nearly 6,000 businesses, so it is in the Large tier. Paid from the month it launches (no free pilot year), spread evenly over each month.'),
        'bid_program_fee_other' => $fixed('revenue', 'BID annual program fee, every other district (Medium tier)', 'dollars per BID per year', 2500, null, '', 'The model has no size for the other districts, so it uses the Medium tier for each of them. Paid from the month the district launches, spread evenly over each month.'),
        'employer_platform_fee' => $a('revenue', 'Employer platform fee', 'dollars per participating employee per month', 4, 6, 10, 'https://www.vendr.com/marketplace/benepass', 'Vendr pricing data for Benepass, a benefits platform: about $4 to $12 per employee per month', 'Third-party estimates; benefit platforms don\'t publish prices. Paid by the employer on top of the credits. The live product\'s employer platform fee uses this base figure (ADR 0042).'),
        'merchant_fee_rate' => $a('revenue', 'Merchant fee', 'share of each sale', 0, 0.015, 0.03, null, '', 'The live merchant fee is 0 (ADR 0029): no fee during the pilot. A small fee per sale would come later, only once the program shows it brings merchants new sales: independent merchants run on thin margins and already pay card processing. The scenario assumes a rate below card processing.'),
        'interchange_rate' => $fixed('revenue', 'Interchange (card-issuing phase only)', 'share of card spend', 0.0135, null, '', 'The 2013 reference model\'s rate. Stripe Issuing\'s interchange share is not public. Counted only when card issuing is turned on, which it is not.'),

        // Cost of revenue.
        'ach_share' => $fixed('stripe', 'Employer purchases paid by ACH', 'share of purchases', 0.80, null, '', 'The rest are paid by card. Large employers usually pay by bank transfer. Today employers pay by card, Apple Pay or Google Pay; ACH is on the roadmap once returned payments are handled.'),
        'stripe_ach_rate' => $fixed('stripe', 'Stripe ACH Direct Debit', 'share of the purchase', 0.008, $stripePricing, 'Stripe pricing: ACH Direct Debit 0.8%, $5.00 cap', 'Paid by Local Goods on each employer purchase.'),
        'stripe_ach_cap' => $fixed('stripe', 'Stripe ACH Direct Debit cap', 'dollars per purchase', 5.00, $stripePricing, 'Stripe pricing: ACH Direct Debit 0.8%, $5.00 cap', 'One purchase per employer per month.'),
        'stripe_card_rate' => $fixed('stripe', 'Stripe card payments, percent', 'share of the purchase', 0.029, $stripePricing, 'Stripe pricing: domestic cards 2.9% + 30¢', 'Paid by Local Goods when an employer pays by card.'),
        'stripe_card_fixed' => $fixed('stripe', 'Stripe card payments, fixed part', 'dollars per purchase', 0.30, $stripePricing, 'Stripe pricing: domestic cards 2.9% + 30¢', ''),
        'connect_active_account' => $fixed('stripe', 'Stripe Connect active account', 'dollars per merchant per month', 2.00, $connectPricing, 'Stripe Connect pricing: $2 per monthly active account', 'Every participating merchant is assumed to receive a payout each month.'),
        'connect_payout_rate' => $fixed('stripe', 'Stripe Connect payout, percent', 'share of each payout', 0.0025, $connectPricing, 'Stripe Connect pricing: 0.25% + 25¢ per payout sent', ''),
        'connect_payout_fixed' => $fixed('stripe', 'Stripe Connect payout, fixed part', 'dollars per payout', 0.25, $connectPricing, 'Stripe Connect pricing: 0.25% + 25¢ per payout sent', ''),
        'payouts_per_merchant_per_month' => $fixed('stripe', 'Payouts per merchant', 'payouts per month', 4.33, null, '', 'Weekly payouts to each merchant\'s bank.'),
        'ai_input_tokens_per_check' => $fixed('ai', 'AI fraud check input', 'tokens per redemption', 1500, null, '', 'Estimated, not measured: the Redemption Fraud Detection prompt is 2,623 bytes, plus the answer format and the anonymous signals.'),
        'ai_output_tokens_per_check' => $fixed('ai', 'AI fraud check output', 'tokens per redemption', 800, null, '', 'Estimated, not measured: the JSON answer plus thinking at effort "low". Usage is not logged yet.'),
        'claude_input_price' => $fixed('ai', 'Claude Opus 5.5 input price', 'dollars per million tokens', 4.00, $claudePricing, 'Anthropic pricing: Claude Opus 5.5, $4 per million input tokens', 'The model the fraud check uses (ANTHROPIC_MODEL default).'),
        'claude_output_price' => $fixed('ai', 'Claude Opus 5.5 output price', 'dollars per million tokens', 20.00, $claudePricing, 'Anthropic pricing: Claude Opus 5.5, $20 per million output tokens', ''),
        'hosting_monthly' => $fixed('operations', 'Hosting (AWS), base', 'dollars per month', 150, null, '', 'Elastic Beanstalk, RDS MySQL and CloudFront. A guess: replace it with the real AWS bill.'),
        'hosting_per_bid_monthly' => $fixed('operations', 'Hosting (AWS), per BID', 'dollars per BID per month', 25, null, '', 'Grows slowly with use.'),
        'bid_revenue_share' => $fixed('partner', 'BID partner revenue share', 'share of the employer platform fee', 0.10, null, '', 'The BID would receive this share (planned; no money goes to a BID today). The reference model paid each BID $0.50 per card; 10% of a $6 fee is $0.60.'),

        // Operating expenses.
        'support_per_employee' => $fixed('operations', 'Customer support', 'dollars per participating employee per month', 0.30, null, '', 'Help desk tools and part-time help.'),
        'marketing_monthly' => $fixed('sales', 'Marketing, ongoing', 'dollars per month', 1000, null, '', 'Website, materials and events.'),
        'bid_launch_cost' => $fixed('sales', 'Cost to launch a BID', 'dollars per new BID', 10000, null, '', $underReview),
        'sales_cost_per_new_employer' => $fixed('sales', 'Sales cost per new employer', 'dollars per new employer', 1500, null, '', $underReview),
        'ga_monthly' => $fixed('g_and_a', 'G&A, base', 'dollars per month', 5000, null, '', 'Accounting, insurance, software and a legal retainer.'),
        'ga_per_bid_monthly' => $fixed('g_and_a', 'G&A per BID', 'dollars per BID per month', 500, null, '', 'Contracts, reporting and compliance per district.'),
        'legal_setup' => $fixed('g_and_a', 'Legal and compliance setup', 'dollars once, in month 1', 25000, null, '', 'A legal review of closed-loop credits and money transmission, and the terms of service.'),
        'founder_salary' => $fixed('payroll', 'Founder salary, loaded', 'dollars per year', 90000, null, '', 'Paid from month 1. The 2013 reference model assumed no payroll at all.'),
        'engineer_salary' => $fixed('payroll', 'Engineer salary, loaded', 'dollars per year', 160000, null, '', 'DC market rate with benefits and taxes.'),
        'first_engineer_at_employees' => $fixed('payroll', 'First engineer hired at', 'participating employees', 2500, null, '', ''),
        'employees_per_extra_engineer' => $fixed('payroll', 'One more engineer per', 'participating employees', 25000, null, '', ''),
        'account_manager_salary' => $fixed('payroll', 'Account manager salary, loaded', 'dollars per year', 110000, null, '', 'Looks after BIDs and their employers.'),
        'account_manager_bids_per_manager' => $fixed('payroll', 'BIDs per account manager', 'BIDs, after the first', 5, null, '', $underReview.' The founder looks after the first BID.'),
        'ops_salary' => $fixed('payroll', 'Operations salary, loaded', 'dollars per year', 85000, null, '', 'Support escalations, fraud review and payouts.'),
        'ops_employees_per_person' => $fixed('payroll', 'One operations person per', 'participating employees', 30000, null, '', ''),
        'compliance_hire_at_bids' => $fixed('payroll', 'Compliance hire at', 'BIDs', 20, null, '', 'One operations person for compliance once the program is in 20 or more districts.'),

        // Customer money: shown separately, never revenue or operating cash.
        'unspent_return_months' => $fixed('customer_funds', 'Unspent credits held before going back to the employer', 'months', 3, null, '', 'Unspent credits are never Local Goods revenue. In the product they don\'t expire: credits an employer hasn\'t given out can be refunded if the employer asks (ADR 0028), and credits given to an employee stay in their wallet. The model\'s return after this many months is a simplification.'),
        'unspent_never_spent_share' => $fixed('customer_funds', 'Credits never spent (estimate)', 'share of credits issued', 0.08, null, '', 'Shown as "Unspent credits (estimate, not counted as revenue)". Capped at the share not spent in the month. Depending on state law, unspent balances may belong to the employer, the employee or the state (unclaimed property), so Local Goods doesn\'t rely on them. Local Goods charges no dormancy fees.'),
        'payout_lag_days' => $fixed('customer_funds', 'Days a cleared sale waits for its payout', 'days', 7, null, '', 'Weekly payouts.'),
    ],

    // A 2013 prepaid-card model shared by a mentor. A guideline only: never copied, and its author is not named.
    'reference' => [
        'description' => 'a 2013 prepaid-card model shared by a mentor',
        'facts' => [
            'Sold prepaid employer cards through BIDs.',
            'Revenue: a $2,500 BID initiation fee, a $5 employer fee per card (about 93% of revenue) and 1.35% interchange on card spend; no advertising.',
            'Growth: 4, 24, 60 and then 100 BIDs over 4 years, with 20 to 30 employers per BID enrolled month by month.',
            'The BID earned $0.50 per card, which made it a paid partner.',
            'Costs: $21,750 to set up with a bank, $10,000 plus $250 a month for the card processor, per-account and identity check fees, $12,000 to $34,000 a month of G&A, and no payroll.',
            'Projected revenue of $182k, $1.18M, $4.55M and $7.36M, and net results of −$112k, +$439k, +$2.76M and +$4.02M.',
        ],
        'lessons' => [
            'The employer fee carries the business. In the reference it was 93% of revenue; here the employer platform fee is the biggest line too, so its price is the first thing to test with HR buyers.',
            'Paying the BID makes it a partner, not just a channel. We keep that as a revenue share of the employer fee.',
            'Stripe Connect replaces the bank and the card processor, so there is no bank setup fee and no processor contract; Stripe charges per account and per payout instead.',
            'Digital credits replace plastic cards: no card production, and no interchange unless a later card-issuing phase is added (off here).',
            'Unspent credits are never revenue. The reference didn\'t rely on them either; we exclude them by policy.',
            'Payroll is real. The reference assumed none, which is a large part of why it showed profit in year 2.',
            'Every redemption has an AI fraud check, a new cost per purchase that the reference didn\'t have. It is small next to Stripe.',
        ],
    ],
];
