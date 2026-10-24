<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\JudgeController;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\TestCase;

/**
 * The Corrections queue (ADR 0028): the platform admin decides, always with
 * a reason; the BID admin and judges see it read-only. Each decision's toast
 * names the amount, the place and the person by initials.
 */
class CorrectionQueueTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_platform_admin_approves_a_refund_from_the_queue(): void
    {
        $redemption = $this->cleared(1250);
        $request = $this->refundRequest($redemption);
        $this->actingAs($this->admin());

        $this->get(route('bid-admin.corrections'))->assertOk()
            ->assertSee('How money mistakes get fixed')
            ->assertSee('Redemption refund')
            ->assertSee('No Stripe transfer behind it (simulated)')
            ->assertSee('action="'.route('bid-admin.corrections.approve', $request).'"', false);

        $this->post(route('bid-admin.corrections.approve', $request), ['amount' => '4.50', 'reason' => 'Charged for a large, served a small.'])
            ->assertRedirect(route('bid-admin.corrections').'#correction-'.$request->id)
            ->assertSessionHas('status', 'Redemption refund of $4.50 for '.$redemption->employee->initials().' at Farragut Grind completed (simulated, no real money).');

        $this->assertSame([Correction::STATUS_COMPLETED, 450], [$request->fresh()->status, $request->fresh()->amount_cents]);
        $this->get(route('bid-admin.corrections'))->assertSee('“Charged for a large, served a small.”', false);
    }

    public function test_an_itemized_refund_is_chosen_by_item(): void
    {
        $redemption = $this->clearedOrder(['Latte' => 2]);
        $request = $this->refundRequest($redemption);
        $latte = $redemption->items->sole();
        $this->actingAs($this->admin());

        $this->get(route('bid-admin.corrections'))->assertSee('name="items['.$latte->id.']"', false)->assertSee('(2 left)');
        $this->post(route('bid-admin.corrections.approve', $request), ['items' => [$latte->id => 1], 'reason' => 'One latte was wrong.'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '$6.33'));
        $this->assertSame([575, 58], [$request->fresh()->subtotal_cents, $request->fresh()->tax_cents]);
    }

    public function test_a_reason_is_required_and_refusals_are_explained(): void
    {
        $request = $this->refundRequest($this->cleared(1250));
        $this->actingAs($this->admin());

        $this->post(route('bid-admin.corrections.approve', $request), ['amount' => '4.50', 'reason' => 'ok'])
            ->assertSessionHasErrorsIn('correction'.$request->id, ['reason']);
        $this->post(route('bid-admin.corrections.approve', $request), ['amount' => '99.00', 'reason' => 'Refund everything.'])
            ->assertSessionHas('error', 'Nothing changed: Only $12.50 of this redemption can still be refunded.');
        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);

        $this->post(route('bid-admin.corrections.reject', $request), ['reason' => 'The receipt matches what was charged.'])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Not approved:'));
        $this->assertSame(Correction::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_the_bid_admin_and_judges_see_the_queue_read_only(): void
    {
        $request = $this->refundRequest($this->cleared(1250));
        $ledger = CreditLedgerEntry::count();

        foreach ([Role::BidAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get(route('bid-admin.corrections'))->assertOk()
                ->assertSee('Only the Local Goods platform admin approves, rejects, retries or closes corrections.')
                ->assertDontSee('action="'.route('bid-admin.corrections.approve', $request).'"', false);
            $this->post(route('bid-admin.corrections.approve', $request), ['amount' => '1.00', 'reason' => 'Trying to refund.'])->assertForbidden();
            $this->post(route('bid-admin.corrections.reject', $request), ['reason' => 'Trying to reject.'])->assertForbidden();
        }

        $this->assertSame(Correction::STATUS_REQUESTED, $request->fresh()->status);
        $this->assertSame($ledger, CreditLedgerEntry::count());
    }

    public function test_the_seeded_examples_show_in_the_decided_list(): void
    {
        $this->signIn(Role::Judge);

        $this->get(route('bid-admin.corrections'))->assertOk()
            ->assertSee('Decided')
            ->assertSee('data-correction-row="completed"', false)
            ->assertSee('data-correction-row="rejected"', false)
            ->assertSee('The shop confirmed the cold brew was never made');
    }

    public function test_the_tour_and_the_admin_pages_link_to_corrections(): void
    {
        $step = collect(JudgeController::tour())->firstWhere('title', 'See how mistakes get fixed: open Corrections');
        $this->assertSame(route('bid-admin.corrections'), $step['url']);

        $this->signIn(Role::BidAdmin);
        $this->get(route('bid-admin.dashboard'))->assertSee(route('bid-admin.corrections'));
    }
}
