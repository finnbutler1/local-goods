<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Disclosure menus that cut clutter (ADR 0025): Account for every signed-in
 * role, More when a role has too many pages for the sidebar (over 5) or the
 * phone tab bar (over 4), View as for judges and Menu on the public site for
 * phones. Each is a real button with aria-expanded and aria-controls
 * pointing at its list, and the moved links are inside that list.
 */
class DropdownMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_signed_in_role_has_an_account_menu(): void
    {
        foreach (Role::cases() as $role) {
            $this->signIn($role);
            $menu = $this->menu($this->get(route($role->dashboardRoute()))->assertOk()->getContent(), 'account-menu');

            $this->assertStringContainsString('href="'.route('account').'"', $menu, $role->value);
            $this->assertStringContainsString('Your account', $menu, $role->value);
            $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('logout'), '#').'">.*<button type="submit" class="menu-item">Log out</button>#s', $menu, $role->value);
            $this->assertStringContainsString('Signed in as', $menu, $role->value);
        }
    }

    public function test_log_out_and_your_account_left_the_sidebar_and_top_bar(): void
    {
        $this->signIn(Role::BidAdmin);
        $html = $this->get(route('bid-admin.dashboard'))->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'), 'Log out is only in the Account menu now.');
        $this->assertSame(1, substr_count($html, '>Your account<'));
    }

    public function test_the_platform_admin_keeps_four_pages_in_the_sidebar_and_the_rest_in_more(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $sidebar = str($html)->after('<aside')->before('</aside>')->toString();
        $more = $this->menu($html, 'nav-more');

        foreach (['admin.reviewers.index', 'admin.adjudication.index'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $more, "{$route} is in More");
        }
        $this->assertSeeInOrderIn(str($sidebar)->before('data-menu')->toString(), [route('admin.dashboard'), route('bid-admin.dashboard'), route('admin.feedback.index'), route('bid-admin.eval')]);
        foreach (['admin.dashboard', 'bid-admin.dashboard', 'admin.feedback.index', 'bid-admin.eval'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $more, "{$route} stays visible");
            $this->assertStringContainsString('href="'.route($route).'"', $sidebar);
        }

        $tabMore = $this->menu($html, 'tab-more');
        foreach (['bid-admin.eval', 'admin.reviewers.index', 'admin.adjudication.index'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $tabMore, "{$route} is in the tab bar's More");
        }
        $this->assertStringNotContainsString('href="'.route('admin.feedback.index').'"', $tabMore, 'Feedback stays on the tab bar.');
    }

    public function test_the_bid_admin_tab_bar_keeps_merchants_visible(): void
    {
        $this->signIn(Role::BidAdmin);
        $html = $this->get(route('bid-admin.dashboard'))->assertOk()->getContent();
        $tabBar = str($html)->afterLast('<nav aria-label="App"')->before('</nav>')->toString();
        $tabMore = $this->menu($html, 'tab-more');

        foreach (['bid-admin.dashboard', 'bid-admin.merchants', 'bid-admin.impact'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', str($tabBar)->before('data-menu')->toString(), "{$route} is a tab");
        }
        $this->assertSeeInOrderIn(str($tabBar)->before('data-menu')->toString(), [route('bid-admin.dashboard'), route('bid-admin.merchants'), route('bid-admin.impact')]);
        foreach (['bid-admin.participation', 'bid-admin.eval'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $tabMore, "{$route} is in More");
        }
        $this->assertStringNotContainsString('id="nav-more"', $html, 'Five pages fit in the sidebar: no More there.');
    }

    public function test_roles_with_few_pages_have_no_more_menu(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin] as $role) {
            $this->signIn($role);
            $html = $this->get(route($role->dashboardRoute()))->getContent();
            $this->assertStringNotContainsString('data-menu-button', str($html)->after('<aside')->before('</aside>')->toString(), $role->value);
            $this->assertStringNotContainsString('id="tab-more"', $html, $role->value);
        }
    }

    public function test_the_judge_view_as_menu_lists_every_role(): void
    {
        $this->signIn(Role::Judge);
        $menu = $this->menu($this->get(route('judge.dashboard'))->assertOk()->getContent(), 'view-as-menu');

        foreach (ViewAs::ROLES as $role) {
            $this->assertStringContainsString('href="'.route('judge.view-as', $role->value).'"', $menu, $role->value);
        }
        $this->assertStringContainsString('Back to judge home', $menu);
    }

    public function test_a_personal_contact_gets_an_account_menu_without_the_public_site(): void
    {
        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]));
        $menu = $this->menu($this->get(route('adjudication.show'))->assertOk()->getContent(), 'account-menu');

        $this->assertStringContainsString('Log out', $menu);
        $this->assertStringNotContainsString('Back to the public site', $menu);
    }

    public function test_the_public_site_has_a_phone_menu_with_the_section_links(): void
    {
        $menu = $this->menu($this->get('/')->assertOk()->getContent(), 'site-menu');

        foreach (['#how-it-works' => 'How it works', '#who-its-for' => "Who it's for", '#trust' => 'Trust &amp; AI'] as $anchor => $label) {
            $this->assertStringContainsString('href="'.route('home').$anchor.'" class="menu-item">'.$label.'</a>', $menu);
        }
        $this->assertStringContainsString('<span class="sr-only">Site menu</span>', $menu);
    }

    private function assertSeeInOrderIn(string $html, array $needles): void
    {
        $at = -1;
        foreach ($needles as $needle) {
            $next = strpos($html, 'href="'.$needle.'"');
            $this->assertNotFalse($next, $needle);
            $this->assertGreaterThan($at, $next, "{$needle} is out of order");
            $at = $next;
        }
    }

    /** The menu's button and list, after checking the button is wired to the list. */
    private function menu(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression(
            '#<button type="button" id="'.$id.'-button" aria-expanded="false" aria-controls="'.$id.'" data-menu-button#',
            $html, "{$id}: a real button, closed, pointing at its list");
        $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "{$id}: the list exists once");

        return str($html)->after('id="'.$id.'-button"')->before('</div>'."\n".'</div>')->toString();
    }
}
