<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\ReviewerLinks;
use App\Services\Sandbox\SandboxRoutes;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * One starting point for judges (ADR 0045): "Try it yourself" first, the
 * read-only live demo second; the "For judges" box; the checklist wording;
 * Jordan made obvious. Views and wording only. Sandboxes off look as before
 * (PublicJudgeLinkTest, JudgeLandingTest).
 */
class SandboxOneStartingPointTest extends TestCase
{
    use RefreshDatabase, UsesSandboxes;

    private User $public;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewers.judging_ends_at' => now()->addMonth()->toDateTimeString()]);
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $this->public = User::where('reviewer_type', ReviewerType::Public)->sole();
    }

    private function publicLink(): string
    {
        $url = 'https://uselocalgoods.com/judge-access#'.Str::after(app(ReviewerLinks::class)->newLink($this->public), '#');
        config(['reviewers.public_judge_url' => $url]);

        return $url;
    }

    public function test_landing_with_sandboxes_off_is_unchanged(): void
    {
        $this->publicLink();
        $this->get('/')->assertOk()->assertSee('Look around (read-only)')
            ->assertDontSee('Try it yourself')->assertDontSee('Prefer to just look?');
    }

    public function test_landing_with_sandboxes_on_has_one_primary_button_and_a_quiet_live_demo_link(): void
    {
        $this->setUpSandboxes();
        $url = $this->publicLink();

        $html = $this->get('/')->assertOk()
            ->assertSee('Try it yourself')
            ->assertSee('Prefer to just look?')
            ->assertSee('Explore the live demo (read-only)')
            ->assertDontSee('Look around (read-only)')
            ->assertDontSee('Continue looking around')
            ->getContent();
        $this->assertStringContainsString('rel="nofollow" href="'.$url.'"', $html);
        // Try it yourself is the one accent button.
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(route('sandbox.show'), '/').'"[^>]*>\s*Try it yourself/s', $html);
    }

    public function test_the_public_judge_sees_the_same_link_pointing_at_judge_home(): void
    {
        $this->setUpSandboxes();
        $this->publicLink();
        $this->actingAs($this->public);

        $html = $this->get('/')->assertOk()->assertSee('Explore the live demo (read-only)')->assertDontSee('Continue looking around')->getContent();
        $this->assertStringContainsString('href="'.route('judge.dashboard').'"', $html);
        $this->assertStringNotContainsString('judge-access#', $html);
    }

    public function test_no_live_demo_link_for_someone_with_no_judge_link_and_not_signed_in(): void
    {
        $this->setUpSandboxes();
        config(['reviewers.public_judge_url' => null]);
        $this->get('/')->assertOk()->assertSee('Try it yourself')->assertDontSee('Prefer to just look?');
    }

    public function test_judge_home_puts_try_it_yourself_first_then_the_live_demo(): void
    {
        $this->setUpSandboxes();
        $this->actingAs($this->public);

        $this->get('/judge')->assertOk()
            ->assertSeeInOrder(['Try it yourself', 'move money between the employer, employee, merchant and BID admin', 'Or explore the live demo', 'the AI evaluation, Where the Money Is', 'Guided tour']);
    }

    public function test_judge_home_with_sandboxes_off_has_no_card(): void
    {
        $this->actingAs($this->public);
        $this->get('/judge')->assertOk()->assertDontSee('Try it yourself')->assertDontSee('Or explore the live demo')
            ->assertSee('Guided tour');
    }

    public function test_judges_still_open_judge_home_read_only_with_sandboxes_on(): void
    {
        $this->setUpSandboxes();
        $named = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::ContestJudge]);
        $link = app(ReviewerLinks::class)->newLink($named);

        // The magic link still signs a judge in and lands on judge Home.
        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect('/judge');
        $this->get('/judge')->assertOk()->assertSee('Try it yourself');
        // And it is still read-only.
        $this->post(route('employer.purchases.store'), ['amount' => '10'])->assertForbidden();
    }

    public function test_the_sandbox_start_page_and_home_have_a_for_judges_box(): void
    {
        $this->setUpSandboxes();

        $this->get(route('sandbox.show'))->assertOk()
            ->assertSee('data-for-judges', false)->assertSee('The AI evaluation')->assertSee('Explore the live demo (read-only)');

        $this->startSandbox();
        $this->sandboxGet(route('sandbox.home'))->assertOk()
            ->assertSeeInOrder(['Try this checklist', 'For judges', 'Exit to the live demo'])
            ->assertSee('Local Goods admin views')->assertSee('feedback is left from the live demo');
    }

    public function test_feedback_inside_a_sandbox_explains_and_offers_exit(): void
    {
        $this->setUpSandboxes();
        $this->startSandbox();

        $this->sandboxPost(route('feedback.store'), ['comment' => 'x'])->assertNotFound()
            ->assertSee('Feedback is left from the live demo')->assertSee('Exit to the live demo');
        // Other pages outside the sandbox keep the plain page.
        $this->sandboxGet('/account')->assertNotFound()->assertSee("That page isn't part of the sandbox", false)->assertDontSee('Feedback is left');
    }

    public function test_the_checklist_wording(): void
    {
        $this->setUpSandboxes();
        $this->startSandbox();

        $page = $this->sandboxGet(route('sandbox.home'))->assertOk()
            ->assertDontSee('Spend credits at Farragut Grind:')
            ->assertSee('Spend credits at any participating merchant (Farragut Grind is a good first stop)', false)
            ->assertSee('See this as the merchant', false)
            ->assertSee('As Employer (HR): in Give credits, choose Jordan Rivera', false);
        $this->assertSame(SandboxRoutes::GIVE_STEP, SandboxRoutes::CHECKLIST[1][1]);
        $this->assertStringContainsString('name="step" value="1"', $page->getContent());
    }

    public function test_the_give_step_opens_the_give_credits_form_as_the_employer(): void
    {
        $this->setUpSandboxes();
        $this->startSandbox();
        $this->switchRole('employee');

        $this->sandboxPost(route('sandbox.role'), ['role' => 'employer', 'step' => 1])
            ->assertRedirect(route('employer.dashboard').'#allocate');
        // A step for another role doesn't carry its anchor.
        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'step' => 1])->assertRedirect(route('merchant.dashboard'));
    }

    public function test_the_employer_page_puts_jordan_first_and_labels_him(): void
    {
        $this->setUpSandboxes();
        $this->startSandbox();

        $html = $this->sandboxGet(route('employer.dashboard'))->assertOk()->assertSee("You'll play this employee", false)->getContent();
        $playerId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', SandboxRoutes::email('employee')))->value('id'));
        $this->assertMatchesRegularExpression('/<option value="">Choose an employee<\/option>\s*<option value="'.$playerId.'"[^>]*>[^<]*You\'ll play this employee/s', $html);
    }

    public function test_the_live_employer_page_has_no_play_label(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $this->get(route('employer.dashboard'))->assertOk()->assertDontSee("You'll play this employee", false);
    }

    public function test_jordans_wallet_says_where_credits_come_from_only_when_low(): void
    {
        $this->setUpSandboxes();
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(fn () => json_encode(
            ['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks like a normal purchase.'])));
        $this->startSandbox();
        $this->switchRole('employee');

        // He starts with $120: no hint.
        $this->sandboxGet(route('employee.wallet'))->assertOk()->assertDontSee('data-sandbox-no-credits', false);

        // Down to $2: the hint, with a button that switches to the employer and opens Give credits.
        $grind = $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '118.00'])->assertSessionHasNoErrors();
        $page = $this->sandboxGet(route('employee.wallet'))->assertOk()
            ->assertSee('Credits come from your employer. Switch to Employer (HR) and use Give credits to send some to Jordan.', false)
            ->assertSee('data-sandbox-no-credits', false);
        $this->assertStringContainsString('name="step" value="1"', $page->getContent());
        $this->sandboxPost(route('sandbox.role'), ['role' => 'employer', 'step' => 1])->assertRedirect(route('employer.dashboard').'#allocate');

        // Give $50 as the employer: no longer low.
        $employeeId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', SandboxRoutes::email('employee')))->value('id'));
        $this->sandboxPost(route('employer.allocations.store'), ['mode' => 'individual', 'occasion' => 'recognition', 'amount' => '50', 'employee_id' => $employeeId])->assertSessionHasNoErrors();
        $this->switchRole('employee');
        $this->sandboxGet(route('employee.wallet'))->assertOk()->assertDontSee('data-sandbox-no-credits', false);
    }

    public function test_the_role_bar_is_only_the_five_buttons_and_the_message_sits_below_it(): void
    {
        $this->setUpSandboxes();
        $this->startSandbox();

        $html = $this->sandboxGet(route('employer.dashboard'))->assertOk()->getContent();
        $bar = Str::between($html, 'data-sandbox-bar', '<main');
        $this->assertSame(5, substr_count($bar, 'action="'.route('sandbox.role').'"'));
        $this->assertStringNotContainsString('<select', $bar);

        // After a role switch the message follows the bar (not floating over it).
        $this->switchRole('employee');
        $after = html_entity_decode($this->sandboxGet(route('employee.wallet'))->getContent(), ENT_QUOTES);
        $this->assertStringContainsString("You're now the Employee.", $after);
        $this->assertLessThan(strpos($after, "You're now the Employee."), strpos($after, 'data-sandbox-bar'));
        $this->assertStringContainsString('data-sandbox-flash', $after);
        $this->assertStringNotContainsString('pointer-events-none fixed', $after);
    }

    public function test_the_live_site_toast_still_floats(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $this->withSession(['status' => 'Done.'])->get(route('employer.dashboard'))->assertOk()
            ->assertSee('pointer-events-none fixed', false);
    }
}
