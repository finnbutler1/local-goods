<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackSeverity;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return [
            'page_url' => url('/bid-admin'),
            'category' => 'ai_integration',
            'rating' => 4,
            'severity' => 'should_fix',
            'comment' => 'The risk score is clear, but say what the scale means.',
            ...$overrides,
        ];
    }

    public static function signedInPages(): array
    {
        return [
            'judge home' => [Role::Judge, '/judge'],
            'judge viewing BID admin' => [Role::Judge, '/bid-admin'],
            'employee wallet' => [Role::Employee, '/employee'],
            'platform admin' => [Role::PlatformAdmin, '/admin'],
            'account' => [Role::Merchant, '/account'],
        ];
    }

    #[DataProvider('signedInPages')]
    public function test_every_signed_in_page_has_the_feedback_button(Role $role, string $url): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn($role);

        $this->get($url)->assertOk()
            ->assertSee('data-dialog-open="feedback-dialog"', false)
            ->assertSee('action="'.route('feedback.store').'"', false)
            ->assertSee('name="page_url" value="'.url($url).'"', false);
    }

    public function test_on_phones_the_button_is_a_named_icon_that_steps_aside(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);

        $html = $this->get('/employee')->getContent();

        // Icon only below desktop width, with an accessible name; the word shows from lg up.
        $this->assertStringContainsString('aria-label="Leave feedback"', $html);
        $this->assertStringContainsString('<span class="hidden lg:inline" aria-hidden="true">Feedback</span>', $html);
        // Above the bottom tab bar, and hidden while it would cover something (resources/js/app.js).
        $this->assertStringContainsString('data-feedback-widget', $html);
        $this->assertStringContainsString('bottom-[calc(4.75rem+env(safe-area-inset-bottom))]', $html);
        $this->assertStringContainsString('data-[covering]:opacity-0', $html);
        $this->assertStringContainsString('data-[covering]:focus-within:opacity-100', $html, 'Keyboard users can still reach it');
        $this->assertStringContainsString('[data-feedback-widget]', file_get_contents(resource_path('js/app.js')));
    }

    public function test_signed_out_pages_have_no_feedback_button(): void
    {
        $this->get('/login')->assertDontSee('feedback-dialog');
        $this->get('/')->assertDontSee('feedback-dialog');
    }

    public function test_a_judge_leaves_feedback_with_the_page_address(): void
    {
        $judge = $this->signIn(Role::Judge);

        $this->from('/judge')->post('/feedback', $this->form())
            ->assertRedirect('/judge')
            ->assertSessionHas('status', 'Thanks, your feedback was sent.');

        $item = Feedback::sole();
        $this->assertSame($judge->id, $item->user_id);
        $this->assertSame(url('/bid-admin'), $item->page_url);
        $this->assertSame(FeedbackCategory::AiIntegration, $item->category);
        $this->assertSame(4, $item->rating);
        $this->assertSame(FeedbackSeverity::ShouldFix, $item->severity);
        $this->assertSame(FeedbackStatus::New, $item->status);
    }

    public function test_bad_input_is_refused_and_reopens_the_form(): void
    {
        $this->signIn(Role::Judge);

        $this->from('/judge')->post('/feedback', $this->form(['rating' => 6, 'category' => 'vibes', 'severity' => '', 'comment' => '']))
            ->assertRedirect('/judge')
            ->assertSessionHasErrors(['rating', 'category', 'severity', 'comment'], errorBag: 'feedback');
        $this->assertSame(0, Feedback::count());

        $this->followingRedirects()->from('/judge')->post('/feedback', $this->form(['comment' => '']))
            ->assertSee('data-open-on-load', false)->assertSee('Tell us a little about what you saw.');
    }

    public function test_a_reviewer_cannot_set_status_or_commit_through_the_form(): void
    {
        $this->signIn(Role::Judge);

        $this->post('/feedback', $this->form(['status' => 'fixed', 'fixed_commit_sha' => 'abc1234', 'user_id' => 999]));

        $item = Feedback::sole();
        $this->assertSame(FeedbackStatus::New, $item->status);
        $this->assertNull($item->fixed_commit_sha);
    }

    public function test_a_judge_edits_their_own_feedback_but_not_anyone_elses(): void
    {
        $judge = $this->signIn(Role::Judge);
        $this->post('/feedback', $this->form());
        $mine = Feedback::sole();

        $other = User::factory()->role(Role::Judge)->create();
        $theirs = $other->feedback()->create($this->form(['comment' => 'Theirs.']));

        $this->get('/account')->assertSee('Your feedback')->assertSee($mine->comment)->assertDontSee('Theirs.');

        $this->patch("/feedback/{$mine->id}", $this->form(['rating' => 2, 'comment' => 'Changed my mind.']))
            ->assertRedirect(route('account')."#feedback-{$mine->id}");
        $this->assertSame('Changed my mind.', $mine->fresh()->comment);
        $this->assertSame(2, $mine->fresh()->rating);

        $this->patch("/feedback/{$theirs->id}", $this->form(['comment' => 'Hijacked.']))->assertForbidden();
        $this->assertSame('Theirs.', $theirs->fresh()->comment);
    }
}
