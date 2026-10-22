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
];
