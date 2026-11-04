<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SolvencyCheckRun;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin Home pages (ADR 0035): the platform admin's /admin and the BID
 * admin's /bid-admin/home (judges see the latter as the BID admin). Each card
 * links to its page and reads only stored data.
 */
class AdminHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_platform_admin_lands_on_a_home_with_every_card_linking_to_its_page(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));

        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        foreach (['waiting' => 'data-waiting=', 'solvency' => 'data-home-card="solvency"', 'reconciliation' => 'data-home-card="reconciliation"', 'demo' => 'data-home-card="demo-activity"', 'recent' => 'data-home-card="recent"'] as $what => $marker) {
            $this->assertStringContainsString($marker, $html, $what);
        }
        $main = str($html)->after('<main')->before('</main>')->toString();
        foreach (['money', 'admin.reconciliation', 'bid-admin.demo-activity', 'admin.feedback.index'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $main, $route);
        }
        $this->assertStringNotContainsString('Invite reviewers', $main, 'The row of shortcut links is gone.');
        $this->assertStringContainsString('<h1 class="mt-1 text-title font-semibold text-primary">Home</h1>', $html);
    }

    public function test_the_solvency_card_shows_pass_or_fail_and_when(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->get(route('admin.dashboard'))->assertSee('No check has been recorded yet.');

        SolvencyCheckRun::record(app(SolvencyCheck::class)->history(), SolvencyCheckRun::TRIGGER_ADMIN);
        $this->get(route('admin.dashboard'))->assertSee('Pass')->assertSee('by the platform admin');
    }

    public function test_the_accounts_table_moved_to_its_own_page(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('admin.accounts'))->assertOk()->assertSee('Revoked accounts are hidden.');
        $this->assertSame('/admin/accounts', parse_url(route('admin.accounts'), PHP_URL_PATH));
        $this->get(route('admin.dashboard'))->assertDontSee('Revoked accounts are hidden.');
    }

    public function test_unread_feedback_is_counted_on_the_feedback_card(): void
    {
        $user = $this->signIn(Role::PlatformAdmin);
        $this->get(route('admin.dashboard'))->assertSee('No new feedback.');

        $user->feedback()->create(['page_url' => url('/bid-admin'), 'category' => 'ux_design', 'rating' => 3, 'severity' => 'should_fix', 'comment' => 'The menu is clear.']);
        $this->get(route('admin.dashboard'))->assertDontSee('No new feedback.')->assertSee('new item');
    }

    public function test_the_bid_admin_lands_on_its_home_and_slash_bid_admin_stays_fraud_review(): void
    {
        $this->signIn(Role::BidAdmin);
        $this->get('/dashboard')->assertRedirect(route('bid-admin.home'));
        $this->assertSame('/bid-admin/home', parse_url(route('bid-admin.home'), PHP_URL_PATH));

        $html = $this->get(route('bid-admin.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-home-card="solvency"', $html);
        $this->assertStringContainsString('data-home-card="demo-activity"', $html);
        $this->assertStringNotContainsString('data-home-card="reconciliation"', $html, 'Stripe reconciliation is platform admin only.');
        $this->assertStringNotContainsString('data-home-card="feedback"', $html);
        $this->assertStringNotContainsString('Operating', $html, 'No figures the BID admin cannot see.');

        $this->get('/bid-admin')->assertOk()->assertSee('<h1 class="mt-1 text-title font-semibold text-primary">Fraud Review</h1>', false);
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_a_judge_viewing_as_the_bid_admin_lands_on_the_bid_admin_home_read_only(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/bid_admin')->assertRedirect(route('bid-admin.home'));
        $this->get(route('bid-admin.home'))->assertOk()->assertSee('This is read-only.')
            ->assertSee('data-home-card="solvency"', false);
        $this->get(route('admin.dashboard'))->assertForbidden();
    }
}
