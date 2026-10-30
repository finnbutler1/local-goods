<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The demo seed is solvent whatever day it is seeded on (ADR 0033). On
 * these dates the 8-week window holds only two allowances, and before the
 * fix the planted Pattern B ring overdrew by $80–$110. The ring still has
 * its three people, five Thursdays and round amounts on every date.
 */
class DemoSeedSolvencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function seedDates(): array
    {
        return ['1 Sep' => ['2026-09-01 12:00'], '30 Sep' => ['2026-09-30 12:00'], '30 Oct' => ['2026-10-30 12:00'],
            '3 Nov' => ['2026-11-03 12:00'], 'judging week' => ['2026-11-27 12:00'], 'fixed test date' => ['2026-10-24 12:00']];
    }

    #[DataProvider('seedDates')]
    public function test_the_seed_never_takes_a_balance_below_zero(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date, 'America/New_York'));
        $this->seed(DatabaseSeeder::class);

        $history = app(SolvencyCheck::class)->history();
        $this->assertTrue($history->passed(), $history->summary());

        $ring = Employee::whereIn('display_name', DemoHistorySeeder::PATTERN_B_EMPLOYEES)->pluck('id');
        $rows = Redemption::whereIn('employee_id', $ring)->where('merchant_id', Merchant::where('name', DemoHistorySeeder::PATTERN_B_MERCHANT)->value('id'))->get();
        $days = $rows->groupBy(fn (Redemption $r) => $r->created_at->setTimezone('America/New_York')->toDateString());

        $this->assertCount(DemoHistorySeeder::PATTERN_B_DAYS, $days, 'Still five Thursdays.');
        $this->assertTrue($days->every(fn ($day) => $day->pluck('employee_id')->sort()->values()->all() === $ring->sort()->values()->all()), 'All three, every time.');
        $this->assertTrue($rows->every(fn (Redemption $r) => in_array($r->amount_cents, [6000, 7000], true)), 'Round $60 or $70.');
    }
}
