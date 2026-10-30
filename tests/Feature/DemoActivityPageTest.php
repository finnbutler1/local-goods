<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * /bid-admin/demo-activity (ADR 0032): what the generator has done, read
 * only, for the BID admin, the platform admin and judges; and the "waiting
 * for a person" count on the admin homes and the corrections queue.
 */
class DemoActivityPageTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
    }

    public function test_it_shows_the_switch_and_an_empty_start(): void
    {
        config(['demo_activity.enabled' => false]);
        $this->signIn(Role::BidAdmin);

        $this->get(route('bid-admin.demo-activity'))->assertOk()
            ->assertSee('Fictional people, real Stripe test-mode transactions')
            ->assertSee('data-demo-activity-state="off"', false)
            ->assertSee('Off: DEMO_ACTIVITY_ENABLED is not true.')
            ->assertSee('Nothing yet.')
            ->assertSee('Read-only.');
    }

    public function test_after_a_day_it_shows_runs_totals_and_actions_by_initials_only(): void
    {
        $this->runDay('2026-10-26');

        foreach ([Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $page = $this->get(route('bid-admin.demo-activity'))->assertOk()
                ->assertSee('data-demo-activity-state="on"', false)
                ->assertSee('Mon, Oct 26')
                ->assertSee('Latest actions')
                ->assertSee('Ordered to go')
                ->assertSee('Paid in person')
                ->assertSee('Cleared by the AI')
                ->assertDontSee('<form method="POST" action="'.route('bid-admin.demo-activity'), false);

            foreach (Employee::whereNotNull('id')->pluck('display_name') as $name) {
                $page->assertDontSee($name);
            }
            $this->assertSame([], AccessibilityAudit::problems($page->getContent()));
        }
    }

    public function test_the_status_command_reports_the_switch_days_and_waiting_items(): void
    {
        $this->runDay('2026-10-26');

        $this->artisan('demo-activity:status')->assertSuccessful()
            ->expectsOutputToContain('Kill switch: DEMO_ACTIVITY_ENABLED is true (on); stops by itself after 2026-11-30.')
            ->expectsOutputToContain('Waiting for a person:')
            ->expectsOutputToContain('Mon Oct 26');

        config(['demo_activity.enabled' => false]);
        $this->artisan('demo-activity:status')->assertSuccessful()->expectsOutputToContain('DEMO_ACTIVITY_ENABLED is false (off)');
    }

    public function test_employees_and_merchants_cannot_open_it(): void
    {
        $this->signIn(Role::Employee);
        $this->get(route('bid-admin.demo-activity'))->assertForbidden();
        $this->signIn(Role::Merchant);
        $this->get(route('bid-admin.demo-activity'))->assertForbidden();
    }

    public function test_the_waiting_count_shows_on_the_admin_homes_and_says_plainly_when_overdue(): void
    {
        $this->app->instance(\App\Services\Ai\ClaudeClient::class, FakeClaudeClient::verdict(85, 'hold', 'Unusual.'));
        $this->tickAt('2026-10-26 12:00');
        $held = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count();
        $this->assertGreaterThan(0, $held);

        $this->signIn(Role::PlatformAdmin);
        foreach ([route('admin.dashboard'), route('bid-admin.dashboard'), route('bid-admin.corrections'), route('bid-admin.demo-activity')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Waiting for a person: '.($held + 0), false)
                ->assertSee($held.' held '.($held === 1 ? 'payment' : 'payments'))
                ->assertDontSee('data-waiting-overdue', false);
        }

        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00', 'America/New_York'));
        $this->get(route('bid-admin.demo-activity'))->assertOk()
            ->assertSee('data-waiting-overdue', false)
            ->assertSee('Something has waited more than 3 days for a person.');
    }
}
