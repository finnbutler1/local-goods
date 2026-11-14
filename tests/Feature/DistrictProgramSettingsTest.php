<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Models\DistrictProgramChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Concerns\PaysBidProgramFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * A district's member businesses and program start date (ADR 0044): set by
 * the platform admin only, never the BID, each change logged with who, when,
 * old → new and the reason, in an append-only table. The start date is fixed
 * once a year is paid.
 */
class DistrictProgramSettingsTest extends TestCase
{
    use PaysBidProgramFee;
    use RefreshDatabase;
    use UsesFakeStripe;

    private function set(array $data)
    {
        return $this->post(route('bid-admin.program-fee.district'), $data + ['member_businesses' => 6000, 'program_starts_on' => '2026-10-01', 'reason' => 'From the BID\'s published member count.']);
    }

    public function test_each_change_writes_one_audit_row_with_old_and_new_values_who_and_why(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);

        $this->set([])->assertRedirect(route('bid-admin.program-fee'))->assertSessionHas('status');
        $this->set(['member_businesses' => 3500, 'reason' => 'Corrected count.'])->assertSessionHas('status');

        $district = District::default();
        $this->assertSame([3500, '2026-10-01'], [$district->member_businesses, $district->program_starts_on->format('Y-m-d')]);

        [$second, $first] = $district->programChanges()->get()->all();
        $this->assertSame([null, 6000, null, '2026-10-01', $admin->id, 'From the BID\'s published member count.'],
            [$first->previous_member_businesses, $first->member_businesses, $first->previous_program_starts_on, $first->program_starts_on->format('Y-m-d'), $first->changed_by_user_id, $first->reason]);
        $this->assertSame([6000, 3500, '2026-10-01', '2026-10-01', 'Corrected count.'],
            [$second->previous_member_businesses, $second->member_businesses, $second->previous_program_starts_on->format('Y-m-d'), $second->program_starts_on->format('Y-m-d'), $second->reason]);
    }

    public function test_a_change_needs_a_reason_and_real_values(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->set(['reason' => ''])->assertSessionHasErrors('reason');
        $this->set(['member_businesses' => 0])->assertSessionHasErrors('member_businesses');
        $this->set(['program_starts_on' => '2026-02-30'])->assertSessionHasErrors('program_starts_on');
        $this->assertSame(0, DistrictProgramChange::count());
        $this->assertNull(District::default()->member_businesses);
    }

    public function test_audit_rows_are_never_edited_or_deleted(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->set([]);
        $change = DistrictProgramChange::sole();

        try {
            $change->update(['reason' => 'rewritten']);
            $this->fail('An audit row was edited.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $change->delete();
    }

    public function test_only_the_platform_admin_sets_them_never_the_bid_or_a_judge(): void
    {
        foreach ([Role::BidAdmin, Role::Judge, Role::EmployerAdmin] as $role) {
            $this->signIn($role);
            $this->set([])->assertForbidden();
        }
        $this->assertSame(0, DistrictProgramChange::count());
        $this->assertNull(District::default()->member_businesses);
    }

    public function test_the_start_date_is_fixed_once_a_year_is_paid_but_the_count_can_change(): void
    {
        $this->fakeStripe();
        $this->switchOnBidFee();
        $this->signIn(Role::PlatformAdmin);
        $this->set([]);
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
        $this->signIn(Role::BidAdmin);
        $this->post(route('bid-admin.program-fee.pay'));
        $this->payBidFee(BidProgramFeePayment::sole());

        $this->signIn(Role::PlatformAdmin);
        $this->set(['program_starts_on' => '2026-11-01'])->assertSessionHas('error', fn ($m) => str_contains($m, 'start date can\'t change'));
        $this->assertSame('2026-10-01', District::default()->program_starts_on->format('Y-m-d'));

        $this->set(['member_businesses' => 2000, 'reason' => 'Fewer members this year.'])->assertSessionHas('status');
        $this->assertSame(500000, BidProgramFeePayment::sole()->amount_cents, 'A paid year keeps its own tier and amount.');
    }

    public function test_the_change_history_is_shown_to_the_platform_admin_only(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->set([]);
        $this->get(route('bid-admin.program-fee'))->assertOk()->assertSee('data-change-history', false)->assertSee('From the BID&#039;s published member count.', false);

        foreach ([Role::BidAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get(route('bid-admin.program-fee'))->assertOk()->assertDontSee('data-change-history', false)->assertDontSee('published member count');
        }
    }
}
