<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Services\Ai\ClaudeClient;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use App\Services\Fraud\SignalCalculator;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Corrections are never sent to Redemption Fraud Detection (ADR 0028) and
 * never change a fraud decision. A refund puts credits back in the
 * employee's balance, which the AI already sees as balance_before_cents,
 * exactly as an allowance of the same amount would; nothing else about the
 * refund reaches it.
 */
class FraudInputCorrectionsTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_refund_never_changes_the_fraud_decision_or_the_stored_ai_answer(): void
    {
        $redemption = $this->cleared(1250);
        $before = $redemption->fresh()->only(['status', 'risk_score', 'ai_decision', 'ai_explanation', 'signals', 'scored_by']);

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Refund.', amountCents: 1250);

        $this->assertSame($before, $redemption->fresh()->only(['status', 'risk_score', 'ai_decision', 'ai_explanation', 'signals', 'scored_by']));
        $this->assertNull($redemption->fresh()->decision, 'Not a fraud decision');
    }

    public function test_after_a_refund_the_ai_sees_what_it_would_after_an_equal_allowance(): void
    {
        $employee = $this->shopper();
        $redemption = $this->cleared(1250, $employee);
        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Refund.', amountCents: 500);
        $afterRefund = app(SignalCalculator::class)->calculate($employee->fresh(), $this->farragut(), 700);

        // The same balance from an allowance instead of the refund.
        DB::table('credit_ledger_entries')->where('correction_id', $correction->id)->delete();
        DB::table('correction_actions')->delete();
        DB::table('corrections')->where('id', $correction->id)->delete();
        app(CreditLedger::class)->allocate($employee->employer, $employee, 500);
        $afterAllowance = app(SignalCalculator::class)->calculate($employee->fresh(), $this->farragut(), 700);

        $this->assertSame($afterAllowance, $afterRefund);
        $this->assertSame(self::SIGNAL_KEYS, array_keys($afterRefund));
    }

    public function test_nothing_about_a_correction_is_in_the_ai_prompt(): void
    {
        $employee = $this->shopper();
        $redemption = $this->cleared(1250, $employee);
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Refund for the cold brew.', amountCents: 500);
        $this->assertSame(3, Correction::count(), 'Two seeded plus this one');

        $claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        app(RedemptionService::class)->redeem($employee->fresh(), $this->farragut(), 700);

        $sent = $claude->calls[0]['system'].$claude->calls[0]['user'];
        foreach (['refund', 'correction', 'cold brew', 'Refund'] as $word) {
            $this->assertStringNotContainsString($word, $sent);
        }
    }
}
