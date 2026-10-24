<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Services\DemoResetter;
use App\Services\MerchantTaxSummary;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCorrections;
use Tests\TestCase;

/**
 * The seeded corrections (ADR 0028): one completed partial refund of a
 * taxed order and one rejected request, at Farragut Grind, by seeded
 * employees, never Jordan. Reset demo puts them back; a live demo seeded
 * before them keeps working with none.
 */
class CorrectionsDemoTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_one_completed_partial_refund_and_one_rejected_request(): void
    {
        $jordan = Employee::orderBy('id')->firstOrFail();
        [$refund, $rejected] = [Correction::where('status', Correction::STATUS_COMPLETED)->sole(), Correction::where('status', Correction::STATUS_REJECTED)->sole()];

        foreach ([$refund, $rejected] as $c) {
            $this->assertSame('Farragut Grind', $c->merchant->name);
            $this->assertNotSame($jordan->id, $c->employee_id, 'Never Jordan');
            $this->assertNull($c->open_key);
            $this->assertNotEmpty($c->decision_reason);
        }

        $order = $refund->redemption;
        $this->assertSame(['1× Cold brew'], $refund->items->map(fn ($i) => $i->quantity.'× '.$i->redemptionItem->name)->all());
        $this->assertLessThan($order->amount_cents, $refund->amount_cents, 'A partial refund');
        $this->assertSame(525 + 53, $refund->amount_cents);
        $this->assertSame([525, 53], [$refund->subtotal_cents, $refund->tax_cents]);
        $this->assertSame([[CreditLedgerEntry::TYPE_REFUND, 578], [CreditLedgerEntry::TYPE_MERCHANT_REFUNDED, -578]],
            $refund->ledgerEntries()->orderBy('id')->get()->map(fn ($l) => [$l->type, $l->amount_cents])->all());
        $this->assertSame(0, $rejected->ledgerEntries()->count());
        $this->assertSame(DemoHistorySeeder::IN_PERSON_MERCHANT, $rejected->redemption->merchant->name);

        $month = $refund->completed_at->isSameMonth($order->created_at) ? 'this_month' : 'last_month';
        $this->assertSame(53, MerchantTaxSummary::for($this->farragut(), $refund->completed_at)['this_month']['refunded_tax_cents'], $month);
        $this->assertLedgerInvariant();
    }

    public function test_reset_demo_restores_them(): void
    {
        $before = Correction::orderBy('id')->get(['type', 'status', 'amount_cents', 'tax_cents'])->toArray();
        Correction::query()->update(['decision_reason' => 'changed']);

        app(DemoResetter::class)->reset();

        $this->assertSame($before, Correction::orderBy('id')->get(['type', 'status', 'amount_cents', 'tax_cents'])->toArray());
        $this->assertSame(0, Correction::where('decision_reason', 'changed')->count());
    }

    public function test_a_demo_seeded_before_corrections_is_left_alone(): void
    {
        DB::table('credit_ledger_entries')->whereNotNull('correction_id')->delete();
        foreach (['correction_actions', 'correction_items', 'corrections'] as $table) {
            DB::table($table)->delete();
        }
        $ledger = CreditLedgerEntry::count();

        $this->artisan('demo:seed-if-empty')->assertSuccessful();

        $this->assertSame(0, Correction::count(), 'The live demo has none until someone asks');
        $this->assertSame($ledger, CreditLedgerEntry::count());
        $this->signIn(Role::Judge);
        $this->get(route('bid-admin.corrections'))->assertOk()->assertSee('No open requests.')->assertSee('Nothing decided yet.');
    }
}
