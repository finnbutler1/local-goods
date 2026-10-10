<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Services\Fraud\SignalCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SignalCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        // Oct 20, 2026 at noon in DC. Seeded balance is $120.00.
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->employee = Employee::firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signals(string $merchant, int $cents, ?Carbon $at = null): array
    {
        return app(SignalCalculator::class)
            ->calculate($this->employee, Merchant::where('name', $merchant)->firstOrFail(), $cents, $at);
    }

    public function test_a_normal_coffee_raises_no_flags(): void
    {
        $s = $this->signals('Farragut Grind', 650);

        $this->assertFalse($s['is_round_number']);
        $this->assertFalse($s['is_full_balance']);
        $this->assertFalse($s['outside_business_hours']);
        $this->assertSame('within', $s['amount_vs_category_range']);
        $this->assertSame(12000, $s['balance_before_cents']);
        $this->assertSame(12, $s['employee_past_redemptions']);
    }

    public function test_full_balance_round_number_is_flagged(): void
    {
        $s = $this->signals('Press Room Deli', 12000);

        $this->assertTrue($s['is_round_number']);
        $this->assertTrue($s['is_full_balance']);
        $this->assertSame('above', $s['amount_vs_category_range']);
        $this->assertSame(1, $s['same_merchant_visits_last_7_days']);
        $this->assertGreaterThan(5, $s['amount_vs_employee_median']);
    }

    public function test_late_night_is_outside_business_hours(): void
    {
        $s = $this->signals('Farragut Grind', 650, Carbon::parse('2026-10-20 23:30', 'America/New_York'));

        $this->assertTrue($s['outside_business_hours']);
        $this->assertSame(23, $s['local_hour']);
    }

    public function test_signals_contain_no_names(): void
    {
        $json = json_encode($this->signals('Press Room Deli', 1200));

        $this->assertStringNotContainsString('Jordan', $json);
        $this->assertStringNotContainsString('Press Room', $json);
        $this->assertStringNotContainsString('Meridian', $json);
    }
}
