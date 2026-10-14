<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each role has its own dashboard. The platform admin also reaches the BID
 * admin's pages (to approve or reject); every other role reaches only its own.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /** Every signed-in page, and the roles allowed on it. Judges may look at every page but the platform admin's. */
    private const PAGES = [
        '/employee' => [Role::Employee, Role::Judge],
        '/bid-admin' => [Role::BidAdmin, Role::PlatformAdmin, Role::Judge],
        '/bid-admin/eval' => [Role::BidAdmin, Role::PlatformAdmin, Role::Judge],
        '/employer' => [Role::EmployerAdmin, Role::Judge],
        '/merchant' => [Role::Merchant, Role::Judge],
        '/judge' => [Role::Judge],
        '/admin' => [Role::PlatformAdmin],
        '/admin/reviewers' => [Role::PlatformAdmin],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public static function roles(): array
    {
        return collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => [$role]])->all();
    }

    /** Every role but judges, who sign in only with their magic link (ReviewerMagicLinkTest). */
    public static function passwordRoles(): array
    {
        return collect(self::roles())->reject(fn ($args) => $args[0] === Role::Judge)->all();
    }

    #[DataProvider('passwordRoles')]
    public function test_each_role_logs_in_to_its_own_dashboard(Role $role): void
    {
        $user = User::factory()->role($role)->create(['password' => 'test-only-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'test-only-password'])
            ->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect(route($role->dashboardRoute()));
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_only_its_own_pages(Role $role): void
    {
        $this->signIn($role);

        foreach (self::PAGES as $url => $allowed) {
            $response = $this->get($url);
            in_array($role, $allowed, true)
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        foreach (['/dashboard', '/account', ...array_keys(self::PAGES)] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/employee/redeem')->assertRedirect('/login');
        $this->post('/bid-admin/reset')->assertRedirect('/login');
    }

    public function test_pages_show_the_signed_in_user_and_role_in_the_shell(): void
    {
        $this->signIn(Role::Employee);
        $this->get('/employee')->assertSee('Jordan Rivera')->assertSee('Role: </span>Employee', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertDontSee('(demo)</p>', false);

        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')->assertSee('Sam Taylor')->assertSee('Role: </span>BID admin', false);
        $this->get('/bid-admin/eval')->assertSee('Role: </span>BID admin', false)->assertSee('aria-label="App"', false);
    }

    public function test_employer_dashboard_renders_in_the_app_shell(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $response = $this->get('/employer')->assertOk();

        $response->assertSee('Employer admin')
            ->assertSee('aria-label="App"', false)
            ->assertSee('href="#main"', false);

        // Both navs (sidebar and mobile tab bar) mark Dashboard as the current page.
        $this->assertSame(2, substr_count($response->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('employer.dashboard'), '/').'"\s+aria-current="page"/', $response->getContent());
    }

    public function test_an_employee_login_without_a_wallet_is_refused(): void
    {
        $this->actingAs(User::factory()->role(Role::Employee)->create());

        $this->get('/employee')->assertForbidden();
    }
}
