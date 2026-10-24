<?php

namespace Tests\Feature;

use App\Enums\Role;
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
 * The channel (to go or in person, ADR 0026) is display only. Redemption
 * Fraud Detection must see exactly the same for the same facts, so paying
 * at the counter can never change how a redemption is scored.
 */
class FraudInputByChannelTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_fraud_ai_sees_exactly_the_same_for_to_go_and_in_person(): void
    {
        $claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        $employee = Employee::firstOrFail();
        $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $service = app(RedemptionService::class);
        $lastRedemption = Redemption::max('id');
        $lastEntry = DB::table('credit_ledger_entries')->max('id');

        $toGo = $service->redeem($employee, $grind, 850);
        $this->assertSame(Redemption::CHANNEL_TO_GO, $toGo->fresh()->channel);

        // Put everything back so the second one is scored from the same state.
        DB::table('merchant_payouts')->where('redemption_id', $toGo->id)->delete();
        DB::table('credit_ledger_entries')->where('id', '>', $lastEntry)->delete();
        DB::table('redemptions')->where('id', '>', $lastRedemption)->delete();

        $inPerson = $service->redeem($employee->fresh(), $grind, 850, [], Redemption::CHANNEL_IN_PERSON);

        $this->assertSame(Redemption::CHANNEL_IN_PERSON, $inPerson->fresh()->channel);
        $this->assertCount(2, $claude->calls);
        $this->assertSame($claude->calls[0]['system'], $claude->calls[1]['system']);
        $this->assertSame($claude->calls[0]['user'], $claude->calls[1]['user']);
        $this->assertSame($toGo->signals, $inPerson->signals);
        foreach (['in_person', 'in person', 'channel', 'pay code', 'counter'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $claude->calls[0]['user']);
        }
    }

    public function test_redemptions_made_before_pay_in_person_are_to_go(): void
    {
        $this->assertSame(0, Redemption::where('channel', '!=', Redemption::CHANNEL_TO_GO)->where('scored_by', '!=', 'seed')->count());
        $this->assertSame('To go', Redemption::firstOrFail()->channelLabel());
    }
}
