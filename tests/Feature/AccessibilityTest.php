<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\Fakes\FakeClaudeClient;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * Opens every page signed out, as every role and as a judge viewing as each
 * role, and runs the static checks in Tests\Support\AccessibilityAudit
 * (labels, alt text, names, ids, landmarks). What a test can't judge is
 * listed in docs/accessibility.md.
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // A held redemption, so the fraud review queue shows its approve/deny forms.
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->skip(1)->firstOrFail(), $merchant, 500);
    }

    /** @param array<string, list<string>> $found */
    private function assertNoProblems(array $found): void
    {
        $report = collect($found)->map(fn ($problems, $where) => "{$where}:\n  - ".implode("\n  - ", array_unique($problems)))->implode("\n");
        $this->assertSame([], $found, "Accessibility problems:\n{$report}");
    }

    public function test_every_page_passes_the_static_checks_for_every_role(): void
    {
        $found = [];
        $checked = $this->sweepPages(function (string $html, string $where) use (&$found) {
            if ($problems = AccessibilityAudit::problems($html)) {
                $found[$where] = $problems;
            }
        });

        $this->assertGreaterThan(25, $checked, 'Too few pages were checked.');
        $this->assertNoProblems($found);
    }

    public function test_every_view_as_page_passes_the_static_checks(): void
    {
        $judge = $this->signIn(Role::Judge);
        $found = [];

        foreach (ViewAs::ROLES as $role) {
            $html = $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', $role->value))->assertOk()->getContent();
            if ($problems = AccessibilityAudit::problems($html)) {
                $found["view as {$role->value}"] = $problems;
            }
        }

        $this->assertNoProblems($found);
    }

    public function test_the_styleguide_passes_the_static_checks(): void
    {
        $this->app['env'] = 'local';

        $this->assertNoProblems(array_filter(['/styleguide' => AccessibilityAudit::problems($this->get('/styleguide')->assertOk()->getContent())]));
    }
}
