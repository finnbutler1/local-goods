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
];
