<?php

namespace Tests\Feature;

use App\Http\Controllers\SandboxController;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\Fraud\FraudVerdict;
use App\Services\Fraud\RedemptionFraudDetector;
use App\Services\Sandbox\CappedClaudeClient;
use App\Services\Sandbox\SandboxFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The sandbox limits (ADR 0045): new sandboxes per IP per hour, sandboxes
 * at once, one per browser, the demo purchase cap, 24 hours, cleanup, the
 * template never built during a request, and the AI caps, which fall back to
 * the existing fail-closed hold.
 */
class SandboxLimitsTest extends TestCase
{
    use RefreshDatabase, UsesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
    }

    private function liveFiles(): int
    {
        return count(glob($this->sandboxDir.'/sb-*.sqlite') ?: []);
    }

    public function test_the_proposed_limits_are_the_configured_ones(): void
    {
        $this->assertSame(10, config('sandbox.creations_per_hour'));
        $this->assertSame(50, config('sandbox.max_live'));
        $this->assertSame(24, config('sandbox.lifetime_hours'));
        $this->assertSame(10, config('sandbox.ai_calls_per_sandbox'));
        $this->assertSame(100, config('sandbox.ai_calls_per_day'));
    }

    public function test_new_sandboxes_are_limited_per_ip_per_hour_and_one_browser_keeps_one(): void
    {
        config(['sandbox.creations_per_hour' => 3]);

        $this->startSandbox();
        $this->startSandbox(); // Start over: replaces the first
        $this->startSandbox();
        $this->assertSame(1, $this->liveFiles(), 'One sandbox per browser');

        $this->sandboxPost(route('sandbox.start'))->assertRedirect(route('sandbox.home'))->assertSessionHas('error');
        $this->assertSame(1, $this->liveFiles());

        // Another visitor (IP) is not affected; an hour later the first can start again.
        $this->sandboxJar = [];
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('sandbox.start'))->assertRedirect(route('sandbox.home'));
        $this->travel(61)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->startSandbox();
    }

    public function test_no_more_than_the_maximum_live_at_once(): void
    {
        config(['sandbox.max_live' => 2]);
        foreach (['203.0.113.1', '203.0.113.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('sandbox.start'))->assertRedirect(route('sandbox.home'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->post(route('sandbox.start'))
            ->assertRedirect(route('sandbox.show'))->assertSessionHas('error', 'Every sandbox is in use right now. Each one lasts 24 hours; please try again later.');
        $this->assertSame(2, $this->liveFiles());

        // Expired ones don't count, and are cleared on the next start.
        $this->travel(25)->hours();
        copy(self::template(), app(SandboxFiles::class)->templatePath());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->post(route('sandbox.start'))->assertRedirect(route('sandbox.home'));
        $this->assertSame(1, $this->liveFiles());
    }

    public function test_without_todays_template_start_says_it_is_being_prepared_and_builds_nothing(): void
    {
        File::delete(app(SandboxFiles::class)->templatePath());

        $this->get(route('sandbox.show'))->assertOk()->assertSee(SandboxController::PREPARING)->assertDontSee('Start my sandbox');
        $this->post(route('sandbox.start'))->assertRedirect(route('sandbox.show'))->assertSessionHas('error', SandboxController::PREPARING);
        $this->assertSame(0, $this->liveFiles());
        $this->assertFileDoesNotExist(app(SandboxFiles::class)->templatePath(), 'A web request never builds the template');
    }

    public function test_the_sandbox_employer_has_the_demo_purchase_cap(): void
    {
        $this->startSandbox();

        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '5000.01'])->assertSessionHasErrors('purchase_amount');
        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '5000'])->assertSessionHasNoErrors();
    }

    public function test_prune_deletes_only_ended_sandboxes_and_old_templates(): void
    {
        $this->startSandbox();
        $old = $this->sandboxId();
        $this->travel(23)->hours();
        $this->sandboxJar = [];
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
        copy(self::template(), app(SandboxFiles::class)->templatePath());
        $this->startSandbox();
        $young = $this->sandboxId();
        File::put($this->sandboxDir.'/template-2020-01-01.sqlite', 'old');

        $this->travel(2)->hours(); // the first is now 25 hours old, the second 2
        copy(self::template(), app(SandboxFiles::class)->templatePath());
        $this->artisan('sandbox:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($this->sandboxDir."/sb-{$old}.sqlite");
        $this->assertFileDoesNotExist($this->sandboxDir."/sb-{$old}.json");
        $this->assertFileExists($this->sandboxDir."/sb-{$young}.sqlite");
        $this->assertFileDoesNotExist($this->sandboxDir.'/template-2020-01-01.sqlite');
        $this->assertFileExists(app(SandboxFiles::class)->templatePath());
    }

    public function test_cleanup_and_the_template_build_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());
        $prune = $events->first(fn ($e) => str_contains($e->command, 'sandbox:prune'));
        $build = $events->first(fn ($e) => str_contains($e->command, 'sandbox:build-template'));

        $this->assertSame('0 * * * *', $prune->expression);
        $this->assertSame('*/5 * * * *', $build->expression);
    }

    public function test_past_the_per_sandbox_ai_cap_payments_are_held_by_the_existing_fail_closed_path(): void
    {
        config(['sandbox.ai_calls_per_sandbox' => 1]);
        $claude = FakeClaudeClient::verdict(10, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);

        $this->startSandbox();
        $this->switchRole('employee');
        $grind = $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));

        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '5.00']);
        $this->assertTrue($this->insideSandbox(fn () => Redemption::orderByDesc('id')->first()->isApproved()));

        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '5.00']);
        $held = $this->insideSandbox(fn () => Redemption::orderByDesc('id')->first());
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $held->status);
        $this->assertSame(FraudVerdict::HOLD, $held->ai_decision);
        $this->assertSame('fallback', $held->scored_by, 'The same fallback as when the AI cannot be reached');
        $this->assertSame(RedemptionFraudDetector::FALLBACK_EXPLANATION, $held->ai_explanation);
        $this->assertCount(1, $claude->calls, 'The AI was not called past the cap');
    }

    public function test_past_the_daily_cap_across_all_sandboxes_payments_are_held_without_calling_the_ai(): void
    {
        $claude = FakeClaudeClient::verdict(10, 'clear');
        $this->app->instance(ClaudeClient::class, $claude);
        $this->startSandbox();
        $this->switchRole('employee');
        File::put($this->sandboxDir.'/ai-calls-'.Carbon::now('America/New_York')->toDateString().'.count', '100');

        $grind = $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '5.00']);

        $held = $this->insideSandbox(fn () => Redemption::orderByDesc('id')->first());
        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $held->status);
        $this->assertSame('fallback', $held->scored_by);
        $this->assertSame([], $claude->calls);
    }

    public function test_the_live_site_has_no_ai_cap(): void
    {
        $this->assertNotInstanceOf(CappedClaudeClient::class, app(ClaudeClient::class));
    }
}
