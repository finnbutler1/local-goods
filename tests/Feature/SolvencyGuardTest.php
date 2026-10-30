<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\SolvencyCheckRun;
use App\Services\CreditLedger;
use App\Services\Payments\MerchantPayouts;
use App\Services\Solvency\SolvencyGuard;
use App\Services\Solvency\SolvencyRefused;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The solvency guard (ADR 0033): the last line of defence behind the
 * existing money guards. An operation that would leave a bigger shortfall
 * than the one already known is refused and writes nothing; a problem
 * already recorded by a full check doesn't stop unrelated operations;
 * money Stripe already moved is recorded and flagged, never refused.
 */
class SolvencyGuardTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_unbacked_promise_is_refused_and_nothing_is_written(): void
    {
        $lines = CreditLedgerEntry::count();
        $employee = Employee::orderBy('id')->skip(3)->firstOrFail();

        try {
            app(SolvencyGuard::class)->transaction('an allowance with no allocation', fn () => CreditLedgerEntry::create([
                'employee_id' => $employee->id, 'amount_cents' => 4000, 'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'description' => 'A bug',
            ]));
            $this->fail('An unbacked allowance was allowed.');
        } catch (SolvencyRefused $e) {
            $this->assertSame('Refused: an allowance with no allocation would leave Local Goods holding less money than it has promised (short by $40.00). Nothing was changed. See Where the money is.', $e->getMessage());
        }

        $this->assertSame($lines, CreditLedgerEntry::count(), 'Rolled back');
    }

    public function test_paying_out_more_than_a_merchant_is_owed_is_refused(): void
    {
        $merchant = Merchant::orderBy('id')->firstOrFail();
        $owed = $merchant->payableCents();

        $this->expectException(SolvencyRefused::class);
        app(SolvencyGuard::class)->transaction('an overpayment', function () use ($merchant, $owed) {
            $row = MerchantPayout::create(['merchant_id' => $merchant->id, 'amount_cents' => $owed + 2500, 'status' => MerchantPayout::STATUS_SIMULATED, 'paid_at' => now()]);
            CreditLedgerEntry::create(['merchant_id' => $merchant->id, 'amount_cents' => -$row->amount_cents, 'type' => CreditLedgerEntry::TYPE_PAYOUT, 'merchant_payout_id' => $row->id, 'description' => 'A bug']);
        });
    }

    public function test_a_known_problem_does_not_block_an_unrelated_allocation_but_anything_worse_is_refused(): void
    {
        $this->plantShortfall(2500);
        $employer = Employer::firstOrFail();
        $someone = Employee::orderBy('id')->skip(10)->firstOrFail();

        // Before any full check has recorded it, the guard is strict.
        try {
            app(CreditLedger::class)->allocateMany($employer, [$someone], 500, Occasion::Recognition);
            $this->fail('Allowed while short with nothing recorded.');
        } catch (SolvencyRefused) {
        }

        // A full check records the $25.00: it shows as a failure, but the site keeps working.
        $this->artisan('ledger:check-solvency')->assertFailed();
        $this->assertSame(['shortfall' => 2500, 'stripe' => 0], SolvencyGuard::known());
        app(CreditLedger::class)->allocateMany($employer, [$someone], 500, Occasion::Recognition);

        // Making it worse is still refused.
        $this->expectException(SolvencyRefused::class);
        $this->guarded(100);
    }

    public function test_a_transfer_that_simulated_money_would_have_to_pay_for_is_not_sent(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(50000);
        $merchant = $this->farragut();
        $redemption = $this->cleared(2000);
        $row = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $this->assertSame(MerchantPayout::STATUS_OWED, $row->status, 'Owed through Stripe, the shop is not onboarded yet');

        // Stripe money already out to another shop: $490 of the $500 paid in (balanced in the ledger).
        $other = Merchant::where('id', '!=', $merchant->id)->orderBy('id')->firstOrFail();
        DB::table('credit_ledger_entries')->insert([
            ['employer_id' => Employer::firstOrFail()->id, 'merchant_id' => null, 'merchant_payout_id' => null, 'amount_cents' => -49000, 'type' => CreditLedgerEntry::TYPE_ALLOCATION_OUT, 'description' => 'Test', 'created_at' => now()],
            ['employer_id' => null, 'merchant_id' => $other->id, 'merchant_payout_id' => null, 'amount_cents' => 49000, 'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED, 'description' => 'Test', 'created_at' => now()],
            ['employer_id' => null, 'merchant_id' => $other->id, 'merchant_payout_id' => MerchantPayout::create(['merchant_id' => $other->id, 'amount_cents' => 49000, 'status' => MerchantPayout::STATUS_SENT, 'stripe_transfer_id' => 'tr_test_other', 'paid_at' => now()])->id,
                'amount_cents' => -49000, 'type' => CreditLedgerEntry::TYPE_PAYOUT, 'description' => 'Test', 'created_at' => now()],
        ]);

        $this->onboard($merchant);
        app(MerchantPayouts::class)->send($merchant);

        $this->assertSame([], $this->stripe->callsOf('transfer'), 'Nothing sent');
        $row->refresh();
        $this->assertSame(MerchantPayout::STATUS_OWED, $row->status, 'Still owed, as it was');
        $this->assertStringContainsString('Stripe money out would be above Stripe money in by $10.00', $row->failure_reason);
    }

    public function test_money_stripe_already_moved_is_recorded_and_flagged_never_refused(): void
    {
        $this->fakeStripe();
        $this->plantShortfall(1500);

        $purchase = $this->stripePurchase(30000);

        $this->assertSame(CreditPurchase::STATUS_PAID, $purchase->status, 'Recorded: Stripe has the money');
        $run = SolvencyCheckRun::latest();
        $this->assertSame(SolvencyCheckRun::TRIGGER_STRIPE, $run->trigger);
        $this->assertFalse($run->passed);
        $this->assertSame(['shortfall' => 1500, 'stripe' => 0], SolvencyGuard::known());
    }

    public function test_the_kill_switch_turns_the_guard_off(): void
    {
        config(['solvency.guard_enabled' => false]);

        $this->guarded(900);

        $this->assertSame(900, (int) CreditLedgerEntry::where('description', 'A bug')->sum('amount_cents'));
    }

    /** A problem already in the ledger, as a bug that bypassed the services would leave it. */
    private function plantShortfall(int $cents): void
    {
        DB::table('credit_ledger_entries')->insert(['employee_id' => Employee::orderBy('id')->skip(4)->firstOrFail()->id, 'amount_cents' => $cents,
            'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'description' => 'A bug', 'created_at' => now()]);
    }

    /** The same bug inside a guarded operation. */
    private function guarded(int $cents): void
    {
        app(SolvencyGuard::class)->transaction('a test', fn () => CreditLedgerEntry::create([
            'employee_id' => Employee::orderBy('id')->skip(4)->firstOrFail()->id, 'amount_cents' => $cents,
            'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'description' => 'A bug',
        ]));
    }
}
