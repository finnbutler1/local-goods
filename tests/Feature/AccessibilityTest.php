<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_pages_with_a_parameter_in_their_address_pass_the_static_checks(): void
    {
        $found = array_filter([
            'reset password' => AccessibilityAudit::problems($this->get('/reset-password/some-token?email=someone@example.com')->assertOk()->getContent()),
            'old invite link' => AccessibilityAudit::problems($this->get('/invite/old-token')->getContent()),
            'adjudication case' => AccessibilityAudit::problems($this->actingAs($this->signIn(Role::Judge))->get('/adjudicate?n=1')->assertOk()->getContent()),
            'redeem from a menu' => AccessibilityAudit::problems($this->actingAs($this->signIn(Role::Employee))->get('/employee?merchant='.Merchant::where('name', 'Farragut Grind')->value('id'))->assertOk()->getContent()),
            'redeem, suspicious example' => AccessibilityAudit::problems($this->actingAs($this->signIn(Role::Employee))->get('/employee?suspicious=1')->assertOk()->getContent()),
        ]);

        $this->assertNoProblems($found);
    }

    public function test_the_styleguide_passes_the_static_checks(): void
    {
        $this->app['env'] = 'local';

        $this->assertNoProblems(array_filter(['/styleguide' => AccessibilityAudit::problems($this->get('/styleguide')->assertOk()->getContent())]));
    }

    /** @return array<string, array{0: ?Role, 1: string, 2: string, 3: string, 4: array<string, mixed>}> */
    public static function formsWithErrors(): array
    {
        return [
            'login, wrong password' => [null, '/login', 'post', '/login', ['email' => 'nobody@example.com', 'password' => 'wrong']],
            'buy credits, not a number' => [Role::EmployerAdmin, '/employer', 'post', '/employer/purchases', ['purchase_amount' => 'abc']],
            'give credits to one person, bad amount' => [Role::EmployerAdmin, '/employer', 'post', '/employer/allocations', ['mode' => 'individual', 'occasion' => 'monthly', 'employee_id' => '', 'amount' => 'abc']],
            'give credits to several, nobody ticked' => [Role::EmployerAdmin, '/employer', 'post', '/employer/allocations', ['mode' => 'bulk', 'occasion' => 'monthly', 'recipients' => 'selected', 'amount' => '5']],
            'program setup, bad budget' => [Role::EmployerAdmin, '/employer', 'post', '/employer/program', ['monthly_budget' => 'abc', 'monthly_allowance' => '']],
            'redeem, nothing chosen' => [Role::Employee, '/employee', 'post', '/employee/redeem', ['merchant_id' => '', 'amount' => 'abc']],
            'redeem from a menu, bad quantity' => [Role::Employee, '/employee', 'post', '/employee/redeem', ['merchant_id' => 'grind', 'items' => ['latte' => '99']]],
            'redeem from a menu, nothing picked' => [Role::Employee, '/employee', 'post', '/employee/redeem', ['merchant_id' => 'grind', 'items' => ['latte' => '0']]],
            'deny, no reason given' => [Role::BidAdmin, '/bid-admin', 'post', 'deny', ['reason' => '']],
            'menu, change a product badly' => [Role::Merchant, '/merchant/menu', 'post', 'menu-product', ['name' => '', 'price' => 'free']],
            'menu, add a product badly' => [Role::Merchant, '/merchant/menu', 'post', '/merchant/menu', ['name' => '', 'price' => '0']],
            'feedback, empty' => [Role::Employee, '/employee', 'post', '/feedback', ['page_url' => 'http://localhost/employee']],
            'change password, wrong current one' => [Role::Employee, '/account', 'put', '/user/password', ['current_password' => 'wrong', 'password' => 'x', 'password_confirmation' => 'y']],
            'invite reviewer, empty' => [Role::PlatformAdmin, '/admin/reviewers', 'post', '/admin/reviewers', []],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('formsWithErrors')]
    public function test_a_form_sent_with_errors_ties_each_error_to_its_field(?Role $role, string $page, string $method, string $action, array $data): void
    {
        if ($role) {
            $this->actingAs($this->signIn($role));
        }
        if (($data['merchant_id'] ?? null) === 'grind') {
            $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
            $data = ['merchant_id' => $grind->id, 'items' => [$grind->products()->where('name', 'Latte')->value('id') => $data['items']['latte']]];
        }
        if ($action === 'menu-product') {
            $action = route('merchant.menu.update', Merchant::where('name', 'Farragut Grind')->firstOrFail()->products()->firstOrFail());
        }
        if ($action === 'deny') {
            $action = route('bid-admin.deny', Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->firstOrFail());
        }

        $html = $this->from($page)->followingRedirects()->{$method}($action, $data)->assertOk()->getContent();

        $this->assertStringContainsString('aria-invalid="true"', $html, 'No field is marked invalid.');
        $this->assertNoProblems(array_filter([$page => AccessibilityAudit::problems($html)]));
    }

    public function test_people_who_ask_for_less_motion_get_none(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*animation-duration: 0\.01ms !important;[^}]*transition-duration: 0\.01ms !important;/s', $css);
        // Animations in views only run for people who haven't asked for less motion.
        foreach (File::allFiles(resource_path('views')) as $view) {
            preg_match_all('/(?<![\w:-])animate-\w+/', $view->getContents(), $m);
            $this->assertSame([], $m[0], "{$view->getRelativePathname()} animates without motion-safe:");
        }
    }

    public function test_every_form_that_posts_shows_it_is_working_and_cant_be_sent_twice(): void
    {
        // Log out is instant; the magic-link form submits itself before anyone sees it.
        $exempt = ["route('logout')", "route('judge-access.store')"];

        foreach (File::allFiles(resource_path('views')) as $view) {
            preg_match_all('/<form\b[^>]*method="POST"[^>]*>/', $view->getContents(), $forms);
            foreach ($forms[0] as $form) {
                if (collect($exempt)->contains(fn ($e) => str_contains($form, $e))) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/data-busy="[^"]+"/', $form, "{$view->getRelativePathname()}: {$form}");
            }
        }
    }

    public function test_each_chart_has_a_summary_sentence_and_a_table(): void
    {
        foreach ([[Role::EmployerAdmin, '/employer'], [Role::BidAdmin, '/bid-admin/impact']] as [$role, $page]) {
            $this->actingAs($this->signIn($role))->get($page)->assertOk()
                // The bars themselves are hidden from screen readers…
                ->assertSee('items-end gap-1.5 border-b border-muted/40 sm:gap-3" aria-hidden="true"', false)
                // …so a sentence sums them up and a table has every number.
                ->assertSeeInOrder(['so far this week,', 'out of a peak of'], false)
                ->assertSeeInOrder(['Show as a table', '<table', 'Week of'], false);
        }
    }
}
