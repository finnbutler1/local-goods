<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Partnership;
use App\Models\PartnershipChange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Partnership page (ADR 0031): BID admin and judges read it (judges
 * without figures), only the platform admin changes it, review writes
 * nothing and confirm is checked against the reviewed version.
 */
class PartnershipPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return [
            'status' => 'active', 'exclusive' => '1', 'starts_on' => '2026-10-01', 'ends_on' => '',
            'revenue_share' => '10', 'data_terms' => Partnership::DEFAULT_DATA_TERMS, 'reason' => 'Agreed with the BID', ...$overrides,
        ];
    }

    public function test_the_bid_admin_reads_the_terms_the_share_and_the_log_and_can_change_it(): void
    {
        $user = $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/partnership')->assertOk()
            ->assertSee('Demo partnership — not an actual agreement.')
            ->assertSee('Exclusive')
            ->assertSee('0% of what Local Goods earns in the district')
            ->assertSee('$0.00')
            ->assertSee('Every change')
            ->assertSee('Review the change')
            ->assertDontSee('Read-only.');

        $this->post('/bid-admin/partnership/review', $this->form())->assertOk()->assertSee('Check the change');
        $this->post('/bid-admin/partnership', [...$this->form(), 'revenue_share_bps' => 1000, 'reviewed_change_id' => Partnership::sole()->latestChangeId(), 'confirm' => '1'])
            ->assertSessionHas('status');
        $this->assertSame(1000, Partnership::sole()->revenue_share_bps);
        $this->assertSame($user->id, PartnershipChange::latest('id')->first()->changed_by_user_id);
    }

    public function test_other_signed_in_roles_cannot_change_it(): void
    {
        foreach ([Role::EmployerAdmin, Role::Merchant, Role::Employee] as $role) {
            $this->signIn($role);
            $this->post('/bid-admin/partnership/review', $this->form())->assertForbidden();
        }
        $this->assertSame(0, Partnership::sole()->revenue_share_bps);
    }

    public function test_judges_see_it_in_words_without_figures(): void
    {
        $this->signIn(Role::Judge);

        $html = $this->get('/bid-admin/partnership')->assertOk()
            ->assertSee('Demo partnership — not an actual agreement.')
            ->assertSee('Read-only.')
            ->assertDontSee('Review the change')
            ->assertSee('No revenue share')
            ->assertSee('Judges see this page without amounts')
            ->getContent();

        $this->assertStringNotContainsString('data-share-month', $html);
        $this->assertDoesNotMatchRegularExpression('/\d%\s+of what Local Goods earns/', $html);
    }

    public function test_review_writes_nothing_and_confirm_saves_against_the_reviewed_version(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $partnership = Partnership::sole();
        $reviewed = $partnership->latestChangeId();

        $this->post('/bid-admin/partnership/review', $this->form())->assertOk()
            ->assertSee('Check the change')
            ->assertSee('Revenue share (changes)')
            ->assertSee('10%');
        $this->assertSame(1, PartnershipChange::count());
        $this->assertSame(0, $partnership->fresh()->revenue_share_bps);

        $confirm = [...$this->form(), 'revenue_share_bps' => 1000, 'reviewed_change_id' => $reviewed, 'confirm' => '1'];
        $this->post('/bid-admin/partnership', $confirm)->assertRedirect('/bid-admin/partnership')
            ->assertSessionHas('status', 'Partnership updated: revenue share. Logged with your initials.');
        $this->assertSame(1000, $partnership->fresh()->revenue_share_bps);

        // The same confirm again is against an older version now.
        $this->post('/bid-admin/partnership', [...$confirm, 'revenue_share_bps' => 2000])->assertSessionHas('error');
        $this->assertSame(1000, $partnership->fresh()->revenue_share_bps);
    }

    public function test_confirm_needs_the_box_ticked(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/bid-admin/partnership', [...$this->form(), 'revenue_share_bps' => 1000, 'reviewed_change_id' => Partnership::sole()->latestChangeId()])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(0, Partnership::sole()->revenue_share_bps);
    }

    public function test_a_status_going_back_is_refused_at_review(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/bid-admin/partnership/review', $this->form(['status' => 'draft']))
            ->assertSessionHasErrors(['terms' => "A partnership can't go from active back to draft. Start a new one instead."]);
    }

    private function withoutPartnership(): void
    {
        // Query builder on purpose: the change log refuses deletes through its model.
        DB::table('partnership_changes')->delete();
        DB::table('partnerships')->delete();
    }

    public function test_with_no_partnership_a_bid_admin_starts_a_draft_through_review_and_confirm(): void
    {
        $this->withoutPartnership();
        $user = $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/partnership')->assertOk()
            ->assertSee('No partnership is recorded for this district yet.')
            ->assertSee('Start a partnership')
            ->assertSee('Review the new partnership')
            ->assertDontSee('Read-only.');

        // Review writes nothing, and a new partnership is always a draft whatever is posted.
        $this->post('/bid-admin/partnership/review', $this->form(['status' => 'active', 'reason' => 'Signed the pilot letter']))->assertOk()
            ->assertSee('Check the new partnership')
            ->assertSee('Start this partnership as a draft');
        $this->assertSame(0, Partnership::count());

        $this->post('/bid-admin/partnership', [...$this->form(['status' => 'active', 'reason' => 'Signed the pilot letter']), 'revenue_share_bps' => 1000, 'reviewed_change_id' => 0, 'confirm' => '1'])
            ->assertRedirect('/bid-admin/partnership')->assertSessionHas('status');

        $partnership = Partnership::sole();
        $this->assertSame(Partnership::STATUS_DRAFT, $partnership->status);
        $this->assertNull($partnership->exclusive_active_key, 'a draft holds no exclusive key');
        $this->assertFalse($partnership->is_demo);
        $change = $partnership->changes()->sole();
        $this->assertNull($change->before);
        $this->assertSame($user->id, $change->changed_by_user_id);
        $this->assertSame('Signed the pilot letter', $change->reason);
        $this->get('/bid-admin/partnership')->assertSee('Change the partnership')->assertSee('Created');
    }

    public function test_starting_needs_the_box_and_is_refused_if_someone_started_one_meanwhile(): void
    {
        $this->withoutPartnership();
        $this->signIn(Role::PlatformAdmin);
        $confirm = [...$this->form(), 'revenue_share_bps' => 0, 'reviewed_change_id' => 0];

        $this->post('/bid-admin/partnership', $confirm)->assertSessionHasErrors('confirm');
        $this->assertSame(0, Partnership::count());

        $this->post('/bid-admin/partnership', [...$confirm, 'confirm' => '1'])->assertSessionHas('status');
        $this->post('/bid-admin/partnership', [...$confirm, 'confirm' => '1'])
            ->assertSessionHas('error', \App\Http\Controllers\BidAdmin\PartnershipController::STARTED_MEANWHILE);
        $this->assertSame(1, Partnership::count());
    }

    public function test_judges_and_other_roles_cannot_start_one_and_see_the_read_only_note(): void
    {
        $this->withoutPartnership();

        $this->signIn(Role::Judge);
        $this->get('/bid-admin/partnership')->assertOk()->assertSee('Read-only.')->assertDontSee('Start a partnership');
        $this->post('/bid-admin/partnership', [...$this->form(), 'revenue_share_bps' => 0, 'reviewed_change_id' => 0, 'confirm' => '1'])->assertForbidden();

        foreach ([Role::EmployerAdmin, Role::Merchant, Role::Employee] as $role) {
            $this->signIn($role);
            $this->post('/bid-admin/partnership/review', $this->form())->assertForbidden();
            $this->post('/bid-admin/partnership', [...$this->form(), 'revenue_share_bps' => 0, 'reviewed_change_id' => 0, 'confirm' => '1'])->assertForbidden();
        }
        $this->assertSame(0, Partnership::count());
    }

    public function test_the_read_only_note_is_only_for_people_who_cannot_edit(): void
    {
        foreach ([Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->signIn($role);
            $this->get('/bid-admin/partnership')->assertOk()->assertDontSee('Read-only.')->assertSee('Change the partnership');
        }

        $this->signIn(Role::Judge);
        $this->get('/bid-admin/partnership')->assertOk()->assertSee('Read-only.')->assertDontSee('Change the partnership');
    }
}
