<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Outside an employee's own wallet, people are shown by initials only
 * ("H.M."), never by full name (ADR 0014). This opens every page with no
 * route parameters as every role, signed out, and as a judge viewing as each
 * role, and checks that no seeded employee's full name is on any of them.
 * A page added later is covered without touching this file.
 */
class EmployeeNamePrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function fullNames(): array
    {
        return [...DemoHistorySeeder::NAMES, 'Jordan Rivera'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Fresh AI-scored redemptions (held, approved after review, rejected)
        // by named employees, so the fraud review and merchant pages list them.
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        $reviewer = User::factory()->role(Role::BidAdmin)->create();

        foreach (Employee::whereIn('display_name', DemoHistorySeeder::PATTERN_B_EMPLOYEES)->get() as $i => $employee) {
            $redemption = app(RedemptionService::class)->redeem($employee, $merchant, 500);
            match ($i) {
                0 => app(RedemptionReviewService::class)->approve($redemption, $reviewer),
                1 => app(RedemptionReviewService::class)->reject($redemption, $reviewer),
                default => null,
            };
        }

        $this->assertGreaterThan(0, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
    }

    /** @return list<string> Every GET page with no route parameters. */
    private function pages(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
            ->map(fn (RoutingRoute $r) => '/'.ltrim($r->uri(), '/'))
            ->reject(fn (string $uri) => str_starts_with($uri, '/up') || str_starts_with($uri, '/storage'))
            ->values()->all();
    }

    private function assertNoFullNames(string $html, string $where): void
    {
        foreach ($this->fullNames() as $name) {
            $this->assertStringNotContainsString($name, $html, "{$name} appears on {$where}.");
            $this->assertStringNotContainsString(e($name), $html, "{$name} appears on {$where}.");
        }
    }

    public function test_no_full_employee_name_on_any_page_for_any_role_other_than_the_employee(): void
    {
        $checked = 0;

        foreach ([Role::PlatformAdmin, Role::BidAdmin, Role::EmployerAdmin, Role::Merchant, Role::Judge] as $role) {
            $user = $this->signIn($role);

            foreach ($this->pages() as $uri) {
                $response = $this->actingAs($user)->get($uri);

                if ($response->isOk()) {
                    $this->assertNoFullNames($response->getContent(), "{$uri} as {$role->value}");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(15, $checked, 'Too few pages were checked.');
    }

    public function test_no_full_employee_name_when_a_judge_views_as_each_role(): void
    {
        $judge = $this->signIn(Role::Judge);

        foreach (ViewAs::ROLES as $role) {
            $html = $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', $role->value))->assertOk()->getContent();
            $this->assertNoFullNames($html, "the {$role->value} view as a judge");
        }
    }

    public function test_no_full_employee_name_on_public_pages(): void
    {
        foreach ($this->pages() as $uri) {
            $response = $this->get($uri);

            if ($response->isOk()) {
                $this->assertNoFullNames($response->getContent(), "{$uri} signed out");
            }
        }
    }

    public function test_the_employee_sees_their_own_name_but_nobody_elses(): void
    {
        $this->signIn(Role::Employee);
        $html = $this->get('/employee')->assertOk()->assertSee('Jordan Rivera')->getContent();

        foreach (DemoHistorySeeder::NAMES as $name) {
            $this->assertStringNotContainsString(e($name), $html);
        }
    }

    public function test_initials_drop_the_demo_tag_and_keep_accents(): void
    {
        $this->assertSame('J.R.', Employee::orderBy('id')->firstOrFail()->initials());
        $this->assertSame('H.Q.', Employee::where('display_name', 'Harper Quiñones')->sole()->initials());
        $this->assertMatchesRegularExpression('/^J\.R\. · #\d{3}$/', Employee::orderBy('id')->firstOrFail()->publicLabel());
    }
}
