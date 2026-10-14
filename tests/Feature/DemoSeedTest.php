<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The synthetic demo: 40 Meridian employees, eight weeks of history at the
 * fictional merchants only, every balance consistent with the ledger, and
 * the two planted collusion patterns described in DemoHistorySeeder.
 */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_meridian_has_40_enrolled_of_120_staff_and_jordan_comes_first(): void
    {
        $employer = Employer::sole();

        $this->assertSame('Meridian Policy Group', $employer->name);
        $this->assertSame(120, $employer->staff_count);
        $this->assertSame(40, Employee::count());
        $this->assertSame('Jordan Rivera (demo)', Employee::orderBy('id')->firstOrFail()->display_name);
        $this->assertSame(12, Employee::orderBy('id')->firstOrFail()->redemptions()->count());
    }

    public function test_every_redemption_is_at_a_fictional_merchant_within_eight_weeks(): void
    {
        $this->assertSame(
            ['Connecticut Noodle Bar', 'Farragut Grind', 'K Street Cuts', 'Lafayette Cycle Studio', 'Page & Quill Books', 'Press Room Deli', 'Seventeenth Street Kitchen', 'Triangle Stems'],
            Merchant::orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(0, Redemption::whereNotIn('merchant_id', Merchant::pluck('id'))->count());
        $this->assertSame(0, Redemption::where('created_at', '<', now('America/New_York')->subWeeks(8)->startOfDay()->utc())->count());
        $this->assertSame(0, Redemption::where('created_at', '>', now())->count());
        $this->assertSame(0, Redemption::where('status', '!=', Redemption::STATUS_APPROVED)->count());
        $this->assertGreaterThan(300, Redemption::count());
    }

    public function test_no_balance_is_ever_negative_and_every_cent_is_accounted_for(): void
    {
        foreach (Employee::all() as $employee) {
            $this->assertGreaterThanOrEqual(0, $employee->balanceCents(), $employee->display_name);
        }
        foreach (Merchant::all() as $merchant) {
            $this->assertGreaterThanOrEqual(0, $merchant->payableCents(), $merchant->name);
        }
        $this->assertGreaterThanOrEqual(0, Employer::sole()->balanceCents());

        $this->assertSame(
            (int) CreditPurchase::sum('amount_cents') - (int) MerchantPayout::sum('amount_cents'),
            (int) CreditLedgerEntry::sum('amount_cents'),
        );
        $this->assertSame(
            (int) Redemption::sum('amount_cents'),
            (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents'),
            'Every seeded redemption cleared, so each earned its merchant the amount.',
        );
        $this->assertSame(0, CreditLedgerEntry::query()
            ->whereRaw('(CASE WHEN employee_id IS NULL THEN 0 ELSE 1 END + CASE WHEN employer_id IS NULL THEN 0 ELSE 1 END + CASE WHEN merchant_id IS NULL THEN 0 ELSE 1 END) <> 1')
            ->count());
        $this->assertSame(MerchantPayout::count(), MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->count());
    }

    public function test_pattern_a_drains_each_allowance_at_one_merchant(): void
    {
        $reese = Employee::where('display_name', DemoHistorySeeder::PATTERN_A_EMPLOYEE)->sole();
        $redemptions = $reese->redemptions()->with('merchant')->orderBy('created_at')->get();

        $this->assertSame([DemoHistorySeeder::PATTERN_A_MERCHANT], $redemptions->pluck('merchant.name')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(4, $redemptions->count());

        foreach ($redemptions->chunk(2) as $pair) {
            [$first, $second] = $pair->values()->all();
            $this->assertSame([8000, 6000], [$first->amount_cents, $second->amount_cents]);
            $this->assertLessThanOrEqual(60, $first->created_at->diffInMinutes($second->created_at));
            $this->assertSame(19, $first->created_at->setTimezone('America/New_York')->hour);
        }
    }

    public function test_pattern_b_is_three_people_at_one_merchant_within_ten_minutes_week_after_week(): void
    {
        $ring = Employee::whereIn('display_name', DemoHistorySeeder::PATTERN_B_EMPLOYEES)->pluck('id');
        $salon = Merchant::where('name', DemoHistorySeeder::PATTERN_B_MERCHANT)->sole();

        $days = Redemption::whereIn('employee_id', $ring)->where('merchant_id', $salon->id)->get()
            ->groupBy(fn ($r) => $r->created_at->setTimezone('America/New_York')->toDateString());

        $this->assertCount(DemoHistorySeeder::PATTERN_B_DAYS, $days);

        foreach ($days as $redemptions) {
            $this->assertEqualsCanonicalizing($ring->all(), $redemptions->pluck('employee_id')->all());
            $this->assertLessThanOrEqual(10, $redemptions->min('created_at')->diffInMinutes($redemptions->max('created_at')));
            $this->assertTrue($redemptions->every(fn ($r) => in_array($r->amount_cents, [6000, 7000], true)));
        }
    }

    public function test_the_same_day_always_gives_the_same_history(): void
    {
        $fingerprint = fn () => Redemption::with(['employee', 'merchant'])->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($r) => [$r->employee->display_name, $r->merchant->name, $r->amount_cents, $r->created_at->toIso8601String()])->all();
        $first = $fingerprint();

        app(DemoResetter::class)->reset();

        $this->assertSame($first, $fingerprint());
    }
}
