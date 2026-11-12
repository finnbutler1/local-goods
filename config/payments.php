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
     * Built and tested; switched off during the pilot. Off unless this is
     * exactly true: unset means off, in every environment including
     * production. Never set it in a deploy file or seeder (a test checks).
     * The price is not set here: it is the base employer_platform_fee in
     * config/projections.php. Refused together with PLATFORM_FEE_PERCENT.
     */
    'employer_platform_fee_enabled' => env('EMPLOYER_PLATFORM_FEE_ENABLED', false) === true,
];
