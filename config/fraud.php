<?php

// Inputs for Redemption Fraud Detection. These are the PHP-side rules that
// turn a redemption into signals; the AI only ever sees the signals.
return [

    'timezone' => 'America/New_York',

    // Outside these local hours a redemption is flagged. Opening is inclusive, closing exclusive.
    'business_hours' => ['open' => 7, 'close' => 22],

    // A whole multiple of this many cents, at or above round_number_min_cents, counts as "round".
    'round_number_step_cents' => 1000,
    'round_number_min_cents' => 2000,

    // A redemption using at least this share of the balance counts as "full balance".
    'full_balance_ratio' => 0.95,

    // If the AI says "clear" but scores at or above this, we hold anyway.
    'hold_if_score_at_least' => 70,

    // Typical ticket size per merchant category, in cents.
    'category_ticket_ranges' => [
        'cafe' => [300, 1500],
        'restaurant' => [1000, 4500],
        'retail' => [1000, 8000],
        'salon' => [2500, 9000],
        'fitness' => [2000, 4000],
    ],

    // SHA-256 of every holdout_3 row and the labeling commit, written once by
    // `php artisan fraud:lock`. fraud:eval refuses to run if a locked row changed.
    'eval_lock_path' => base_path('data/fraud_eval_lock.json'),

    // Public repo, so /eval can link the lock's labeling commit.
    'eval_repo_url' => 'https://github.com/finnbutler1/local-goods',

    // The hand-labeled eval cases. Blind adjudication (ADR 0011) shows their facts.
    'eval_cases_path' => base_path('data/fraud_eval_cases.csv'),

    // Latest results written by `php artisan fraud:eval`, shown at /demo/bid-admin/eval.
    'eval_latest_path' => base_path('docs/evals/fraud-eval-latest.json'),

    // The headline: catch rate and hold precision on one split of one run.
    // holdout_3 in run 4 was labeled and locked (data/fraud_eval_lock.json)
    // after the last prompt change and before the AI saw it. Update the note
    // and pattern_notes whenever the headline run changes. pattern_notes is
    // written from the AI's explanations on that run's disagreements.
    'eval_headline' => [
        'run' => '2026-10-14-claude-opus-5-5-prompt-8d5e1e70-cases-33b70229',
        'split' => 'holdout_3',
        'note' => 'The AI misses more often than it raises false alarms. It cleared 9 of the 26 suspicious cases, mostly ones with a single strong signal (a big jump over the employee\'s usual spend, or nearly the whole balance) at normal hours and inside the category\'s typical range; 4 of the 9 were at the salon. A cleared redemption goes through without a person looking at it, so a miss is the costlier mistake. Holds are reliable: 17 of 19 were suspicious. The 2 false alarms are frequent visitors spending a round amount well above their usual, which the prompt treats as possible credit-to-cash collusion; a held redemption waits for a person, so a false alarm costs a delay, not a wrong payout.',
        'pattern_notes' => [
            'round_amount' => 'A round amount is one weak signal to the AI: it cleared round amounts inside the category\'s normal range (FE-58, FE-89) or on part of the balance mid-afternoon (FE-86), and held one from a very frequent visitor (FE-75).',
            'triple_median' => 'The AI called a big jump over usual spend "worth watching" but not enough alone when the amount was not round, used part of the balance and came in business hours (FE-45, FE-86, FE-89); with very frequent visits it held (FE-62, FE-75).',
            'most_of_balance' => 'The AI let one recent visit to the merchant and a normal-for-the-category amount outweigh using nearly the whole balance (FE-58, FE-71).',
            'new_merchant' => 'The AI read "no repeat visits" as reassuring, because the collusion pattern it looks for is repeat visits, so a first visit with a big amount was cleared (FE-45, FE-86).',
            'outside_hours' => 'The one false alarm (FE-62) was a 6:40 AM coffee purchase; the early hour added to the frequent-visitor signal.',
            'frequent_visitor' => 'Both false alarms (FE-62, FE-75): the AI reads many visits in a week plus a much larger round amount as possible collusion, where the person saw a loyal regular. Same pattern as the earlier holdout_2 false alarms.',
            'above_category' => 'Slightly above the range but not round and during the day, so the AI saw one signal and cleared it (FE-45, FE-56, FE-86); with frequent early visits it held (FE-62).',
            'salon' => '4 of 6 suspicious salon cases were missed (FE-50, FE-58, FE-74, FE-89): the salon\'s wide $25–$90 typical range made amounts 2–3.5 times the employee\'s usual look normal to the AI.',
            'cafe' => 'Missed FE-86 (a first visit at 4 times the usual spend, at 3 PM); false alarm FE-62 (a regular at 6:40 AM spending 4 times the usual).',
            'restaurant' => 'Missed FE-56 and FE-80 (about 2.5–2.8 times the usual at dinner time, read as a larger dinner); false alarm FE-75 (a very frequent visitor, round $40).',
            'retail' => 'Missed FE-45 (6.5 times the usual but under half the balance, at 1 PM) and FE-71 (nearly the whole $50 balance, but a recent visit and a normal retail amount).',
        ],
    ],
];
