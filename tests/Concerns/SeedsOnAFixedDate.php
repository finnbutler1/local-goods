<?php

namespace Tests\Concerns;

use Illuminate\Support\Carbon;

/**
 * The demo seed is relative to today: 8 weeks of history and an allowance
 * on the 1st of each month in that window. When the window's start crosses
 * into a new month, seeded employees get one allowance fewer, so tests that
 * spend seeded balances or the employer's spare credits pass or fail with
 * the calendar. They seed on this fixed date instead (Laravel resets the
 * clock after each test).
 */
trait SeedsOnAFixedDate
{
    protected const SEED_DATE = '2026-10-24 12:00';

    protected function seedOnAFixedDate(): void
    {
        Carbon::setTestNow(Carbon::parse(self::SEED_DATE, 'America/New_York'));
    }
}
