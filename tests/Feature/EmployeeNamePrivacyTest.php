<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\FeedbackCategory;
use App\Enums\FeedbackSeverity;
use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Ai\ClaudeClient;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
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
    use SweepsPages;

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
                0 => app(RedemptionReviewService::class)->approve($redemption, $reviewer, 'Checked with the merchant.'),
                1 => app(RedemptionReviewService::class)->deny($redemption, $reviewer, 'No purchase happened.', DenialReason::NoPurchase),
                default => null,
            };
        }

        $this->assertGreaterThan(0, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
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

    /**
     * The shared "Public judge access" account is anyone with the README
     * link, so it gets the strictest view: no employee names, no real
     * candidate business names, and no names or emails of real reviewers or
     * any other account, on any page or "view as" page.
     */
    public function test_the_public_judge_account_sees_no_personal_data_anywhere(): void
    {
        $named = User::factory()->role(Role::Judge)->create([
            'name' => 'Rowan Examplereviewer', 'email' => 'rowan.reviewer@cua.example', 'reviewer_type' => ReviewerType::ContestJudge,
        ]);
        $this->actingAs($named)->get('/judge')->assertOk();
        $named->feedback()->create([
            'page_url' => url('/judge'), 'category' => FeedbackCategory::AiIntegration, 'rating' => 4,
            'severity' => FeedbackSeverity::ShouldFix, 'comment' => 'A note from a named reviewer.',
        ]);

        $public = User::factory()->role(Role::Judge)->create(['name' => 'Public judge access', 'reviewer_type' => ReviewerType::Public]);
        $others = User::whereKeyNot($public->id)->get(['name', 'email']);
        $candidates = array_column(MerchantCandidates::load(), 'name');
        $this->assertNotEmpty($candidates);

        $check = function (string $html, string $where) use ($others, $candidates) {
            // <x-breakable> puts <wbr> inside long emails; an email split that way still counts.
            $html = str_replace('<wbr>', '', $html);
            $this->assertNoFullNames($html, $where);
            foreach ($candidates as $name) {
                $this->assertStringNotContainsString(e($name), $html, "Candidate {$name} appears on {$where}.");
            }
            foreach ($others as $user) {
                $this->assertStringNotContainsString(e($user->email), $html, "An account email appears on {$where}.");
            }
            $this->assertStringNotContainsString('Rowan Examplereviewer', $html, "A reviewer's name appears on {$where}.");
        };

        $checked = 0;
        foreach ($this->pages() as $uri) {
            $response = $this->actingAs($public)->get($uri);
            if ($response->isOk()) {
                $check($response->getContent(), "{$uri} as the public judge");
                $checked++;
            }
        }
        foreach (ViewAs::ROLES as $role) {
            $html = $this->actingAs($public)->followingRedirects()->get(route('judge.view-as', $role->value))->assertOk()->getContent();
            $check($html, "the {$role->value} view as the public judge");
        }

        $this->assertGreaterThan(10, $checked, 'Too few pages were checked.');
    }

    /**
     * Personal contacts (ADR 0019) know Finn, so their name and email never
     * appear on any page for anyone: signed out, every role (admin pages show
     * initials only) and the public judge account.
     */
    public function test_a_personal_contacts_name_and_email_appear_nowhere(): void
    {
        $builder = app(AdjudicationSetBuilder::class);
        $set = $builder->newDraft();
        $builder->publish($set);

        // A different judge first, so the sweep's judge (the first one) isn't the contact on their own pages.
        $this->signIn(Role::Judge);
        $contact = User::factory()->role(Role::Judge)->create([
            'name' => 'Quincy Fakecontact', 'email' => 'quincy.fakecontact@example.test', 'reviewer_type' => ReviewerType::PersonalContact,
        ]);
        $this->actingAs($contact)->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        foreach ($set->cases as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic reason.']);
        }
        $this->post('/adjudicate/submit')->assertRedirect();
        $contact->feedback()->create([
            'page_url' => url('/adjudicate'), 'category' => FeedbackCategory::AiIntegration, 'rating' => 4,
            'severity' => FeedbackSeverity::ShouldFix, 'comment' => 'A note from a personal contact.',
        ]);
        $builder->close($set);
        auth()->logout();

        $check = function (string $html, string $where) {
            $html = str_replace('<wbr>', '', $html);
            $this->assertStringNotContainsString('Quincy', $html, "A personal contact's name appears on {$where}.");
            $this->assertStringNotContainsString('Fakecontact', $html, "A personal contact's name appears on {$where}.");
            $this->assertStringNotContainsString('quincy.fakecontact', $html, "A personal contact's email appears on {$where}.");
        };

        $checked = $this->sweepPages($check);

        $public = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]);
        foreach ($this->pages() as $uri) {
            $response = $this->actingAs($public)->get($uri);
            if ($response->isOk()) {
                $check($response->getContent(), "{$uri} as the public judge");
                $checked++;
            }
        }
        $this->assertGreaterThan(30, $checked, 'Too few pages were checked.');

        // Admin pages name them by initials.
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()->assertSee('Q.F.');
        $this->get('/admin/reviewers')->assertOk()->assertSee('Q.F.');
        $this->get('/admin/feedback')->assertOk()->assertSee('Q.F. · Personal contact (knows Finn)');
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
        $this->assertMatchesRegularExpression('/^J\.R\. · #\d{3,}$/', Employee::orderBy('id')->firstOrFail()->publicLabel());
    }
}
