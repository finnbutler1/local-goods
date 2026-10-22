<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employer;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
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

        $this->get('/employee')->assertOk()->assertSee("Read-only: judges can see the menus but can't make redemptions.", false);

        $html = $this->get('/employee?merchant='.Merchant::firstOrFail()->id)->assertOk()
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

    public function test_employer_view_shows_the_dashboard_with_every_write_disabled(): void
    {
        // A pending purchase: judges see it, but never a button that pays it.
        $pending = app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 10000, 'MPG-TEST-1');
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/employer_admin');

        $html = $this->get('/employer')->assertOk()
            ->assertSee('Your team got out into the neighborhood.')
            ->assertSee("Judges can look at every form here but can't buy, pay or give credits.", false)
            ->getContent();

        // Every form sits in a disabled fieldset, and every submit button outside one is disabled.
        // (Only the employer's own forms: feedback and log out stay open to judges.)
        preg_match_all('/<form method="POST" action="[^"]*\/employer[^"]*"[^>]*>(.*?)<\/form>/s', $html, $forms);
        $this->assertCount(4, $forms[1], 'program, purchase, two allocations (no Pay form for judges)');
        $this->assertStringContainsString("Read-only: judges can't pay", $html);
        $this->assertStringNotContainsString('/pay"', $html);
        foreach ($forms[1] as $form) {
            $this->assertTrue(str_contains($form, '<fieldset disabled') || preg_match('/<button[^>]*type="submit"[^>]*disabled/', $form) === 1, 'A form is not disabled for judges.');
        }

        $this->post('/employer/purchases', ['purchase_amount' => '10'])->assertForbidden();
        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'monthly', 'amount' => '1'])->assertForbidden();
        $this->post('/employer/program', ['monthly_allowance' => '1'])->assertForbidden();
        $this->post("/employer/purchases/{$pending->id}/pay")->assertForbidden();
        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_merchant_view_shows_the_demo_merchant_with_no_forms(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/merchant');

        $this->get('/merchant')->assertOk()
            ->assertSee('Owed to you now')
            ->assertSee('Simulated')->assertSee('no money moved')
            ->assertDontSee('<form method="POST" action="'.url('/merchant'), false);
    }
}
