<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\PlacesMerchantsInTheDistrict;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\SetsMerchantFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The README's promise about a fraud we miss: it is capped by the credits
 * loaded onto one employee. In code terms, on every way credits can be spent:
 *
 *  1. An employee can never spend more than their balance (the ledger sum)
 *     at the moment of spending.
 *  2. An employee's balance never goes below zero.
 *  3. A merchant is credited only for credits an employee actually spent,
 *     and never more than that amount (a fee only moves money from the
 *     merchant to the platform).
 *
 * So the most one missed fraud can move is what is on that employee's
 * wallet; over time, what employers have allocated to them, less returns.
 * This is enforced by the code (the redemption locks the employee row and
 * re-checks the balance inside the lock). There is no database constraint
 * behind it. The concurrency case at the bottom runs only on MySQL (the CI
 * "tests-mysql" job), like MysqlRedemptionLockTest; everything else runs on
 * SQLite and MySQL. DatabaseMigrations (committed rows, not an open
 * transaction) is what lets a second connection see and lock the rows.
 */
class MissedFraudLossCapTest extends TestCase
{
    use DatabaseMigrations;
    use MakesCorrections;
    use PlacesMerchantsInTheDistrict;
    use SeedsOnAFixedDate;
    use SetsMerchantFee;
    use UsesFakeStripe;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->withoutSeededCorrections();
        $this->employee = $this->shopper();

