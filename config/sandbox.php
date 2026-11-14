<?php

/*
| "Try it yourself" sandboxes (ADR 0045). Each visitor gets a private copy of
| the demo as its own SQLite file, made from a template the demo seeder built.
| Money moves through the real ledger, solvency and fraud code inside that
| copy only; Stripe is never contacted and the live database is never written.
|
| Off unless SANDBOX_ENABLED is exactly "true". With it off there is no
| button and every /sandbox address is a 404. Also off when PHP has no SQLite
| driver (App\Support\Sandbox::enabled()).
*/

return [
    'enabled' => env('SANDBOX_ENABLED') === true || env('SANDBOX_ENABLED') === 'true',

    // Never under public/. Templates and sandboxes live side by side here.
    'path' => env('SANDBOX_PATH') ?: storage_path('app/sandboxes'),

    // How long one sandbox lives, in hours, from when it was made.
    'lifetime_hours' => 24,

    // At most this many sandboxes at once (each about the size of the template).
    'max_live' => 50,

    // New sandboxes per visitor IP per hour (judges may share one Wi-Fi).
    'creations_per_hour' => 10,

    // Redemption Fraud Detection calls to Claude. Past either cap the AI is not
    // called and the existing fail-closed path holds the payment for review.
    'ai_calls_per_sandbox' => 10,
    'ai_calls_per_day' => 100,

    // Real Stripe Checkout for the employer's Buy credits, inside a sandbox only,
    // on a SEPARATE Stripe test account (ADR 0045 addendum). Off unless
    // SANDBOX_STRIPE_ENABLED is exactly "true" and SANDBOX_STRIPE_SECRET is an
    // sk_test_ key; anything else = simulated as before. Only
    // App\Services\Sandbox\SandboxStripe reads the key, and only while a
    // sandbox is active. Never set either in a deploy file or seeder.
    'stripe' => [
        'enabled' => env('SANDBOX_STRIPE_ENABLED') === true || env('SANDBOX_STRIPE_ENABLED') === 'true',
        'secret' => env('SANDBOX_STRIPE_SECRET'),
        // Checkout Sessions one sandbox may open; after that, Buy credits is simulated.
        'checkouts_per_sandbox' => 10,
    ],

    // Sandbox logins: one per role, no password anyone knows. ".invalid" is
    // reserved (RFC 2606): it can never receive mail. Sandbox pages offer no
    // password reset or any other mail anyway.
    'email_domain' => 'sandbox.invalid',
];
