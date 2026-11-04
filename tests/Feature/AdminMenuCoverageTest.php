<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\JudgeController;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Admin navigation (ADR 0035): every admin page a role can open is in that
 * role's menu, every menu link opens for that role, and each page's title and
 * heading are the menu label.
 */
class AdminMenuCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Downloads, not pages. */
    private const NOT_PAGES = ['bid-admin.impact.csv', 'business-model.csv'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return list<string> Named GET routes without parameters that this role can open under an admin role: middleware. */
    private function adminPagesFor(Role $role): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => in_array('GET', $r->methods(), true) && $r->getName() && ! str_contains($r->uri(), '{'))
            ->reject(fn (LaravelRoute $r) => in_array($r->getName(), self::NOT_PAGES, true))
            ->filter(function (LaravelRoute $r) use ($role) {
                foreach ($r->gatherMiddleware() as $m) {
                    if (is_string($m) && str_starts_with($m, 'role:')) {
                        $roles = explode(',', substr($m, 5));

                        return in_array($role->value, $roles, true) && (in_array('bid_admin', $roles, true) || in_array('platform_admin', $roles, true));
                    }
                }

                return false;
            })
            ->map(fn (LaravelRoute $r) => $r->getName())->values()->all();
    }

    public function test_every_admin_page_a_role_can_open_is_in_its_menu(): void
    {
        foreach ([Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $inMenu = collect($role->navigation())->pluck('route')->all();
            $pages = $this->adminPagesFor($role);

            $this->assertGreaterThan(10, count($pages), $role->value);
            $this->assertSame([], array_values(array_diff($pages, $inMenu)), "{$role->value}: pages missing from the menu");
        }
    }

    public function test_every_menu_link_opens_for_the_role_and_for_judges_viewing_as_the_bid_admin(): void
    {
        foreach ([Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $menuRole = $role === Role::Judge ? Role::BidAdmin : $role;
            foreach ($menuRole->navigation() as $item) {
                $this->get(route($item['route']))->assertOk();
            }
        }
    }

    public function test_the_menu_is_the_same_on_every_page_of_a_role(): void
    {
        foreach ([Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->signIn($role);
            foreach ($role->navigation() as $item) {
                if (in_array($item['route'], ['district', 'eval.merchants'], true)) {
                    continue; // public pages with the public header, as before
                }
                $html = $this->get(route($item['route']))->getContent();
                foreach ($role->navigation() as $other) {
                    $this->assertStringContainsString('href="'.route($other['route']).'"', $html, "{$role->value} {$item['label']} lacks {$other['label']}");
                }
            }
        }
    }

    public function test_page_titles_and_headings_match_their_menu_labels(): void
    {
        $this->signIn(Role::PlatformAdmin);
        foreach (Role::PlatformAdmin->navigation() as $key => $item) {
            if (in_array($item['route'], ['district', 'eval.merchants'], true)) {
                continue;
            }
            $html = $this->get(route($item['route']))->getContent();
            $this->assertSame(1, preg_match('#<title>(.*?) · Local Goods</title>#', html_entity_decode($html), $title), $item['label']);
            $this->assertSame($item['label'], $title[1], "title of {$item['route']}");
            preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $h1);
            $this->assertSame($item['label'], trim(html_entity_decode(strip_tags($h1[1]))), "h1 of {$item['route']}");
            $this->assertMatchesRegularExpression('#aria-current="page"#', str($html)->after('<aside')->before('</aside>')->toString(), "{$item['route']} highlights its menu item");
        }
    }

    public function test_menu_labels_are_title_case(): void
    {
        $small = ['a', 'an', 'and', 'the', 'of', 'to', 'in', 'for', 'is', 'on', 'at', 'by', 'or', '&'];
        foreach (Role::cases() as $role) {
            foreach ($role->navigationGroups() as $group) {
                foreach (array_filter([$group['label'], ...array_column($group['items'], 'label')]) as $label) {
                    foreach (explode(' ', $label) as $i => $word) {
                        $this->assertTrue($i > 0 && in_array($word, $small, true) || ctype_upper($word[0]), "'{$label}' is not Title Case");
                    }
                }
            }
        }
    }

    public function test_the_tour_names_menu_items_by_their_exact_label(): void
    {
        $this->signIn(Role::Judge);
        $labels = collect(Role::BidAdmin->navigation())->pluck('label')->all();
        foreach (JudgeController::tour(auth()->user()) as $step) {
            if (str_contains($step['title'], 'Open ')) {
                $this->assertContains(trim(str($step['title'])->after('Open ')->before(' to ')->toString()), $labels, $step['title']);
            }
        }
        foreach (['Fraud Review', 'AI Eval', 'Merchant Eligibility'] as $label) {
            $this->assertContains($label, $labels);
            $this->assertContains($label, array_column(JudgeController::tour(auth()->user()), 'title'));
        }
        $this->get(route('judge.dashboard'))->assertSee('>Where the Money Is</a>', false)->assertSee('>Business Model</a>', false);
    }
}