        // Enough credits for every case below, loaded the normal way.
        if (($short = 20000 - $this->balance()) > 0) {
            $ledger = app(CreditLedger::class);
            $ledger->payPurchase($ledger->requestPurchase(Employer::firstOrFail(), $short, 'LOSS-CAP-TOP-UP'));
            $ledger->allocate(Employer::firstOrFail(), $this->employee, $short);
        }
    }

    protected function tearDown(): void
    {
        // The invariant holds after every test, whatever the test did.
        if ($this->app && DB::getSchemaBuilder()->hasTable('employees')) {
            $this->assertNoNegativeBalancesAndNoOverpaidMerchant();
        }
        DB::purge('mysql_other');
        parent::tearDown();
    }

    private function assertNoNegativeBalancesAndNoOverpaidMerchant(): void
    {
        foreach (Employee::all() as $employee) {
            $this->assertGreaterThanOrEqual(0, $employee->balanceCents(), "Employee {$employee->id} has a negative balance.");
        }

        foreach (Merchant::all() as $merchant) {
            $earned = (int) CreditLedgerEntry::where('merchant_id', $merchant->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents');
            $spent = (int) Redemption::where('merchant_id', $merchant->id)->where('status', Redemption::STATUS_APPROVED)->sum('amount_cents');
            $this->assertLessThanOrEqual($spent, $earned, "{$merchant->name} was credited more than employees spent.");
        }
    }

    private function redeem(int $cents, string $decision = 'clear', ?Merchant $merchant = null): Redemption
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'hold' ? 90 : 10, $decision));

        return app(RedemptionService::class)->redeem($this->employee->fresh(), $merchant ?? $this->farragut(), $cents);
    }

    private function balance(): int
    {
        return $this->employee->fresh()->balanceCents();
    }

    /** Runs the call and returns the first validation message, failing if it was allowed. */
    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $this->fail('Expected the spend to be refused.');
    }

    private function reviewer(): User
    {
        return User::factory()->role(Role::BidAdmin)->create();
    }

    // --- Ordering an item to go ------------------------------------------

    public function test_to_go_cannot_spend_more_than_the_balance_and_the_whole_balance_is_allowed(): void
    {
        $balance = $this->balance();
        $this->assertGreaterThan(0, $balance);

        $this->assertSame('That is more than the available balance.', $this->refusal(fn () => $this->redeem($balance + 1)));
        $this->assertSame($balance, $this->balance(), 'a refused spend moves nothing');

        $this->redeem($balance);
        $this->assertSame(0, $this->balance());
        $this->refusal(fn () => $this->redeem(1));
        $this->assertSame(0, $this->balance());
    }

    public function test_two_redemptions_at_the_same_moment_cannot_spend_the_balance_twice(): void
    {
        $balance = $this->balance();
        $nested = false;
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(function () use (&$nested, $balance) {
            if (! $nested) { // the second request lands while the first waits for the AI
                $nested = true;
                app(RedemptionService::class)->redeem($this->employee->fresh(), $this->farragut(), $balance);
            }

            return json_encode(['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks normal.']);
        }));
        $before = Redemption::count();

        $this->refusal(fn () => app(RedemptionService::class)->redeem($this->employee->fresh(), $this->farragut(), $balance));

        $this->assertSame($before + 1, Redemption::count());
        $this->assertSame(0, $this->balance());
    }

    // --- Paying in person --------------------------------------------------

    public function test_a_pay_code_cannot_be_issued_for_more_than_the_balance(): void
    {
        $balance = $this->balance();
        $entries = CreditLedgerEntry::count();

        $this->refusal(fn () => app(PayCodes::class)->issue($this->employee, $this->farragut(), $balance + 1));
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame($balance, $this->balance());
    }

    public function test_a_pay_code_and_an_order_to_go_together_cannot_exceed_the_balance(): void
    {
        $balance = $this->balance();
        $codes = app(PayCodes::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));

        $payCode = $codes->issue($this->employee, $this->farragut(), $balance); // reserves nothing
        $this->redeem($balance); // the order to go takes it all first

        $this->refusal(fn () => $codes->confirm($payCode->fresh(), $this->farragut()));

        $this->assertSame(0, $this->balance());
        $this->assertSame(1, Redemption::where('employee_id', $this->employee->id)->where('amount_cents', $balance)->count());
    }

    public function test_confirming_a_pay_code_spends_it_once_and_never_below_zero(): void
    {
        $balance = $this->balance();
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $payCode = app(PayCodes::class)->issue($this->employee, $this->farragut(), $balance);

        app(PayCodes::class)->confirm($payCode->fresh(), $this->farragut());
        $this->assertSame(0, $this->balance());

        $this->refusal(fn () => app(PayCodes::class)->confirm($payCode->fresh(), $this->farragut())); // single use
        $this->assertSame(0, $this->balance());
    }

    // --- A held payment decided later -------------------------------------

    public function test_a_held_payment_keeps_its_credits_out_of_reach_and_deciding_never_creates_credits(): void
    {
        $start = $this->balance();
        $held = $this->redeem(4000, 'hold');
        $this->assertFalse($held->isApproved());
        $this->assertSame($start - 4000, $this->balance(), 'a hold keeps the credits spent while it waits');
        $this->refusal(fn () => $this->redeem($this->balance() + 1)); // cannot spend them again

        app(RedemptionReviewService::class)->approve($held, $this->reviewer(), 'Looks fine after a check.');
        $this->assertSame($start - 4000, $this->balance(), 'approving moves no employee credits');

        $this->assertSame(0, (int) CreditLedgerEntry::where('merchant_id', $held->merchant_id)->where('redemption_id', $held->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents') - 4000);
    }

    public function test_denying_a_held_payment_returns_at_most_what_was_spent_and_only_once(): void
    {
        $start = $this->balance();
        $held = $this->redeem(4000, 'hold');

        app(RedemptionReviewService::class)->deny($held, $this->reviewer(), 'No purchase was made.', DenialReason::NoPurchase);
        $this->assertSame($start, $this->balance());

        try {
            app(RedemptionReviewService::class)->deny($held->fresh(), $this->reviewer(), 'Again.', DenialReason::NoPurchase);
        } catch (\Throwable) {
            // refused: already decided
        }
        $this->assertSame($start, $this->balance(), 'a second decision returns nothing more');
    }

    public function test_denying_for_suspected_abuse_sends_the_credits_to_the_employer_not_the_employee(): void
    {
        $start = $this->balance();
        $held = $this->redeem(4000, 'hold');

        app(RedemptionReviewService::class)->deny($held, $this->reviewer(), 'Looks like cashing out.', DenialReason::SuspectedAbuse);

        $this->assertSame($start - 4000, $this->balance());
    }

    public function test_when_the_ai_fails_the_payment_is_held_and_the_balance_still_adds_up(): void
    {
        $start = $this->balance();
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(fn () => throw new \RuntimeException('API down')));

        $redemption = app(RedemptionService::class)->redeem($this->employee->fresh(), $this->farragut(), 2500);

        $this->assertFalse($redemption->isApproved(), 'fails closed');
        $this->assertSame($start - 2500, $this->balance());
    }

    // --- Menu baskets with tax --------------------------------------------

    public function test_a_taxed_menu_order_cannot_spend_more_than_the_balance(): void
    {
        $merchant = $this->farragut();
        $order = ['Avocado toast' => 1, 'Cold brew' => 1];
        $ids = $merchant->products()->whereIn('name', array_keys($order))->pluck('id', 'name');
        $basket = RedemptionBasket::build($merchant, collect($order)->mapWithKeys(fn ($q, $name) => [$ids[$name] => $q])->all(), 0);
        $total = $basket['total_cents'];
        $this->assertGreaterThan(0, $total);

        $this->redeem($this->balance() - ($total - 1)); // leave one cent less than the order
        $left = $this->balance();
        $this->assertSame($total - 1, $left);

        $this->refusal(fn () => $this->clearedOrder($order));
        $this->assertSame($left, $this->balance());
    }

    // --- The merchant fee ---------------------------------------------------

    public function test_with_a_merchant_fee_the_merchant_receives_no_more_than_was_spent(): void
    {
        $this->setMerchantFee(1000, 100);
        $redemption = $this->redeem(5000);

        $entries = CreditLedgerEntry::where('redemption_id', $redemption->id)->whereNotNull('merchant_id')->sum('amount_cents');
        $this->assertGreaterThanOrEqual(0, (int) $entries);
        $this->assertLessThanOrEqual(5000, (int) $entries);
        $this->assertSame(0, (int) CreditLedgerEntry::where('redemption_id', $redemption->id)->whereIn('type', [CreditLedgerEntry::TYPE_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE])->sum('amount_cents'), 'the fee only moves money between merchant and platform');
    }

    // --- The district rule ---------------------------------------------------

    public function test_a_shop_outside_the_program_is_refused_before_anything_is_written(): void
    {
        $outside = Merchant::create(['bid_id' => Bid::value('id'), 'name' => 'Outside Shop', 'category' => 'cafe', 'address' => '1 Test St', ...$this->inTheDistrict(), 'live_at' => null]);
        $before = [Redemption::count(), CreditLedgerEntry::count(), $this->balance()];

        $this->refusal(fn () => $this->redeem(500, 'clear', $outside));
        $this->refusal(fn () => app(PayCodes::class)->issue($this->employee, $outside, 500));

        $this->assertSame($before, [Redemption::count(), CreditLedgerEntry::count(), $this->balance()]);
    }

    // --- A refund, then spending again ---------------------------------------

    public function test_a_refund_then_spending_again_never_goes_past_the_credits_loaded(): void
    {
        $start = $this->balance();
        $redemption = $this->redeem(3000);
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Wrong amount.', amountCents: 3000);

        $this->assertSame($start, $this->balance(), 'the refund gives back exactly what was spent');
        $this->assertSame(2, CreditLedgerEntry::where('redemption_id', $redemption->id)->whereIn('type', [CreditLedgerEntry::TYPE_REDEMPTION, CreditLedgerEntry::TYPE_REFUND])->count());

        $this->redeem($start); // spend it all again
        $this->assertSame(0, $this->balance());
        $this->refusal(fn () => $this->redeem(1));
    }

    // --- Two requests at once, on MySQL ------------------------------------------

    public function test_on_mysql_a_second_request_waits_for_the_first_and_cannot_overspend(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking is only checked against MySQL (the MySQL CI job).');
        }

        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
        $balance = $this->balance();

        // Another request is mid-redemption for the same employee and holds the row.
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->table('employees')->where('id', $this->employee->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->redeem($balance);
            $this->fail('The redemption should have waited for the other lock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }
        $this->assertSame($balance, $this->balance(), 'a request that could not get the lock spent nothing');

        // The first request finishes and spends everything; the second now sees that.
        $this->redeem($balance);
        $this->refusal(fn () => $this->redeem(1));
        $this->assertSame(0, $this->balance());
    }
}
