<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Judges can view the app as a BID admin, employer admin, employee or
 * merchant, with a read-only banner and no buttons that change data.
 */
class JudgeViewAsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_switcher_opens_each_role_with_a_read_only_banner(): void
    {
        $this->signIn(Role::Judge);

        foreach (['bid_admin' => '/bid-admin', 'employer_admin' => '/employer', 'employee' => '/employee', 'merchant' => '/merchant'] as $role => $url) {
            $this->get("/judge/view-as/{$role}")->assertRedirect($url);
            $label = Role::from($role)->label();
            $this->get($url)->assertOk()
                ->assertSee("You're viewing as {$label}. This is read-only.", false)
                ->assertSee('role="status"', false)
                ->assertSee('Judge home');
        }

        $this->get('/judge/view-as')->assertRedirect('/judge');
        $this->get('/judge')->assertDontSee('This is read-only.');
    }

    public function test_unknown_or_admin_roles_cannot_be_chosen(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/judge/view-as/platform_admin')->assertNotFound();
        $this->get('/judge/view-as/nobody')->assertNotFound();
        $this->get('/admin')->assertForbidden();
    }

    public function test_bid_admin_view_hides_approve_reject_and_reset(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold', 'Round number.'));
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => Merchant::firstOrFail()->id, 'amount' => '5']);

        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/bid_admin');

        $page = $this->get('/bid-admin')->assertOk()
            ->assertSee('Under review')
            ->assertSee("Waiting for a BID admin's decision.", false);

        foreach (['/approve"', '/reject"', 'bid-admin/reset"'] as $action) {
            $page->assertDontSee($action, false);
        }
        $page->assertDontSee('>Approve<', false)->assertDontSee('>Reject<', false)->assertDontSee('Reset demo');
    }

    public function test_employee_view_shows_the_demo_wallet_with_redemption_disabled(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/employee');

        $html = $this->get('/employee')->assertOk()
            ->assertSee("Read-only: judges can't make redemptions.", false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Confirm redemption/s', $html);
    }

    public function test_a_direct_link_shows_the_page_as_its_own_role(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/bid-admin/eval')->assertOk()->assertSee("You're viewing as BID admin.", false);
    }

    public function test_real_bid_admins_still_see_their_buttons(): void
    {
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin')->assertSee('Reset demo')->assertDontSee('This is read-only.');
    }
}
