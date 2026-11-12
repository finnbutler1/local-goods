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
            'email' => 'sam.taylor@goldentriangle.uselocalgoods.com',
            'password' => env('DEMO_PASSWORD_BID_ADMIN'),
        ],
        'employer_admin' => [
            'name' => 'Alex Morgan',
            'email' => 'alex.morgan@meridianpolicygroup.com',
            'password' => env('DEMO_PASSWORD_EMPLOYER_ADMIN'),
        ],
        'employee' => [
            'name' => 'Jordan Rivera',
            'email' => 'jordan.rivera@meridianpolicygroup.com',
            'password' => env('DEMO_PASSWORD_EMPLOYEE'),
        ],
        'merchant' => [
            'name' => 'Priya Natarajan',
            'email' => 'priya.natarajan@farragutgrind.com',
            'password' => env('DEMO_PASSWORD_MERCHANT'),
            // The fictional demo merchant this login runs (DatabaseSeeder).
            'business' => 'Farragut Grind',
        ],
        'judge' => [
            'name' => 'Public judge access',
            'email' => 'public.judge@example.com',
        ],
    ],

    /*
    | The address each of these logins had before, by role. demo:update-emails
    | moves them; until it has run, demo:seed-accounts skips the role instead
    | of creating a second login. Platform admin and judge are never in here.
    */
    'previous_emails' => [
        'bid_admin' => 'sam.taylor@example.com',
        'employer_admin' => 'alex.morgan@example.com',
        'employee' => 'jordan.rivera@example.com',
        'merchant' => 'priya.natarajan@example.com',
    ],

    /*
    | The demo accounts' fictional organisations. Someone else could own these
    | domains, so no email is ever sent to them, in any environment
    | (App\Support\DemoMailGuard). Exact domains only: never uselocalgoods.com
    | itself, a real reviewer's domain or anything else.
    */
    'mail_blocked_domains' => [
        'meridianpolicygroup.com',
        'farragutgrind.com',
        'goldentriangle.uselocalgoods.com',
    ],

    /*
    | Reset demo (DemoResetter) erases every live demo example, so it only
    | ever runs on a laptop or in tests. Deliberately no env override: nothing
    | set on Beanstalk can turn it on. The reset-demo gate also refuses in
    | production on its own.
    */
    'reset_enabled' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
];
