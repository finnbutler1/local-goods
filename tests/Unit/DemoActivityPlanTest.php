<?php

namespace Tests\Unit;

use App\Services\DemoActivity\DayPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * The demo activity generator's day plans (ADR 0032): the same date always
 * draws the same plan, weekends are quiet, lunch is busiest, Friday is
 * lighter and nothing in a plan can break a daily cap on its own.
 */
class DemoActivityPlanTest extends TestCase
{
    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/New_York');
    }

    /** Every weekday from Oct 26 to Dec 4, 2026. */
    private function weekdays(): array
    {
        $days = [];
        for ($d = $this->day('2026-10-26'); $d->lte($this->day('2026-12-04')); $d = $d->addDay()) {
            if ($d->dayOfWeekIso <= 5) {
                $days[] = $d;
            }
        }

        return $days;
    }

    public function test_the_same_date_always_draws_the_same_plan(): void
    {
        $planner = new DayPlanner;

        $this->assertSame($planner->plan($this->day('2026-11-04')), $planner->plan($this->day('2026-11-04')));
        $this->assertNotSame($planner->plan($this->day('2026-11-04')), $planner->plan($this->day('2026-11-05')));
    }

    public function test_weekends_have_no_plan(): void
    {
        $this->assertSame([], (new DayPlanner)->plan($this->day('2026-11-07')));
        $this->assertSame([], (new DayPlanner)->plan($this->day('2026-11-08')));
    }

    public function test_monday_to_thursday_are_busier_than_friday(): void
    {
        foreach ($this->weekdays() as $day) {
            $count = collect((new DayPlanner)->plan($day))->where('kind', 'redemption')->where('unusual', false)->count();
            [$fewest, $most] = $day->dayOfWeekIso === 5 ? [6, 9] : [10, 14];
            $this->assertGreaterThanOrEqual($fewest, $count, $day->toDateString());
            $this->assertLessThanOrEqual($most, $count, $day->toDateString());
        }
    }

    public function test_most_redemptions_fall_at_lunch_and_all_inside_the_tick_hours(): void
    {
        $times = collect($this->weekdays())->flatMap(fn ($d) => collect((new DayPlanner)->plan($d))->where('kind', 'redemption')->pluck('at'));
        $lunch = $times->filter(fn ($t) => $t >= '11:00' && $t < '14:00')->count();

        $this->assertGreaterThan(0.6, $lunch / $times->count());
        $this->assertTrue($times->every(fn ($t) => $t >= '07:00' && $t < '19:00'));
        // The peak hour is 12:00–13:00.
        $byHour = $times->countBy(fn ($t) => substr($t, 0, 2));
        $this->assertSame(12, (int) $byHour->sortDesc()->keys()->first());
    }

    public function test_both_ways_of_paying_several_merchants_and_menu_items_appear(): void
    {
        $slots = collect($this->weekdays())->flatMap(fn ($d) => (new DayPlanner)->plan($d))->where('kind', 'redemption');

        $this->assertEqualsCanonicalizing(['in_person', 'to_go'], $slots->pluck('channel')->unique()->values()->all());
        $this->assertCount(8, $slots->pluck('merchant')->unique());
        $this->assertGreaterThan(0.6, $slots->whereNotNull('items')->count() / $slots->count());

        foreach ($slots as $slot) {
            if ($slot['items'] !== null) {
                $menu = array_column(DatabaseSeeder::DEMO_MENUS[$slot['merchant']], 0);
                $this->assertEmpty(array_diff(array_keys($slot['items']), $menu));
                $this->assertNull($slot['amount_cents']);
            } elseif (! $slot['unusual']) {
                // A typed-in amount is never round, so it doesn't look unusual by accident.
                $this->assertNotSame(0, $slot['amount_cents'] % 100);
            }
        }
    }

    public function test_about_one_unusual_slot_every_other_day_and_one_a_day_at_most(): void
    {
        $days = collect($this->weekdays());
        $unusual = $days->map(fn ($d) => collect((new DayPlanner)->plan($d))->where('unusual', true)->count());

        $this->assertTrue($unusual->every(fn ($n) => $n <= 1));
        $share = $unusual->sum() / $days->count();
        $this->assertGreaterThan(0.25, $share);
        $this->assertLessThan(0.75, $share);
    }

    public function test_weekly_extras_happen_once_a_week(): void
    {
        $planner = new DayPlanner;
        $week = collect(range(0, 4))->map(fn ($i) => $this->day('2026-11-09')->addDays($i));
        $kinds = $week->flatMap(fn ($d) => collect($planner->plan($d))->pluck('kind'));

        $this->assertSame(1, $kinds->filter(fn ($k) => $k === 'recognition')->count());
        $this->assertSame(1, $kinds->filter(fn ($k) => $k === 'refund_request')->count());
    }

    public function test_a_plan_never_has_more_actions_than_the_daily_cap(): void
    {
        foreach ($this->weekdays() as $day) {
            $this->assertLessThanOrEqual(config('demo_activity.caps.actions_per_day'), count((new DayPlanner)->plan($day)));
        }
    }
}
