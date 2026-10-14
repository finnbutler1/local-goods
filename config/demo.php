<?php

/*
| One demo account per role, created by `php artisan demo:seed-accounts`.
| Names are synthetic. Passwords come only from the environment (.env locally,
| Beanstalk environment properties in production): an account whose password
| is blank is skipped, never given a made-up one.
|
| The judge account is the shared "Public judge access" reviewer linked from
| the README. Reviewers never have a password: it signs in only with the
| magic link the platform admin makes on /admin/reviewers.
*/

return [
    'accounts' => [
        'platform_admin' => [
            'name' => env('PLATFORM_ADMIN_NAME') ?: 'Riley Chen',
            'email' => env('PLATFORM_ADMIN_EMAIL') ?: 'riley.chen@example.com',
            'password' => env('DEMO_PASSWORD_PLATFORM_ADMIN'),
        ],
        'bid_admin' => [
            'name' => 'Sam Taylor',
            'email' => 'sam.taylor@example.com',
            'password' => env('DEMO_PASSWORD_BID_ADMIN'),
        ],
        'employer_admin' => [
            'name' => 'Alex Morgan',
            'email' => 'alex.morgan@example.com',
            'password' => env('DEMO_PASSWORD_EMPLOYER_ADMIN'),
        ],
        'employee' => [
            'name' => 'Jordan Rivera',
            'email' => 'jordan.rivera@example.com',
            'password' => env('DEMO_PASSWORD_EMPLOYEE'),
        ],
        'merchant' => [
            'name' => 'Priya Natarajan',
            'email' => 'priya.natarajan@example.com',
            'password' => env('DEMO_PASSWORD_MERCHANT'),
        ],
        'judge' => [
            'name' => 'Public judge access',
            'email' => 'public.judge@example.com',
        ],
    ],
];
