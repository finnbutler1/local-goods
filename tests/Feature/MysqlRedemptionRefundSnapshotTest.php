<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * On MySQL's default REPEATABLE READ a transaction's snapshot starts at its
 * first plain SELECT, so every lock approving a redemption refund depends on
 * must be taken before anything is read with one. Each test commits a
 * competing change on a second connection just before a lock, where a
 * snapshot taken too early would miss it. Companion to
 * MysqlEmployerRefundSnapshotTest. Runs only in the MySQL CI job.
 */
class MysqlRedemptionRefundSnapshotTest extends TestCase
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
        $this->withoutSeededCorrections();
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_other');
        parent::tearDown();
    }

    /**
     * Two refunds on different redemptions at one merchant: open_key doesn't
     * keep these apart, only the merchant's lock does. The shop is owed
     * enough for one of them. The second must see the first and pull back
     * from its simulated payout, not leave the shop below zero.
     */
    public function test_a_refund_at_the_same_merchant_committed_before_the_lock_is_seen_and_the_merchant_stays_at_zero(): void
    {
        $merchant = $this->farragut();
        $refunding = $this->cleared(1250);
        $other = $this->cleared(1250);
        app(CreditLedger::class)->payout($merchant, $merchant->owedSimulatedCents() - 1250, MerchantPayout::STATUS_SIMULATED);
        $this->assertSame(1250, $merchant->owedSimulatedCents());
        $request = $this->refundRequest($refunding);

        $done = $this->beforeLocking('merchants', fn () => $this->commitCompletedRefund($other));

        app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);

        $this->assertTrue($done(), 'the competing refund never ran');
        $this->assertSame(Correction::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertSame(0, $merchant->fresh()->payableCents(), 'The merchant never goes below zero');
        $this->assertSame(1250, (int) CreditLedgerEntry::where('correction_id', $request->id)->where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sum('amount_cents'), 'Pulled back from its simulated payout');
    }

    /**
     * open_key allows one open request per redemption, and a request only
     * counts once it is claimed, so this state can't come from real use. The
     * test forces it, to guard the order: refunds on this redemption are read
     * after its lock, so no refund can exceed what was paid.
     */
    public function test_a_refund_on_the_same_redemption_committed_before_the_lock_is_seen_and_this_one_is_refused(): void
    {
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);

        $done = $this->beforeLocking('redemptions', fn () => $this->commitCompletedRefund($redemption));

        try {
            app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);
            $this->fail('The refund went through on a redemption that was already fully refunded.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('can still be refunded', $e->getMessage());
        }

        $this->assertTrue($done(), 'the competing refund never ran');
        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status, 'the refused request is still open');
        $this->assertSame(1250, (int) CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REFUND)->sum('amount_cents'), 'Refunded once, no more than was paid');
    }

    /** A transfer reversed in the Stripe dashboard while we wait for the payout's lock: refused, and nothing is reversed again. */
    public function test_a_dashboard_reversal_committed_before_the_payout_lock_is_seen_and_the_refund_is_refused(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $redemption = $this->cleared(1250);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->firstOrFail();
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->status);
        $request = $this->refundRequest($redemption);

        $done = $this->beforeLocking('merchant_payouts', fn () => DB::connection('mysql_other')->table('merchant_payouts')
            ->where('id', $payout->id)->update(['status' => MerchantPayout::STATUS_REVERSED]));

        try {
            app(CorrectionDecisions::class)->approveRedemptionRefund($request, $this->admin(), 'Refund.', amountCents: 1250);
            $this->fail('The refund went ahead on a transfer already reversed in the dashboard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(CorrectionDecisions::REVERSED_IN_DASHBOARD, $e->getMessage());
        }

        $this->assertTrue($done(), 'the competing reversal never ran');
        $this->assertSame(0, CorrectionAction::where('correction_id', $request->id)->count());
        $this->assertSame([], $this->stripe->callsOf('reverseTransfer'));
    }

    /**
     * Runs $change once, on the second connection (so it commits at once),
     * just before this connection locks a row of $table.
     *
     * @return callable(): bool whether it ran
     */
    private function beforeLocking(string $table, callable $change): callable
    {
        $done = false;
        DB::beforeExecuting(function (string $sql) use (&$done, $table, $change) {
            if (! $done && str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update')) {
                $done = true;
                $change();
            }
        });

        return function () use (&$done) {
            return $done;
        };
    }

    /** What another approval leaves behind once it has fully refunded this redemption: a completed correction and its ledger lines. */
    private function commitCompletedRefund(Redemption $redemption): void
    {
        DB::connection('mysql_other')->transaction(function ($other) use ($redemption) {
            $at = now();
            $correction = $other->table('corrections')->insertGetId([
                'type' => Correction::TYPE_REDEMPTION_REFUND, 'status' => Correction::STATUS_COMPLETED, 'open_key' => null,
                'requested_by_role' => 'employee', 'reason_category' => 'wrong_amount', 'amount_cents' => $redemption->amount_cents,
                'redemption_id' => $redemption->id, 'merchant_id' => $redemption->merchant_id, 'employee_id' => $redemption->employee_id,
                'completed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $base = ['redemption_id' => $redemption->id, 'correction_id' => $correction, 'description' => 'A concurrent refund', 'created_at' => $at];
            $other->table('credit_ledger_entries')->insert($base + ['employee_id' => $redemption->employee_id, 'amount_cents' => $redemption->amount_cents, 'type' => CreditLedgerEntry::TYPE_REFUND]);
            $other->table('credit_ledger_entries')->insert($base + ['merchant_id' => $redemption->merchant_id, 'amount_cents' => -$redemption->amount_cents, 'type' => CreditLedgerEntry::TYPE_MERCHANT_REFUNDED]);
        });
    }
}
