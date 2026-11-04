<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * StripeFunding::claim decides which Stripe-paid purchase pays for a cleared
 * redemption, from how much of it is unclaimed. Two employees at one employer
 * don't share an employee lock, only the purchases' lock, so on MySQL that
 * lock must come before the transaction's first plain SELECT: otherwise the
 * snapshot misses a claim that committed while we waited, and one purchase is
 * claimed twice. Each test commits a competing claim on a second connection
 * just before the lock. The last tests pin the lock order, so these locks
 * always queue in the same direction: employer, employee, purchases, then
 * everything else.
 * Runs only in the MySQL CI job.
 */
class MysqlStripeFundingSnapshotTest extends TestCase
{
    use DatabaseMigrations;
    use MakesCorrections;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A snapshot read is only checked against MySQL.');
        }

        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->withoutSeededCorrections();
        $this->onboard();
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    public function test_a_redemption_sees_a_claim_committed_before_the_lock_and_does_not_overclaim_the_only_purchase(): void
    {
        $purchase = $this->stripePurchase(5000, 'ch_ONE');
        $done = $this->competingClaimBeforeTheLock($purchase, 3000);

        $redemption = $this->redeemFor($this->employee(), 3000);

        $this->assertTrue($done(), 'the competing claim never ran');
        $this->assertSame(2000, $purchase->fresh()->unclaimedCents(), 'Only the first claim is on the purchase');
        $this->assertNull(MerchantPayout::where('redemption_id', $redemption->id)->first(), 'Only $20.00 was left, so this one stays simulated');
    }

    public function test_a_redemption_falls_through_to_the_next_purchase_when_the_older_one_was_just_claimed(): void
    {
        $older = $this->stripePurchase(3000, 'ch_OLD');
        $this->travel(1)->minutes();
        $newer = $this->stripePurchase(50000, 'ch_NEW');
        $done = $this->competingClaimBeforeTheLock($older, 3000);

        $redemption = $this->redeemFor($this->employee(), 3000);

        $this->assertTrue($done(), 'the competing claim never ran');
        $this->assertSame(0, $older->fresh()->unclaimedCents());
        $this->assertSame('ch_NEW', MerchantPayout::where('redemption_id', $redemption->id)->value('source_charge_id'));
        $this->assertSame(47000, $newer->fresh()->unclaimedCents());
    }

    public function test_approving_a_held_redemption_sees_a_claim_committed_before_the_lock(): void
    {
        $older = $this->stripePurchase(3000, 'ch_OLD');
        $this->travel(1)->minutes();
        $this->stripePurchase(50000, 'ch_NEW');
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'Round number.'));
        $held = app(RedemptionService::class)->redeem($this->funded($this->employee(), 3000), $this->farragut(), 3000);
        $this->assertTrue($held->isUnderReview());
        $done = $this->competingClaimBeforeTheLock($older, 3000);

        app(RedemptionReviewService::class)->approve($held, $this->admin(), 'Checked with the shop.');

        $this->assertTrue($done(), 'the competing claim never ran');
        $this->assertSame(0, $older->fresh()->unclaimedCents());
        $this->assertSame('ch_NEW', MerchantPayout::where('redemption_id', $held->id)->value('source_charge_id'));
    }

    public function test_a_cleared_redemption_locks_the_employer_then_the_employee_then_the_purchases_before_any_plain_read(): void
    {
        $this->stripePurchase(50000, 'ch_ONE');
        $employee = $this->funded($this->employee(), 1000);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));

        $sql = $this->firstTransactionOf(fn () => app(RedemptionService::class)->redeem($employee, $this->farragut(), 1000));

        $this->assertLocksFirst(['employers', 'employees', 'credit_purchases'], $sql);
    }

    public function test_a_held_redemption_never_locks_the_purchases(): void
    {
        $this->stripePurchase(50000, 'ch_ONE');
        $employee = $this->funded($this->employee(), 1000);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'Round number.'));

        $sql = $this->firstTransactionOf(fn () => app(RedemptionService::class)->redeem($employee, $this->farragut(), 1000));

        $this->assertLocksFirst(['employees'], $sql);
        $this->assertEmpty(array_filter($sql, fn (string $q) => str_contains($q, 'from `credit_purchases`') && str_contains($q, 'for update')));
    }

    public function test_approving_locks_the_employer_then_the_employee_then_the_purchases_before_it_changes_or_reads_anything(): void
    {
        $this->stripePurchase(50000, 'ch_ONE');
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'Round number.'));
        $held = app(RedemptionService::class)->redeem($this->funded($this->employee(), 1000), $this->farragut(), 1000);

        $sql = $this->firstTransactionOf(fn () => app(RedemptionReviewService::class)->approve($held, $this->admin(), 'Checked.'));

        $this->assertLocksFirst(['employers', 'employees', 'credit_purchases'], $sql);
    }

    public function test_a_refund_approval_locks_the_employee_first(): void
    {
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);
        $admin = $this->admin();

        $sql = $this->firstTransactionOf(fn () => app(CorrectionDecisions::class)->approveRedemptionRefund($request, $admin, 'Refund.', amountCents: 1250));

        $this->assertLocksFirst(['employees', 'redemptions', 'merchant_payouts', 'merchants'], $sql);
    }

    public function test_a_stripe_payment_locks_the_employer_before_it_marks_the_purchase_paid(): void
    {
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::query()->orderBy('id')->firstOrFail(), 5000, 'MPG-STRIPE-ORDER');

        $sql = $this->firstTransactionOf(fn () => $ledger->markPurchasePaidByStripe($purchase, 'pi_order', 'ch_order', $purchase->totalCents(), 'usd'));

        $this->assertStringContainsString('from `employers`', $sql[0]);
        $this->assertStringContainsString('for update', $sql[0]);
        $this->assertStringContainsString('update `credit_purchases`', $sql[1]);
    }

    public function test_a_new_purchase_locks_the_employer_before_it_is_inserted(): void
    {
        $sql = $this->firstTransactionOf(fn () => app(CreditLedger::class)->requestPurchase(Employer::query()->orderBy('id')->firstOrFail(), 5000, 'MPG-STRIPE-NEW'));

        $this->assertLocksFirst(['employers'], $sql);
        $this->assertStringContainsString('insert into `credit_purchases`', $sql[1]);
    }

    /** A synthetic employee who is not Jordan and not the shopper the helpers use. */
    private function employee(): Employee
    {
        return Employee::orderBy('id')->skip(3)->firstOrFail();
    }

    private function funded(Employee $employee, int $cents): Employee
    {
        if (($short = $cents - $employee->balanceCents()) > 0) {
            app(CreditLedger::class)->allocate($employee->employer, $employee, $short);
        }

        return $employee->fresh();
    }

    private function redeemFor(Employee $employee, int $cents): Redemption
    {
        $employee = $this->funded($employee, $cents);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));

        return app(RedemptionService::class)->redeem($employee, $this->farragut(), $cents);
    }

    /**
     * Another employee's redemption clears and commits (on the second
     * connection) just before this connection locks the purchases. It leaves
     * what a real one leaves: the redemption, its ledger lines and its claim.
     *
     * @return callable(): bool whether it ran
     */
    private function competingClaimBeforeTheLock(CreditPurchase $purchase, int $cents): callable
    {
        $merchant = $this->farragut();
        $done = false;
        DB::beforeExecuting(function (string $sql) use (&$done, $purchase, $cents, $merchant) {
            if ($done || ! str_contains($sql, 'from `credit_purchases`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $done = true;

            DB::connection('mysql_other')->transaction(function ($other) use ($purchase, $cents, $merchant) {
                $at = now();
                $employee = $other->table('employees')->orderBy('id')->skip(2)->value('id');
                $redemption = $other->table('redemptions')->insertGetId(['employee_id' => $employee, 'merchant_id' => $merchant->id, 'amount_cents' => $cents,
                    'status' => Redemption::STATUS_APPROVED, 'risk_score' => 5, 'ai_decision' => 'clear', 'ai_explanation' => 'A concurrent redemption', 'scored_by' => 'ai', 'created_at' => $at, 'updated_at' => $at]);
                $base = ['redemption_id' => $redemption, 'description' => 'A concurrent redemption', 'created_at' => $at];
                $other->table('credit_ledger_entries')->insert($base + ['employee_id' => $employee, 'amount_cents' => -$cents, 'type' => CreditLedgerEntry::TYPE_REDEMPTION]);
                $other->table('credit_ledger_entries')->insert($base + ['merchant_id' => $merchant->id, 'amount_cents' => $cents, 'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED]);
                $other->table('merchant_payouts')->insert(['merchant_id' => $merchant->id, 'amount_cents' => $cents, 'fee_cents' => 0, 'status' => MerchantPayout::STATUS_OWED,
                    'redemption_id' => $redemption, 'credit_purchase_id' => $purchase->id, 'transfer_group' => $purchase->reference,
                    'source_charge_id' => $purchase->stripe_charge_id, 'created_at' => $at, 'updated_at' => $at]);
            });
        });

        return function () use (&$done) {
            return $done;
        };
    }

    /**
     * The SQL of the first transaction $work opens.
     *
     * @return list<string>
     */
    private function firstTransactionOf(callable $work): array
    {
        $transactions = 0;
        $sql = [];
        Event::listen(TransactionBeginning::class, function () use (&$transactions) {
            $transactions++;
        });
        DB::beforeExecuting(function (string $query) use (&$transactions, &$sql) {
            if ($transactions === 1) {
                $sql[] = $query;
            }
        });

        $work();

        return $sql;
    }

    /**
     * The transaction's first statements are locks on these tables, in this
     * order, so nothing is read or changed before them.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $sql
     */
    private function assertLocksFirst(array $tables, array $sql): void
    {
        foreach ($tables as $i => $table) {
            $this->assertStringContainsString("from `{$table}`", $sql[$i] ?? '', 'Lock '.($i + 1).' should be on '.$table);
            $this->assertStringContainsString('for update', $sql[$i] ?? '', 'Lock '.($i + 1).' should be on '.$table);
        }
    }
}
