<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Ai\ClaudeClient;
use App\Services\ReviewerLinks;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The shared "Public judge access" reviewer behind the README link: one
 * generic read-only account, its own type in the feedback inbox, tighter
 * feedback limits, and no say over redemptions.
 */
class PublicJudgeAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $this->public = User::where('reviewer_type', ReviewerType::Public)->sole();
    }

    private function feedback(string $comment = 'Synthetic public feedback.'): array
    {
        return ['page_url' => url('/judge'), 'category' => 'ux_design', 'rating' => 4, 'severity' => 'should_fix', 'comment' => $comment];
    }

    public function test_the_public_link_signs_in_to_the_generic_read_only_account(): void
    {
        $link = app(ReviewerLinks::class)->newLink($this->public);

        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect('/judge');
        $this->assertAuthenticatedAs($this->public);
        $this->assertTrue($this->public->isReadOnly());
        $this->get('/admin/reviewers')->assertForbidden();
        $this->get('/admin/feedback')->assertForbidden();
    }

    public function test_it_cannot_approve_or_deny_a_held_redemption_or_run_the_collusion_example(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'Round number.'));
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => Merchant::firstOrFail()->id, 'amount' => '20']);
        $held = Redemption::latest('id')->firstOrFail();
        $this->assertTrue($held->isUnderReview());

        $this->assertFalse($this->public->can('review', $held));
        $this->actingAs($this->public);
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Looks fine to me.'])->assertForbidden();
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Looks wrong to me.', 'denial' => 'suspected_abuse'])->assertForbidden();
        $this->post(route('bid-admin.collusion-example'))->assertForbidden();
        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertNull($held->fresh()->reviewed_by_user_id);
    }

    public function test_its_feedback_shows_as_its_own_reviewer_type_in_the_inbox(): void
    {
        $this->actingAs($this->public)->post('/feedback', $this->feedback('From the README link.'))->assertSessionHasNoErrors();

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/feedback')->assertOk()->assertSee('Public judge access · Public judge access');
        $this->get('/admin/feedback?reviewer_type=public')->assertOk()->assertSee('From the README link.');
        $this->get('/admin/feedback?reviewer_type=contest_judge')->assertOk()->assertDontSee('From the README link.');
    }

    public function test_its_feedback_is_rate_limited_more_tightly_than_named_reviewers(): void
    {
        $this->actingAs($this->public);
        foreach (range(1, AppServiceProvider::PUBLIC_FEEDBACK_PER_MINUTE) as $_) {
            $this->post('/feedback', $this->feedback())->assertRedirect();
        }
        $this->post('/feedback', $this->feedback())->assertStatus(429);

        // A named reviewer from the same IP still has room.
        $named = User::factory()->role(Role::Judge)->create();
        $named->forceFill(['reviewer_type' => ReviewerType::ContestJudge])->save();
        $this->actingAs($named);
        foreach (range(1, AppServiceProvider::PUBLIC_FEEDBACK_PER_MINUTE + 2) as $_) {
            $this->post('/feedback', $this->feedback())->assertRedirect();
        }
    }

    public function test_its_daily_feedback_limit(): void
    {
        $this->actingAs($this->public);
        foreach (range(1, AppServiceProvider::PUBLIC_FEEDBACK_PER_DAY) as $i) {
            if ($i % AppServiceProvider::PUBLIC_FEEDBACK_PER_MINUTE === 0) {
                $this->travel(61)->seconds();
            }
            $this->post('/feedback', $this->feedback())->assertRedirect();
        }

        $this->travel(61)->seconds();
        $this->post('/feedback', $this->feedback())->assertStatus(429);
    }

    public function test_the_admin_page_shows_it_separately_with_its_own_new_link_button(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin/reviewers')->assertOk()
            ->assertSeeInOrder(['Public judge access', 'No link yet', 'New public link', 'Invite a reviewer', 'Named reviewers'])
            ->assertDontSee('<option value="public"', false);

        $this->post("/admin/reviewers/{$this->public->id}/link")->assertSessionHas('new_reviewer_link');
        $this->assertStringContainsString('/judge-access#'.$this->public->id.'.', session('new_reviewer_link')['url']);
    }

    public function test_the_invite_form_cannot_make_a_second_public_account(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/admin/reviewers', ['name' => 'Another', 'email' => 'another@example.test', 'reviewer_type' => 'public'])
            ->assertSessionHasErrors('reviewer_type');
        $this->assertSame(1, User::where('reviewer_type', ReviewerType::Public)->count());
    }
}
