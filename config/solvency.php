<?php

/*
| The solvency rule (ADR 0033): money in hand must cover every promise
| (customer funds held plus operating funds), at every moment.
*/

return [
    // The guard after every money operation. Refuses an operation that
    // would leave a bigger shortfall than before it. A kill switch only:
    // turn it off in Beanstalk if it ever refuses something it shouldn't,
    // and the checks below still report.
    'guard_enabled' => (bool) env('SOLVENCY_GUARD_ENABLED', true),

    // What the demo activity tick checks each time (ADR 0032):
    // "history" replays the whole ledger (about 10 ms at 2,000 entries,
    // measured 2026-10-30, growing about 40 entries a generated weekday);
    // "current" is the cheap check of the position now (about 2 ms), with
    // the full history once a day at the first tick after the time below.
    'tick_check' => env('SOLVENCY_TICK_CHECK', 'history'),
    'history_check_daily_at' => '07:00',
];
