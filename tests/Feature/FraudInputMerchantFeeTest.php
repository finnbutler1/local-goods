<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantFeeChange;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsMerchantFee;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The merchant fee (ADR 0029) never reaches Redemption Fraud Detection: the
 * same redemption with the fee off and at its maximum gives the AI exactly
 * the same prompt and signals.
 */
class FraudInputMerchantFeeTest extends TestCase
{
    use RefreshDatabase;
    use SetsMerchantFee;

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

    public function test_the_fraud_ai_sees_the_same_with_and_without_a_fee(): void
    {
        $claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        $employee = Employee::firstOrFail();
        $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $lastRedemption = Redemption::max('id');
        $lastEntry = DB::table('credit_ledger_entries')->max('id');

        $none = app(RedemptionService::class)->redeem($employee, $grind, 633);
        $this->assertSame(0, $none->merchant_fee_cents);

        // Put everything back so the second one is scored from the same state.
        DB::table('credit_ledger_entries')->where('id', '>', $lastEntry)->delete();
        DB::table('redemptions')->where('id', '>', $lastRedemption)->delete();

        $this->setMerchantFee(1000, 100);
        $charged = app(RedemptionService::class)->redeem($employee->fresh(), $grind, 633);
        $this->assertSame(163, $charged->merchant_fee_cents);

        $this->assertCount(2, $claude->calls);
        $this->assertSame($claude->calls[0]['system'], $claude->calls[1]['system']);
        $this->assertSame($claude->calls[0]['user'], $claude->calls[1]['user']);
        $this->assertSameIgnoringKeyOrder($none->signals, $charged->signals);
        $this->assertStringNotContainsStringIgnoringCase('fee', $claude->calls[1]['user']);
        $this->assertGreaterThan(0, MerchantFeeChange::count());
    }
}
