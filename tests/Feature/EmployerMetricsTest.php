<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\EmployerMetrics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The employer dashboard's "got out into the neighborhood" numbers, on a
 * small hand-built history where every answer can be counted by eye.
 */
class EmployerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Employer $employer;

    /** @var list<Employee> */
    private array $staff;

    private Merchant $cafe;

    private Merchant $books;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'America/New_York'));

        $bid = Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);
        $this->employer = Employer::create(['bid_id' => $bid->id, 'name' => 'Meridian Policy Group', 'monthly_allowance_cents' => 15000, 'staff_count' => 120]);
        $this->staff = array_map(fn ($n) => Employee::create(['employer_id' => $this->employer->id, 'display_name' => "Employee {$n}"]), [1, 2, 3]);
        $this->cafe = Merchant::create(['bid_id' => $bid->id, 'name' => 'Farragut Grind', 'category' => 'cafe', 'address' => '1 Test St NW']);
        $this->books = Merchant::create(['bid_id' => $bid->id, 'name' => 'Page & Quill Books', 'category' => 'retail', 'address' => '2 Test St NW']);
    }

    private function redemption(int $employee, Merchant $merchant, int $cents, string $when, string $status = Redemption::STATUS_APPROVED): void
    {
        Redemption::create([
            'employee_id' => $this->staff[$employee]->id, 'merchant_id' => $merchant->id, 'amount_cents' => $cents,
            'status' => $status, 'scored_by' => 'seed', 'created_at' => Carbon::parse($when, 'America/New_York')->utc(),
        ]);
    }

    public function test_each_number_on_a_history_counted_by_hand(): void
    {
        $this->redemption(0, $this->cafe, 500, '2026-09-20 09:00');      // last month
        $this->redemption(0, $this->cafe, 650, '2026-10-02 08:30');      // back at a known place
        $this->redemption(0, $this->books, 2400, '2026-10-06 13:00');    // first visit
        $this->redemption(1, $this->cafe, 700, '2026-10-13 09:15');      // first visit
        $this->redemption(1, $this->cafe, 725, '2026-10-14 09:15');      // same pair again: not another first visit
        $this->redemption(1, $this->cafe, 900, '2026-10-14 19:30', Redemption::STATUS_UNDER_REVIEW);
        $this->redemption(2, $this->books, 4000, '2026-10-09 18:00', Redemption::STATUS_REJECTED);
        // Just after midnight on Oct 1 in DC (still Sep 30 in UTC) counts as October.
        $this->redemption(2, $this->cafe, 300, '2026-10-01 00:05');

        $m = app(EmployerMetrics::class)->for($this->employer);

        $this->assertSame(3, $m['enrolled']);
        $this->assertSame(120, $m['staff']);
        $this->assertSame(3, $m['redeemers'], 'Employee 3 counts for the cleared $3 on Oct 1, not the rejected one');
        $this->assertSame(100, $m['participation_pct']);
        $this->assertSame(3, $m['staff_pct'], '3 of 120 staff, rounded');
        $this->assertSame(2, $m['participating']);
        $this->assertSame(5, $m['visits']);
        $this->assertSame(2, $m['businesses']);
        $this->assertSame(3, $m['first_visits']);
        $this->assertSame(650 + 2400 + 700 + 725 + 300, $m['spent_cents']);
        $this->assertSame([1, 900], [$m['held_count'], $m['held_cents']]);
        $this->assertSame([
            ['category' => 'retail', 'cents' => 2400, 'visits' => 1],
            ['category' => 'cafe', 'cents' => 650 + 700 + 725 + 300, 'visits' => 4],
        ], $m['categories']);
    }

    public function test_the_trend_has_eight_monday_weeks_ending_this_week(): void
    {
        $this->redemption(0, $this->cafe, 500, '2026-08-24 09:00');      // Monday of the oldest week
        $this->redemption(0, $this->cafe, 600, '2026-08-23 09:00');      // the Sunday before: outside
        $this->redemption(1, $this->cafe, 700, '2026-10-12 09:00');      // this week
        $this->redemption(1, $this->cafe, 800, '2026-10-12 10:00', Redemption::STATUS_UNDER_REVIEW);

        $weeks = app(EmployerMetrics::class)->for($this->employer)['weeks'];

        $this->assertCount(8, $weeks);
        $this->assertSame('2026-08-24', $weeks[0]['start']->toDateString());
        $this->assertSame('2026-10-12', $weeks[7]['start']->toDateString());
        $this->assertSame([1, 500], [$weeks[0]['visits'], $weeks[0]['cents']]);
        $this->assertSame([1, 700], [$weeks[7]['visits'], $weeks[7]['cents']]);
        $this->assertSame(2, array_sum(array_column($weeks, 'visits')));
    }

    public function test_no_redemptions_gives_zeros_not_errors(): void
    {
        $m = app(EmployerMetrics::class)->for($this->employer);

        $this->assertSame([0, 0, 0, 0, 0, []], [$m['participation_pct'], $m['visits'], $m['businesses'], $m['first_visits'], $m['spent_cents'], $m['categories']]);
    }

    public function test_the_seeded_demo_has_a_trend_in_every_week(): void
    {
        $this->travelBack();
        $this->seed(DatabaseSeeder::class); // a second employer, the demo's Meridian

        $m = app(EmployerMetrics::class)->for(Employer::latest('id')->firstOrFail());

        $this->assertSame(40, $m['enrolled']);
        foreach (array_slice($m['weeks'], 0, 7) as $week) {
            $this->assertGreaterThan(0, $week['visits'], 'Week of '.$week['start']->toDateString());
        }
    }
}
