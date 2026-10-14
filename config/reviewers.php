<?php

/*
| Reviewers (contest judges, HR managers, merchants, BID staff, friends) sign
| in with a personal magic link. Every link stops working at the end of
| judging, read in Washington DC time. Change JUDGING_ENDS_AT to move it:
| links already sent follow the new date.
*/

return [
    'judging_ends_at' => env('JUDGING_ENDS_AT') ?: '2026-11-30 23:59',
    'timezone' => 'America/New_York',

    // Blind adjudication of the fraud eval (ADR 0011): the date shown to
    // reviewers. Voting actually ends when the platform admin presses
    // Close voting on /admin/adjudication.
    'adjudication_ends_at' => env('ADJUDICATION_ENDS_AT') ?: '2026-11-13 23:59',
];
