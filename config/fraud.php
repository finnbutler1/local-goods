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

    // Latest results written by `php artisan fraud:eval`, shown at /demo/bid-admin/eval.
    'eval_latest_path' => base_path('docs/evals/fraud-eval-latest.json'),

    // The headline score: one split of one run. holdout_2 in run 3 was
    // labeled after the last prompt change and never seen while changing it.
    // Update the note whenever the headline run changes.
    'eval_headline' => [
        'run' => '2026-10-11-claude-opus-5-5-prompt-8d5e1e70-cases-3b40d7c8',
        'split' => 'holdout_2',
        'note' => 'Every disagreement is a false alarm on the same pattern (a frequent customer spending a round amount well above their usual), which the prompt treats as possible credit-to-cash collusion. A held redemption is never approved automatically: it waits under review for a person, so a false alarm costs a delay, not a wrong payout.',
    ],
];
