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

    // The full public judge link, set only in the Beanstalk environment
    // properties. Read through App\Support\PublicJudgeLink, never directly.
    'public_judge_url' => env('PUBLIC_JUDGE_URL'),

    // Blind adjudication of the fraud eval was retired with no votes (ADR
    // 0041): voting is closed and stored data is kept. Deliberately not an
    // environment setting, so nothing outside the code can reopen it.
    'adjudication_retired' => true,
];
