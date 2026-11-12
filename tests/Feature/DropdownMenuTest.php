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
 * role, grouped collapsible menus for the admin roles (ADR 0035), View as for judges and Menu on the public site for
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
            $this->assertStringContainsString('Your Account', $menu, $role->value);
            $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('logout'), '#').'">.*<button type="submit" class="menu-item">Log Out</button>#s', $menu, $role->value);
            $this->assertStringContainsString('Signed in as', $menu, $role->value);
        }
    }

    public function test_log_out_and_your_account_left_the_sidebar_and_top_bar(): void
    {
        $this->signIn(Role::BidAdmin);
        $html = $this->get(route('bid-admin.home'))->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'), 'Log out is only in the Account menu now.');
        $this->assertSame(1, substr_count($html, '>Your Account<'));
    }

    public function test_the_platform_admin_menu_is_six_collapsible_groups(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $sidebar = str($html)->after('<aside')->before('</aside>')->toString();

        $this->assertStringContainsString('href="'.route('admin.dashboard').'"', $sidebar);
        $this->assertStringNotContainsString('id="nav-more"', $html, 'The old More menu is gone.');
        $this->assertGroupsInOrder($sidebar, 'side', ['reviews' => 'Reviews &amp; Decisions', 'money' => 'Money', 'program' => 'Program', 'ai' => 'AI &amp; Evaluation', 'people' => 'People &amp; Access']);

        foreach (Role::PlatformAdmin->navigation() as $item) {
            $this->assertStringContainsString('href="'.route($item['route']).'"', $sidebar, $item['label']);
        }
        $this->assertStringContainsString('aria-current="page"', str($sidebar)->before('Reviews'), 'Home is the current page.');
    }

    public function test_the_group_with_the_current_page_starts_open_and_the_others_closed(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $sidebar = str($this->get(route('bid-admin.corrections'))->assertOk()->getContent())->after('<aside')->before('</aside>')->toString();

        $this->assertMatchesRegularExpression('#id="nav-side-reviews-button" aria-expanded="true" aria-controls="nav-side-reviews"#', $sidebar);
        $this->assertMatchesRegularExpression('#id="nav-side-money-button" aria-expanded="false" aria-controls="nav-side-money"#', $sidebar);
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));
    }

    public function test_the_bid_admin_sees_only_its_own_subset_in_the_same_groups(): void
    {
        $this->signIn(Role::BidAdmin);
        $sidebar = str($this->get(route('bid-admin.home'))->assertOk()->getContent())->after('<aside')->before('</aside>')->toString();

        $this->assertGroupsInOrder($sidebar, 'side', ['reviews' => 'Reviews &amp; Decisions', 'program' => 'Program']);
        $this->assertSingleItemsAreUngrouped($sidebar, 'side');
        $this->assertStringNotContainsString('nav-side-people', $sidebar);
        foreach (['admin.reconciliation', 'admin.adjudication.index', 'admin.reviewers.index', 'admin.feedback.index'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $sidebar, "{$route} is platform admin only");
        }
        foreach (['bid-admin.corrections', 'money', 'bid-admin.participation', 'bid-admin.impact'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $sidebar, $route);
        }
        // Left out of the BID admin's menu in the 2026-11-12 trim (platform admin keeps them).
        foreach (['bid-admin.fees', 'bid-admin.demo-activity', 'business-model', 'eval.merchants'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $sidebar, $route);
        }
    }

    public function test_the_phone_bar_is_home_two_pages_and_a_menu_button_with_the_same_groups(): void
    {
        foreach ([[Role::PlatformAdmin, ['admin.dashboard', 'bid-admin.dashboard', 'money']], [Role::BidAdmin, ['bid-admin.home', 'bid-admin.dashboard', 'bid-admin.impact']]] as [$role, $tabs]) {
            $this->signIn($role);
            $html = $this->get(route($role->dashboardRoute()))->assertOk()->getContent();
            $tabBar = str($html)->afterLast('<nav aria-label="App"')->before('</nav>')->toString();

            $this->assertSeeInOrderIn(str($tabBar)->before('data-menu')->toString(), array_map(fn ($r) => route($r), $tabs));
            $this->assertSame(3, substr_count(str($tabBar)->before('data-menu')->toString(), '<a href='), $role->value);
            $sheet = $this->menu($html, 'tab-menu');
            $this->assertStringContainsString('>Menu<', $tabBar);
            $this->assertStringContainsString('id="nav-sheet-reviews-button" aria-expanded=', $sheet);
            if ($role === Role::BidAdmin) {
                $this->assertSingleItemsAreUngrouped($sheet, 'sheet');
            }
            foreach ($role->navigation() as $item) {
                $this->assertStringContainsString('href="'.route($item['route']).'"', $sheet, $role->value.' '.$item['label']);
            }
        }
    }

    public function test_a_judge_viewing_as_the_bid_admin_gets_the_same_structure(): void
    {
        $this->signIn(Role::Judge);
        $sidebar = str($this->get(route('bid-admin.home'))->assertOk()->getContent())->after('<aside')->before('</aside>')->toString();

        $this->assertMatchesRegularExpression('#Judge Home\s*</a>#', $sidebar);
        $this->assertGroupsInOrder($sidebar, 'side', ['reviews' => 'Reviews &amp; Decisions', 'program' => 'Program']);
        $this->assertSingleItemsAreUngrouped($sidebar, 'side');
    }

    /** The BID admin's Money and AI & Evaluation hold one page each, so they have no header: just the link. */
    private function assertSingleItemsAreUngrouped(string $html, string $variant): void
    {
        foreach (['money', 'ai'] as $id) {
            $this->assertStringNotContainsString('id="nav-'.$variant.'-'.$id.'-button"', $html, "{$id} has no group header");
        }
        foreach (['Money', 'AI &amp; Evaluation'] as $heading) {
            $this->assertStringNotContainsString('>'.$heading.'<', $html, "{$heading} heading is gone");
        }
        foreach (['money', 'bid-admin.eval'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $html, $route);
        }
    }

    /** @param array<string, string> $groups id => heading */
    private function assertGroupsInOrder(string $html, string $variant, array $groups): void
    {
        $at = -1;
        foreach ($groups as $id => $heading) {
            $this->assertMatchesRegularExpression('#<button type="button" id="nav-'.$variant.'-'.$id.'-button" aria-expanded="(true|false)" aria-controls="nav-'.$variant.'-'.$id.'" data-nav-group-button#', $html, $id);
            $this->assertSame(1, substr_count($html, 'id="nav-'.$variant.'-'.$id.'"'), "{$id}: the list exists once");
            $next = strpos($html, 'id="nav-'.$variant.'-'.$id.'-button"');
            $this->assertGreaterThan($at, $next, "{$id} is out of order");
            $this->assertStringContainsString('>'.$heading, $html);
            $at = $next;
        }
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
        $this->assertStringContainsString('Back to Judge Home', $menu);
    }

    public function test_a_personal_contact_gets_an_account_menu_without_the_public_site(): void
    {
        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]));
        $menu = $this->menu($this->get(route('adjudication.show'))->assertOk()->getContent(), 'account-menu');

        $this->assertStringContainsString('Log Out', $menu);
        $this->assertStringNotContainsString('Back to the Public Site', $menu);
    }

    public function test_the_public_site_has_a_phone_menu_with_the_section_links(): void
    {
        $menu = $this->menu($this->get('/')->assertOk()->getContent(), 'site-menu');

        foreach (['#how-it-works' => 'How it works', '#why-it-matters' => "Who it's for", '#trust' => 'Trust &amp; AI'] as $anchor => $label) {
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
