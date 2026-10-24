<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Sales tax is display only (ADR 0027). Redemption Fraud Detection sees the
 * amount charged (the total including tax) and nothing about tax: a taxed
 * menu basket and a typed-in amount of the same total give the AI exactly
 * the same input, and the shape of that input is unchanged.
 */
class FraudInputTaxTest extends TestCase
{
    use RefreshDatabase;

    /** The signals sent to the AI, exactly as before tax display. */
    private const SIGNAL_KEYS = [
        'amount_cents', 'balance_before_cents', 'is_round_number', 'is_full_balance',
        'same_merchant_visits_last_7_days', 'same_merchant_visits_last_30_days',
        'employee_past_redemptions', 'employee_median_cents', 'amount_vs_employee_median',
        'merchant_category', 'category_typical_range_cents', 'amount_vs_category_range',
        'local_hour', 'outside_business_hours',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_fraud_ai_sees_the_same_for_a_taxed_basket_and_a_typed_in_total(): void
    {
        $claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        $employee = Employee::firstOrFail();
        $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $service = app(RedemptionService::class);
        $lastRedemption = Redemption::max('id');
        $lastEntry = DB::table('credit_ledger_entries')->max('id');

        $basket = RedemptionBasket::build($grind, [$grind->products()->where('name', 'Latte')->value('id') => 2], 0);
        $this->assertSame(1265, $basket['total_cents']);
        $taxed = $service->redeem($employee, $grind, $basket['total_cents'], $basket['items'], tax: $basket['tax']);
        $this->assertSame(115, $taxed->tax_cents);

        // Put everything back so the second one is scored from the same state.
        DB::table('merchant_payouts')->where('redemption_id', $taxed->id)->delete();
        DB::table('credit_ledger_entries')->where('id', '>', $lastEntry)->delete();
        DB::table('redemption_items')->where('redemption_id', $taxed->id)->delete();
        DB::table('redemptions')->where('id', '>', $lastRedemption)->delete();

        $typed = $service->redeem($employee->fresh(), $grind, 1265);

        $this->assertCount(2, $claude->calls);
        $this->assertSame($claude->calls[0]['system'], $claude->calls[1]['system']);
        $this->assertSame($claude->calls[0]['user'], $claude->calls[1]['user']);
        $this->assertSame($taxed->signals, $typed->signals);
        $this->assertSame(1265, $taxed->signals['amount_cents'], 'the AI sees the total charged');
        $this->assertSame(self::SIGNAL_KEYS, array_keys($taxed->signals));
        $this->assertSame(self::SIGNAL_KEYS, array_keys(json_decode($claude->calls[0]['user'], true)));
        foreach (['tax', 'subtotal', 'meals', 'prepared_food', 'Latte'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $claude->calls[0]['user']);
        }
    }
}
