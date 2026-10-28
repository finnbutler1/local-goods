<?php

// Merchant applications from the public "Apply to join" form (ADR 0031).
return [

    // Applications are deleted on request, or after this date. Run
    // `php artisan applications:purge --before=2027-01-01` on 2027-01-01.
    'retain_until' => '2026-12-31',

    // Lookups from the public address checker keep no typed text, and even
    // their hash and answer are purged after this many days.
    'checker_lookup_days' => 30,

    // One IP may send this many applications per 10 minutes.
    'per_ten_minutes' => 3,
];
