<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/** The "Try a suspicious redemption" button lives on the employee's Demo Tools page, not the wallet. */
class DemoToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_holds_the_button_and_the_wallet_does_not(): void
    {
        $this->signIn(Role::Employee);

        $this->get('/employee')->assertOk()->assertDontSee('Try a suspicious redemption');
        $this->get(route('employee.demo-tools'))->assertOk()
            ->assertSee('Demo Tools')
            ->assertSee('For demonstrations: fills in a payment on your wallet that the fraud check is designed to hold.')
            ->assertSee('Try a suspicious redemption')
            ->assertSee(route('employee.wallet', ['suspicious' => 1]).'#redeem', false);
    }

    public function test_following_the_button_and_confirming_creates_a_held_redemption(): void
    {
        $this->signIn(Role::Employee);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(92, 'hold', 'The whole balance as a round number.'));

        $this->get('/employee?suspicious=1')->assertOk()->assertSee('value="120"', false);
        $merchant = Redemption::orderByDesc('id')->firstOrFail()->merchant_id;

        $this->followingRedirects()->post('/employee/redeem', ['merchant_id' => $merchant, 'amount' => '120'])
            ->assertOk()->assertSee('Under review');
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, Redemption::latest('id')->firstOrFail()->status);
    }

    public function test_a_judge_can_see_the_page_but_not_submit(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/employee');

        $this->get(route('employee.demo-tools'))->assertOk()->assertSee('Try a suspicious redemption');
        $before = Redemption::count();
        $this->post('/employee/redeem', ['merchant_id' => 1, 'amount' => '120']);
        $this->assertSame($before, Redemption::count());
    }

    public function test_other_roles_and_visitors_cannot_reach_it(): void
    {
        $this->get(route('employee.demo-tools'))->assertRedirect('/login');
        foreach ([Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('employee.demo-tools'))->assertForbidden();
        }
    }

    public function test_the_account_menu_link_is_for_employees_only(): void
    {
        $menu = fn (Role $role) => $this->actingAs(User::factory()->role($role)->create())->get('/account')->getContent();

        $this->assertStringContainsString('>Demo Tools</a>', $menu(Role::Employee));
        foreach ([Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $this->assertStringNotContainsString('Demo Tools', $menu($role), $role->value);
        }
    }

    public function test_the_menu_link_is_marked_current_on_its_page(): void
    {
        $this->signIn(Role::Employee);
        $html = $this->get(route('employee.demo-tools'))->getContent();
        $this->assertMatchesRegularExpression('#/employee/demo-tools" class="menu-item"\s+aria-current="page"\s*>Demo Tools#', $html);
    }
}
