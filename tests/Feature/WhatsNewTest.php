<?php

namespace Tests\Feature;

use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\ChangelogEntry;
use App\Models\Feedback;
use App\Models\User;
use App\Services\ReviewerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsNewTest extends TestCase
{
    use RefreshDatabase;

    private function feedbackFrom(User $user, string $comment): Feedback
    {
        return $user->feedback()->create([
            'page_url' => url('/judge'), 'category' => 'functionality', 'rating' => 3,
            'severity' => 'should_fix', 'comment' => $comment,
        ]);
    }

    private function markFixed(Feedback $item, string $sha): void
    {
        $item->forceFill(['status' => FeedbackStatus::Fixed, 'fixed_commit_sha' => $sha, 'status_changed_at' => now()])->save();
    }

    public function test_the_platform_admin_publishes_changelog_entries(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/admin/changelog', ['title' => 'Faster wallet', 'body' => 'The wallet loads in half the time.'])
            ->assertRedirect('/admin/changelog');
        $this->get('/admin/changelog')->assertSee('Faster wallet');
        $this->assertNotNull(ChangelogEntry::sole()->published_at);

        $this->signIn(Role::Judge);
        $this->get('/admin/changelog')->assertForbidden();
        $this->post('/admin/changelog', ['title' => 'X', 'body' => 'Y'])->assertForbidden();
        $this->assertSame(1, ChangelogEntry::count());
    }

    public function test_only_entries_and_fixes_since_the_last_visit_show(): void
    {
        $judge = User::factory()->role(Role::Judge)->create();
        $link = Str::after(app(ReviewerLinks::class)->newLink($judge), '#');
        $other = User::factory()->role(Role::Judge)->create();

        Carbon::setTestNow('2026-10-01 12:00');
        ChangelogEntry::create(['title' => 'Old news', 'body' => 'Before the visit.', 'published_at' => now()]);
        $mineOld = $this->feedbackFrom($judge, 'Fixed before my visit.');
        $this->markFixed($mineOld, 'aaaaaaa');

        // First visit on Oct 2.
        Carbon::setTestNow('2026-10-02 12:00');
        $this->post('/judge-access', ['link' => $link]);
        $this->post('/logout');

        Carbon::setTestNow('2026-10-05 12:00');
        ChangelogEntry::create(['title' => 'Fresh news', 'body' => 'After the visit.', 'published_at' => now()]);
        $this->markFixed($mine = $this->feedbackFrom($judge, 'My menu bug.'), 'bbbbbbb');
        $this->markFixed($this->feedbackFrom($other, 'Someone else’s bug.'), 'ccccccc');
        $this->feedbackFrom($judge, 'Still open.');

        Carbon::setTestNow('2026-10-06 12:00');
        $this->post('/judge-access', ['link' => $link]);

        $this->get('/judge')->assertOk()
            ->assertSee("What's new since your last visit", false)
            ->assertSee('Fresh news')
            ->assertDontSee('Old news')
            ->assertSee('My menu bug.')
            ->assertSee('bbbbbbb')
            ->assertDontSee('Fixed before my visit.')
            ->assertDontSee('Someone else')
            ->assertDontSee('Still open.');
    }

    public function test_nothing_new_says_so(): void
    {
        $judge = User::factory()->role(Role::Judge)->create(['previous_visit_at' => now()]);
        ChangelogEntry::create(['title' => 'Old', 'body' => 'Old.', 'published_at' => now()->subDay()]);

        $this->actingAs($judge)->get('/judge')->assertSee('Nothing new since your last visit.')->assertDontSee('>Old<', false);
    }

    public function test_a_first_visit_shows_the_latest_entries(): void
    {
        foreach (range(1, 5) as $n) {
            ChangelogEntry::create(['title' => "Entry {$n}", 'body' => 'Body.', 'published_at' => now()->subDays(6 - $n)]);
        }

        $this->signIn(Role::Judge);
        $this->get('/judge')->assertSee("What's new lately", false)
            ->assertSeeInOrder(['Entry 5', 'Entry 4', 'Entry 3'])
            ->assertDontSee('Entry 2');
    }
}
