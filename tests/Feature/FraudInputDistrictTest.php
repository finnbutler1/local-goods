<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Districts (ADR 0031) never reach Redemption Fraud Detection: a merchant's
 * district, coordinates and approval dates change nothing the AI sees.
 */
class FraudInputDistrictTest extends TestCase
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

    public function test_the_fraud_ai_sees_the_same_whatever_the_merchant_location(): void
    {
        $claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        $employee = Employee::orderBy('id')->firstOrFail();
        $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $lastRedemption = Redemption::max('id');
        $lastEntry = DB::table('credit_ledger_entries')->max('id');

        $first = app(RedemptionService::class)->redeem($employee, $grind, 1265);

        DB::table('merchant_payouts')->where('redemption_id', $first->id)->delete();
        DB::table('credit_ledger_entries')->where('id', '>', $lastEntry)->delete();
        DB::table('redemptions')->where('id', '>', $lastRedemption)->delete();
        // Another point inside the district, approved and live at other times.
        $grind->update(['latitude' => 38.9037, 'longitude' => -77.0405, 'district_approved_at' => now()->subYear(), 'live_at' => now()->subMonth()]);

        $second = app(RedemptionService::class)->redeem($employee->fresh(), $grind->fresh(), 1265);

        $this->assertCount(2, $claude->calls);
        $this->assertSame($claude->calls[0]['user'], $claude->calls[1]['user']);
        $this->assertSame($first->signals, $second->signals);

        foreach (['district', 'latitude', 'longitude', 'live_at', 'approved', '38.90', '-77.0'] as $word) {
            $this->assertStringNotContainsString($word, $claude->calls[0]['user']);
        }
    }
}
