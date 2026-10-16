<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Models\Bid;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\EmployerProgram;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Balance math for the money around the wallet: purchases, allocations,
 * merchant earnings and payouts. Every balance is a sum of ledger entries.
 */
class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Employer $employer;
    private Employee $employee;
    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $bid = Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);
        $this->employer = Employer::create(['bid_id' => $bid->id, 'name' => 'Meridian Policy Group', 'monthly_allowance_cents' => 15000, 'staff_count' => 120]);
        $this->employee = Employee::create(['employer_id' => $this->employer->id, 'display_name' => 'Test Employee']);
        $this->merchant = Merchant::create(['bid_id' => $bid->id, 'name' => 'Farragut Grind', 'category' => 'cafe', 'address' => '1 Test St NW']);
    }

    private function ledger(): CreditLedger
    {
        return app(CreditLedger::class);
    }

    /** Buys $1,000 and gives the employee $150. */
    private function fund(): void
    {
        $this->ledger()->purchase($this->employer, 100000, 'INV-1');
        $this->ledger()->allocate($this->employer, $this->employee, 15000);
    }

    private function redeem(int $cents, string $decision): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'clear' ? 5 : 90, $decision));

        return app(RedemptionService::class)->redeem($this->employee, $this->merchant, $cents);
    }

    public function test_a_purchase_raises_the_employer_balance(): void
    {
        $this->ledger()->purchase($this->employer, 100000, 'INV-1');

        $this->assertSame(100000, $this->employer->balanceCents());
        $this->assertSame(100000, CreditPurchase::sole()->amount_cents);
    }

    public function test_an_allocation_moves_credits_from_the_employer_to_the_employee(): void
    {
        $this->fund();

        $this->assertSame(85000, $this->employer->balanceCents());
        $this->assertSame(15000, $this->employee->balanceCents());
        $this->assertSame(0, (int) CreditLedgerEntry::whereNotNull('credit_allocation_id')->sum('amount_cents'));
    }

    public function test_an_employer_cannot_hand_out_more_than_it_bought(): void
    {
        $this->ledger()->purchase($this->employer, 10000, 'INV-1');

        try {
            $this->ledger()->allocate($this->employer, $this->employee, 10001);
            $this->fail('Over-allocation was allowed.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(10000, $this->employer->balanceCents());
        $this->assertSame(0, $this->employee->balanceCents());
    }

    public function test_a_cleared_redemption_earns_the_merchant_the_amount(): void
    {
        $this->fund();
        $this->redeem(1250, 'clear');

        $this->assertSame(13750, $this->employee->balanceCents());
        $this->assertSame(1250, $this->merchant->payableCents());
    }

    public function test_a_held_redemption_earns_the_merchant_nothing(): void
    {
        $this->fund();
        $this->redeem(5000, 'hold');

        $this->assertSame(10000, $this->employee->balanceCents());
        $this->assertSame(0, $this->merchant->payableCents());
    }

    public function test_approving_a_held_redemption_earns_the_merchant_the_amount(): void
    {
        $this->fund();
        $held = $this->redeem(5000, 'hold');

        app(RedemptionReviewService::class)->approve($held, User::factory()->create());

        $this->assertSame(10000, $this->employee->balanceCents());
        $this->assertSame(5000, $this->merchant->payableCents());
    }

    public function test_rejecting_a_held_redemption_refunds_the_employee_and_earns_the_merchant_nothing(): void
    {
        $this->fund();
        $held = $this->redeem(5000, 'hold');

        app(RedemptionReviewService::class)->reject($held, User::factory()->create());

        $this->assertSame(15000, $this->employee->balanceCents());
        $this->assertSame(0, $this->merchant->payableCents());
    }

    public function test_a_payout_is_capped_at_what_the_merchant_is_owed(): void
    {
        $this->fund();
        $this->redeem(1250, 'clear');

        try {
            $this->ledger()->payout($this->merchant, 1251, MerchantPayout::STATUS_SIMULATED);
            $this->fail('Overpayment was allowed.');
        } catch (InvalidArgumentException) {
        }

        $this->ledger()->payout($this->merchant, 1250, MerchantPayout::STATUS_SIMULATED);
        $this->assertSame(0, $this->merchant->payableCents());
    }

    public function test_zero_and_negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ledger()->purchase($this->employer, 0, 'INV-0');
    }

    public function test_an_entry_must_belong_to_exactly_one_account(): void
    {
        foreach ([[], ['employee_id' => $this->employee->id, 'merchant_id' => $this->merchant->id]] as $accounts) {
            try {
                CreditLedgerEntry::create($accounts + ['amount_cents' => 100, 'type' => 'allowance', 'description' => 'x', 'created_at' => now()]);
                $this->fail('An entry with '.count($accounts).' accounts was allowed.');
            } catch (LogicException) {
            }
        }

        $this->assertSame(0, CreditLedgerEntry::count());
    }

    public function test_every_cent_bought_is_with_someone_paid_out_or_held(): void
    {
        $this->fund();
        $this->redeem(1250, 'clear');
        $this->redeem(800, 'clear');
        $approved = $this->redeem(3000, 'hold');
        $rejected = $this->redeem(2000, 'hold');
        $this->redeem(1000, 'hold');
        app(RedemptionReviewService::class)->approve($approved, User::factory()->create());
        app(RedemptionReviewService::class)->reject($rejected, User::factory()->create());
        $this->ledger()->payout($this->merchant, 4000, MerchantPayout::STATUS_SIMULATED);
        // Requested but not paid: not in the ledger, so not counted.
        $this->ledger()->requestPurchase($this->employer, 70000, 'MPG-2');

        $purchased = (int) CreditPurchase::where('status', '!=', CreditPurchase::STATUS_PENDING)->sum('amount_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');

        $this->assertSame($purchased - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
        $this->assertSame(
            $this->employer->balanceCents() + $this->employee->balanceCents() + $this->merchant->payableCents(),
            (int) CreditLedgerEntry::sum('amount_cents'),
        );
        $this->assertSame([85000, 15000 - 1250 - 800 - 3000 - 1000, 1250 + 800 + 3000 - 4000], [
            $this->employer->balanceCents(), $this->employee->balanceCents(), $this->merchant->payableCents(),
        ]);
    }

    public function test_a_requested_purchase_is_pending_and_writes_no_ledger_entry(): void
    {
        $purchase = $this->ledger()->requestPurchase($this->employer, 50000, 'MPG-1');

        $this->assertTrue($purchase->isPending());
        $this->assertSame(0, CreditLedgerEntry::count());
        $this->assertSame(0, $this->employer->balanceCents());
    }

    public function test_pending_credits_cannot_be_allocated_or_spent(): void
    {
        $this->ledger()->requestPurchase($this->employer, 50000, 'MPG-1');

        foreach ([
            fn () => $this->ledger()->allocate($this->employer, $this->employee, 100),
            fn () => $this->ledger()->allocateMany($this->employer, [$this->employee], 100, Occasion::Monthly),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Pending credits were allocated.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, CreditAllocation::count());
        $this->assertSame(0, $this->employee->balanceCents());

        // With nothing in the wallet, a redemption is refused too.
        $this->expectException(ValidationException::class);
        $this->redeem(100, 'clear');
    }

    public function test_paying_a_purchase_writes_one_entry_and_unlocks_its_credits(): void
    {
        $purchase = $this->ledger()->requestPurchase($this->employer, 50000, 'MPG-1');
        $this->ledger()->payPurchase($purchase);

        try {
            $this->ledger()->payPurchase($purchase);
            $this->fail('A purchase was paid twice.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $purchase->fresh()->status);
        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->count());
        $this->assertSame(50000, $this->employer->balanceCents());

        $this->ledger()->allocate($this->employer, $this->employee, 15000);
        $this->assertSame([35000, 15000], [$this->employer->balanceCents(), $this->employee->balanceCents()]);
    }

    public function test_bulk_allocation_gives_each_employee_the_amount_in_cents(): void
    {
        $second = Employee::create(['employer_id' => $this->employer->id, 'display_name' => 'Second Employee']);
        $this->ledger()->purchase($this->employer, 10000, 'INV-1');

        $allocations = $this->ledger()->allocateMany($this->employer, [$this->employee, $second], 2501, Occasion::Birthday);

        $this->assertCount(2, $allocations);
        $this->assertSame([10000 - 5002, 2501, 2501], [$this->employer->balanceCents(), $this->employee->balanceCents(), $second->balanceCents()]);
        $this->assertSame(Occasion::Birthday, $allocations[0]->fresh()->occasion);
        $this->assertSame('Birthday from Meridian Policy Group', CreditLedgerEntry::where('employee_id', $second->id)->value('description'));
        $this->assertSame((int) CreditPurchase::sum('amount_cents'), (int) CreditLedgerEntry::sum('amount_cents'));
    }

    public function test_bulk_allocation_is_all_or_nothing_when_the_balance_is_short(): void
    {
        $second = Employee::create(['employer_id' => $this->employer->id, 'display_name' => 'Second Employee']);
        $this->ledger()->purchase($this->employer, 15000, 'INV-1');

        try {
            $this->ledger()->allocateMany($this->employer, [$this->employee, $second], 10000, Occasion::Monthly);
            $this->fail('An allocation beyond the balance was allowed.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Buy more credits', $e->getMessage());
        }

        $this->assertSame([0, 15000, 0, 0], [CreditAllocation::count(), $this->employer->balanceCents(), $this->employee->balanceCents(), $second->balanceCents()]);
    }

    public function test_allocation_is_refused_beyond_this_months_budget(): void
    {
        $this->employer->update(['monthly_budget_cents' => 20000]);
        $this->ledger()->purchase($this->employer, 100000, 'INV-1');
        $this->ledger()->allocateMany($this->employer, [$this->employee], 15000, Occasion::Monthly);

        try {
            $this->ledger()->allocateMany($this->employer, [$this->employee], 5001, Occasion::Recognition);
            $this->fail('An allocation beyond the budget was allowed.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('$50.00 is left', $e->getMessage());
        }

        $this->ledger()->allocateMany($this->employer, [$this->employee], 5000, Occasion::Recognition);
        $this->assertSame(0, EmployerProgram::remainingBudgetCents($this->employer));
        $this->assertSame(20000, $this->employee->balanceCents());

        // Last month's allocations don't count against this month.
        $this->assertSame(0, EmployerProgram::allocatedInMonthCents($this->employer, now()->subMonthNoOverflow()));
    }
}
