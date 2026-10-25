<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MerchantFeeChange;
use App\Models\User;
use App\Services\MerchantFeeSettings;
use App\Support\MerchantFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\SetsMerchantFee;
use Tests\TestCase;

/**
 * Fee settings (ADR 0029): only the platform admin changes the fee, within
 * the cap, with a reason, in two steps (review writes nothing, then
 * confirm against the reviewed setting). Every change is an audit row;
 * the BID admin and judges see it read-only.
 */
class MerchantFeeSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SetsMerchantFee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('role', Role::PlatformAdmin)->first() ?? User::factory()->role(Role::PlatformAdmin)->create(['name' => 'Pat Admin']);
    }

    public function test_the_platform_admin_reviews_then_confirms_a_change(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get(route('bid-admin.fees'))->assertOk()
            ->assertSee('None right now')
            ->assertSee('No changes yet')
            ->assertSee('action="'.route('bid-admin.fees.review').'"', false);

        // Step 1 writes nothing.
        $this->post(route('bid-admin.fees.review'), ['percent' => '2.5', 'fixed' => '0', 'reason' => 'Trying a fee for the demo.'])
            ->assertOk()
            ->assertSee('Check the change')
            ->assertSee('2.5% per sale')
            ->assertSee('Fee $0.00 → $0.16; the merchant receives $6.17')
            ->assertSee('Applies to new redemptions only.')
            ->assertSee('name="reviewed_change_id" value="0"', false);
        $this->assertSame(0, MerchantFeeChange::count());

        // Step 2 without the box ticked writes nothing either.
        $this->post(route('bid-admin.fees.store'), ['bps' => 250, 'fixed_cents' => 0, 'reason' => 'Trying a fee for the demo.', 'reviewed_change_id' => 0])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(0, MerchantFeeChange::count());

        $this->post(route('bid-admin.fees.store'), ['bps' => 250, 'fixed_cents' => 0, 'reason' => 'Trying a fee for the demo.', 'reviewed_change_id' => 0, 'confirm' => '1'])
            ->assertRedirect(route('bid-admin.fees'))
            ->assertSessionHas('status', 'Local Goods fee changed from none to 2.5% per sale. Applies to new redemptions only.');

        $change = MerchantFeeChange::sole();
        $this->assertSame([250, 0, 0, 0, 'Trying a fee for the demo.', $admin->id],
            [$change->fee_bps, $change->fixed_cents, $change->previous_bps, $change->previous_fixed_cents, $change->reason, $change->changed_by_user_id]);
        $this->assertSame(250, MerchantFee::current()->bps);

        // The audit log: when, who by initials, old → new, why. Then back to 0.
        $this->get(route('bid-admin.fees'))->assertOk()
            ->assertSee('2.5% per sale')
            ->assertSee(User::initialsOf($admin->name))
            ->assertSee('None → 2.5% per sale')
            ->assertSee('Trying a fee for the demo.');

        $this->post(route('bid-admin.fees.store'), ['bps' => 0, 'fixed_cents' => 0, 'reason' => 'Back to no fee.', 'reviewed_change_id' => $change->id, 'confirm' => '1'])
            ->assertSessionHas('status', 'Local Goods fee changed from 2.5% per sale to none. Applies to new redemptions only.');
        $this->assertTrue(MerchantFee::current()->isNone());
        $this->assertSame(2, MerchantFeeChange::count());
    }

    public function test_a_stale_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin());
        $this->setMerchantFee(100); // someone else changed it after this admin reviewed against "none" (id 0)

        $this->post(route('bid-admin.fees.store'), ['bps' => 250, 'fixed_cents' => 0, 'reason' => 'Reviewed earlier.', 'reviewed_change_id' => 0, 'confirm' => '1'])
            ->assertRedirect(route('bid-admin.fees'))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'changed since you reviewed this'));
        $this->assertSame(100, MerchantFee::current()->bps);
        $this->assertSame(1, MerchantFeeChange::count());
    }

    public function test_the_cap_is_enforced_at_every_step(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('bid-admin.fees.review'), ['percent' => '10.01', 'fixed' => '0', 'reason' => 'Too much.'])->assertSessionHasErrors('percent');
        $this->post(route('bid-admin.fees.review'), ['percent' => '0', 'fixed' => '1.01', 'reason' => 'Too much.'])->assertSessionHasErrors('fixed');
        $this->post(route('bid-admin.fees.review'), ['percent' => '-1', 'fixed' => '0', 'reason' => 'Negative.'])->assertSessionHasErrors('percent');
        $this->post(route('bid-admin.fees.review'), ['percent' => '2.5', 'fixed' => '0', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('bid-admin.fees.review'), ['percent' => '10', 'fixed' => '1.00', 'reason' => 'At the cap.'])->assertOk()->assertSee('10% + $1.00 per sale');

        $this->post(route('bid-admin.fees.store'), ['bps' => 1001, 'fixed_cents' => 0, 'reason' => 'Too much.', 'reviewed_change_id' => 0, 'confirm' => '1'])->assertSessionHasErrors('bps');
        $this->post(route('bid-admin.fees.store'), ['bps' => 0, 'fixed_cents' => 101, 'reason' => 'Too much.', 'reviewed_change_id' => 0, 'confirm' => '1'])->assertSessionHasErrors('fixed_cents');

        try {
            app(MerchantFeeSettings::class)->change($this->admin(), 1001, 0, 'Straight to the service.', 0);
            $this->fail('The service took a fee over the cap.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at most 10%', $e->getMessage());
        }

        $this->assertSame(0, MerchantFeeChange::count());
    }

    public function test_only_the_platform_admin_can_change_it(): void
    {
        $this->setMerchantFee(250);

        foreach ([Role::BidAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get(route('bid-admin.fees'))->assertOk()
                ->assertSee('Only the Local Goods platform admin changes the fee.')
                // Judges count as the public: no fee figures for them (PublicClaimsTest); the BID admin sees the rate.
                ->{$role === Role::Judge ? 'assertDontSee' : 'assertSee'}('2.5%')
                ->assertDontSee('action="'.route('bid-admin.fees.review').'"', false);
            $this->post(route('bid-admin.fees.review'), ['percent' => '2.5', 'fixed' => '0', 'reason' => 'Trying.'])->assertForbidden();
            $this->post(route('bid-admin.fees.store'), ['bps' => 250, 'fixed_cents' => 0, 'reason' => 'Trying.', 'reviewed_change_id' => 0, 'confirm' => '1'])->assertForbidden();
        }

        foreach ([Role::Merchant, Role::EmployerAdmin, Role::Employee] as $role) {
            $this->signIn($role);
            $this->get(route('bid-admin.fees'))->assertForbidden();
            $this->post(route('bid-admin.fees.store'), ['bps' => 250, 'fixed_cents' => 0, 'reason' => 'Trying.', 'reviewed_change_id' => 0, 'confirm' => '1'])->assertForbidden();
        }

        $this->expectException(LogicException::class);
        app(MerchantFeeSettings::class)->change(User::where('role', Role::BidAdmin)->firstOrFail(), 250, 0, 'Not allowed.', 0);
    }

    public function test_setting_the_same_fee_again_writes_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MerchantFeeSettings::class)->change($this->admin(), 0, 0, 'Already none.', 0);
    }

    public function test_the_admin_pages_link_to_it(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard'))->assertSee(route('bid-admin.fees'));
        $this->signIn(Role::BidAdmin);
        $this->get(route('bid-admin.dashboard'))->assertSee(route('bid-admin.fees'));
    }
}
