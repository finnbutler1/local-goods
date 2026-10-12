<?php

namespace Tests\Feature;

use App\Enums\FeedbackStatus;
use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::factory()->role(Role::Judge)->create(['name' => 'Drew Patel']);
        $this->hr->forceFill(['reviewer_type' => ReviewerType::EmployerHr])->save();
        $this->friend = User::factory()->role(Role::Judge)->create(['name' => 'Quinn Lee']);
        $this->friend->forceFill(['reviewer_type' => ReviewerType::Friend])->save();

        $this->give($this->hr, 'ux_design', 4, 'blocker', 'HR: the menu is hard to find.');
        $this->give($this->hr, 'ux_design', 2, 'nice_to_have', 'HR: colors are a bit flat.');
        $this->give($this->friend, 'ai_integration', 5, 'should_fix', 'Friend: love the AI explanation.');
    }

    private function give(User $user, string $category, int $rating, string $severity, string $comment): Feedback
    {
        return $user->feedback()->create([
            'page_url' => url('/bid-admin'), 'category' => $category, 'rating' => $rating,
            'severity' => $severity, 'comment' => $comment,
        ]);
    }

    public function test_the_inbox_lists_everything_with_averages_per_category(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $page = $this->get('/admin/feedback')->assertOk()
            ->assertSee('HR: the menu is hard to find.')
            ->assertSee('Friend: love the AI explanation.')
            ->assertSee('Drew Patel · Employer HR')
            ->assertSee('Quinn Lee · Friend');

        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/data-category="ux_design".*?3\.0.*?2 reviews/s', $html);
        $this->assertMatchesRegularExpression('/data-category="ai_integration".*?5\.0.*?1 review</s', $html);
        $this->assertMatchesRegularExpression('/data-category="code_quality".*?No reviews yet/s', $html);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin/feedback?category=ai_integration')
            ->assertSee('Friend: love')->assertDontSee('HR: the menu');
        $this->get('/admin/feedback?severity=blocker')
            ->assertSee('HR: the menu')->assertDontSee('HR: colors')->assertDontSee('Friend: love');
        $this->get('/admin/feedback?reviewer='.$this->friend->id)
            ->assertSee('Friend: love')->assertDontSee('HR: the menu');
        $this->get('/admin/feedback?reviewer_type=employer_hr')
            ->assertSee('HR: the menu')->assertSee('HR: colors')->assertDontSee('Friend: love');

        Feedback::where('comment', 'like', 'HR: colors%')->first()->forceFill(['status' => FeedbackStatus::WontFix])->save();
        $this->get('/admin/feedback?status=wont_fix')
            ->assertSee('HR: colors')->assertDontSee('HR: the menu');
    }

    public function test_status_and_commit_sha_are_saved(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $item = Feedback::where('comment', 'like', 'HR: the menu%')->first();

        $this->from('/admin/feedback?severity=blocker')
            ->patch("/admin/feedback/{$item->id}", ['status' => 'fixed', 'fixed_commit_sha' => 'ABC1234'])
            ->assertRedirect("/admin/feedback?severity=blocker#feedback-{$item->id}");

        $item->refresh();
        $this->assertSame(FeedbackStatus::Fixed, $item->status);
        $this->assertSame('abc1234', $item->fixed_commit_sha);
        $this->assertNotNull($item->status_changed_at);

        $this->patch("/admin/feedback/{$item->id}", ['status' => 'fixed', 'fixed_commit_sha' => 'not a sha'])
            ->assertSessionHasErrors('fixed_commit_sha');
        $this->assertSame('abc1234', $item->fresh()->fixed_commit_sha);
    }

    public function test_the_sidebar_counts_unread_feedback(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin')->assertSee('3<span class="sr-only"> unread</span>', false);

        Feedback::first()->forceFill(['status' => FeedbackStatus::Acknowledged])->save();
        $this->get('/admin')->assertSee('2<span class="sr-only"> unread</span>', false);

        Feedback::query()->update(['status' => 'fixed']);
        $this->get('/admin')->assertDontSee('<span class="sr-only"> unread</span>', false);
    }

    public function test_judges_and_other_roles_cannot_open_the_inbox(): void
    {
        foreach ([Role::Judge, Role::BidAdmin, Role::Employee] as $role) {
            $this->signIn($role);
            $this->get('/admin/feedback')->assertForbidden();
        }

        $this->actingAs($this->hr)->get('/judge')->assertDontSee('unread</span>', false);
    }
}
