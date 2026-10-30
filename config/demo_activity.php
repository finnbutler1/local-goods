<?php

/*
| Live demo activity (ADR 0032). A scheduled generator that makes a small,
| realistic slice of activity each weekday through the normal services:
| real AI fraud checks, real Stripe test-mode payments, transfers and
| refunds. Nothing is backdated; seeded history stays labelled simulated.
|
| Off unless DEMO_ACTIVITY_ENABLED is true. Never set it in a deploy file:
| Finn turns it on in Beanstalk's environment properties and off the same way.
*/

return [
    // The kill switch. False stops the next tick before it does anything.
    'enabled' => (bool) env('DEMO_ACTIVITY_ENABLED', false),

    // It stops by itself after this date (DC time), the end of judging.
    'ends_on' => env('DEMO_ACTIVITY_ENDS_ON', '2026-11-30'),

    // Ticks run every five minutes on weekdays between these DC hours.
    'hours' => ['from' => '07:00', 'until' => '19:00'],

    // At most this many due slots per tick, so a tick stays short.
    'max_slots_per_tick' => 3,

    // Hard limits per day, checked before every action.
    'caps' => [
        'actions_per_day' => 25,
        'spend_cents_per_day' => 40000,
        // Enough for one month's allowances in one purchase, as HR would buy them.
        'purchase_cents_per_day' => 750000,
        'refund_cents_per_day' => 5000,
        // A tick that fails this many actions in a day stops that day.
        'failures_per_day' => 3,
        // Two reconciliation mismatches in a row stop that day.
        'reconciliation_misses' => 2,
    ],

    // Never leave the queue looking neglected: with this many waiting for a
    // person, the generator makes no new redemptions (any could be held) or
    // refund requests that day.
    'queue' => [
        'held_waiting' => 5,
        'refunds_waiting' => 3,
        // Shown plainly as waiting for a person after this many days.
        'overdue_days' => 3,
    ],

    // Redemptions per weekday (ISO 1 = Monday): [fewest, most].
    'redemptions_per_day' => [
        1 => [10, 14], 2 => [10, 14], 3 => [10, 14], 4 => [10, 14], 5 => [6, 9],
    ],

    // When people spend (DC time), weighted to lunch. Lunch peaks in its middle.
    'periods' => [
        'morning' => ['from' => '07:30', 'until' => '10:00', 'weight' => 15, 'merchants' => ['cafe']],
        'lunch' => ['from' => '11:00', 'until' => '14:00', 'weight' => 70, 'merchants' => ['cafe', 'kitchen', 'noodle', 'deli']],
        'afternoon' => ['from' => '14:00', 'until' => '18:00', 'weight' => 15, 'merchants' => ['salon', 'cycle', 'florist', 'books']],
    ],

    // Share of redemptions built from menu items (tax added), else a typed-in amount.
    'itemised_share' => 0.75,

    // Share paid in person with a pay code, else ordered to go.
    'in_person_share' => 0.40,

    // Chance that a day has one redemption planned as unusual on purpose (a
    // round amount far above the café's usual, near the whole balance), so
    // the AI has something real to question. The AI is never told.
    'unusual_day_share' => 0.5,
    'unusual_merchant' => 'cafe',
    'unusual_cents' => [4000, 5000, 6000],

    // About one Recognition a week, one refund request a week (from the
    // Farragut Grind merchant login, on one of its own cleared orders).
    'weekly' => ['recognition' => true, 'refund_request' => true],
    'refund_reasons' => [
        ['wrong_items', 'One item was out of stock but stayed on the order.'],
        ['duplicate', 'Rang up one item twice by mistake.'],
    ],

    // Buy credits through Stripe test mode when the employer's Stripe-paid
    // money not yet used for payouts, or its paid balance, drops below this.
    'top_up' => [
        'below_cents' => 50000,
        'amount_cents' => 150000,
    ],

    // Never picked by the generator: Jordan's wallet is the fixed demo, and the
    // planted collusion patterns must stay exactly as seeded.
    'excluded_employees' => [
        'Jordan Rivera (demo)',
        'Reese Okafor', 'Dana Whitfield', 'Marcus Lindqvist', 'Priya Raman',
    ],
];
