<?php

return [
    /*
     * Platform fee (ADR 0021), a percent added on top of the credits an
     * employer buys, e.g. "2.5". Unset or blank means 0: no fee, and nothing
     * about fees appears anywhere. Only a number from 0 to 20 with up to two
     * decimals is accepted; anything else counts as 0 here and stops a
     * production deploy (app:check-production-config). Kept raw so the
     * check can tell "unset" from "bad".
     */
    'platform_fee_percent' => env('PLATFORM_FEE_PERCENT'),

    /*
     * Employer platform fee per participating employee per month (ADR 0042).
     * Switched on in Stripe test mode on the live site on 2026-11-12, by
     * Finn in Beanstalk; switch off by unsetting it. Off unless this is
     * exactly true: unset means off, in every environment including
     * production. Never set it in a deploy file or seeder (a test checks).
     * The price is not set here: it is the base employer_platform_fee in
     * config/projections.php. Refused together with PLATFORM_FEE_PERCENT.
     */
    'employer_platform_fee_enabled' => env('EMPLOYER_PLATFORM_FEE_ENABLED', false) === true,

    /*
     * BID annual program fee, charged in Stripe test mode (ADR 0044). Off
     * unless this is exactly true: unset means off, everywhere. Never set it
     * in a deploy file or seeder (a test checks) and never turn it on
     * without Finn's say-so. The price is not set here: it is the district's
     * tier in config/projections.php bid_fee_tiers. It charges only with
     * Stripe test keys; with no keys the page says Stripe test mode is needed.
     */
    'bid_program_fee_enabled' => env('BID_PROGRAM_FEE_ENABLED', false) === true,
];
