<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Services\ReviewerLinks;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public judge link from PUBLIC_JUDGE_URL: the landing button, the admin
 * card, and what changes because the link is public. Every link here is a
 * throwaway one made by the test.
 */
class PublicJudgeLinkTest extends TestCase
{
    use RefreshDatabase;

    private const BUTTON = 'Look around (read-only)';

    private const NOT_SET = 'Not set: add PUBLIC_JUDGE_URL in the Beanstalk environment properties.';

    private User $public;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewers.judging_ends_at' => now()->addMonth()->toDateTimeString()]);
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $this->public = User::where('reviewer_type', ReviewerType::Public)->sole();
    }

    private function setLink(?User $for = null): string
    {
        $fragment = Str::after(app(ReviewerLinks::class)->newLink($for ?? $this->public), '#');
        $url = 'https://uselocalgoods.com/judge-access#'.$fragment;
        config(['reviewers.public_judge_url' => $url]);

        return $url;
    }

    private function namedJudge(): User
    {
        $judge = User::factory()->role(Role::Judge)->create(['name' => 'Avery Example']);
        $judge->forceFill(['reviewer_type' => ReviewerType::ContestJudge])->save();

        return $judge;
    }

    /** @return array<string, mixed> */
    private function feedback(string $comment = 'Synthetic public feedback.'): array
    {
        return ['page_url' => url('/judge'), 'category' => 'ux_design', 'rating' => 4, 'severity' => 'should_fix', 'comment' => $comment];
    }

    public function test_the_route_the_link_must_point_to_is_the_judge_access_page(): void
    {
        $this->assertSame('/judge-access', parse_url(route('judge-access.show'), PHP_URL_PATH));
    }

    public function test_logged_out_visitors_get_the_button_when_the_link_is_valid(): void
    {
        $url = $this->setLink();

        $html = $this->get('/')->assertOk()->assertSee(self::BUTTON)->getContent();
        $this->assertStringContainsString('href="'.$url.'"', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*rel="nofollow"[^>]*>\s*'.preg_quote(self::BUTTON, '/').'/', $html);
    }

    public function test_the_button_is_hidden_when_the_link_is_not_set(): void
    {
        config(['reviewers.public_judge_url' => null]);

        $this->get('/')->assertOk()->assertDontSee(self::BUTTON);
    }

    /** @return array<string, array{0: callable(string): string}> */
    public static function invalidLinks(): array
    {
        return [
            'wrong host' => [fn (string $url) => str_replace('uselocalgoods.com', 'example.test', $url)],
            'lookalike host' => [fn (string $url) => str_replace('uselocalgoods.com', 'uselocalgoods.com.example.test', $url)],
            'www host' => [fn (string $url) => str_replace('uselocalgoods.com', 'www.uselocalgoods.com', $url)],
            'http' => [fn (string $url) => str_replace('https:', 'http:', $url)],
            'port' => [fn (string $url) => str_replace('.com/', '.com:8443/', $url)],
            'wrong path' => [fn (string $url) => str_replace('/judge-access', '/login', $url)],
            'query' => [fn (string $url) => str_replace('#', '?a=1#', $url)],
            'no token' => [fn (string $url) => Str::before($url, '#')],
            'malformed token' => [fn (string $url) => $url.'!'],
            'trailing newline' => [fn (string $url) => $url."\n"],
            'wrong token' => [fn (string $url) => substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a')],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_an_invalid_link_counts_as_not_set(callable $break): void
    {
        config(['reviewers.public_judge_url' => $break($this->setLink())]);

        $this->get('/')->assertOk()->assertDontSee(self::BUTTON);
        $this->signIn(Role::PlatformAdmin);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(self::NOT_SET);
    }

    public function test_a_named_reviewers_link_is_never_published(): void
    {
        $this->setLink($this->namedJudge());

        $this->get('/')->assertOk()->assertDontSee(self::BUTTON);
    }

    public function test_the_link_hides_itself_once_judging_has_ended(): void
    {
        $this->setLink();
        $this->get('/')->assertSee(self::BUTTON);

        $this->travel(2)->months();
        $this->get('/')->assertOk()->assertDontSee(self::BUTTON);
    }

    public function test_the_link_opens_the_public_account_again_and_again(): void
    {
        $url = $this->setLink();
        $fragment = Str::after($url, '#');

        foreach (range(1, 3) as $_) {
            $this->post('/judge-access', ['link' => $fragment])->assertRedirect('/judge');
            $this->assertAuthenticatedAs($this->public);
            $this->post('/logout');
        }
    }

    /** @return array<string, array{0: Role}> */
    public static function signedInRoles(): array
    {
        return array_combine(
            array_map(fn (Role $role) => $role->value, Role::cases()),
            array_map(fn (Role $role) => [$role], Role::cases()),
        );
    }

    #[DataProvider('signedInRoles')]
    public function test_nobody_signed_in_gets_the_button(Role $role): void
    {
        $url = $this->setLink();
        $this->signIn($role);

        $response = $this->get('/');
        $response->assertDontSee(self::BUTTON);
        $this->assertStringNotContainsString(Str::after($url, '#'), $response->getContent());
    }

    public function test_the_platform_admin_card_shows_the_link_and_the_copy_button(): void
    {
        $url = $this->setLink();
        $this->signIn(Role::PlatformAdmin);

        $html = $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee("Read-only access for judges and guests. Anyone with this link can look around but can't change anything.", false)
            ->assertDontSee(self::NOT_SET)
            ->getContent();

        foreach (['data-home-card="public-judge-link"', 'value="'.$url.'"', 'readonly', 'data-copy="#public-judge-link"', 'role="status"', '<label for="public-judge-link"'] as $piece) {
            $this->assertStringContainsString($piece, $html, $piece);
        }
    }

    public function test_the_platform_admin_card_says_when_it_is_not_set(): void
    {
        config(['reviewers.public_judge_url' => null]);
        $this->signIn(Role::PlatformAdmin);

        $html = $this->get(route('admin.dashboard'))->assertOk()->assertSee(self::NOT_SET)->getContent();
        $this->assertStringNotContainsString('id="public-judge-link"', $html);
    }

    public function test_the_card_is_for_the_platform_admin_only(): void
    {
        $url = $this->setLink();
        $token = Str::after($url, '#');

        $this->signIn(Role::BidAdmin);
        $html = $this->get(route('bid-admin.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('public-judge-link', $html);
        $this->assertStringNotContainsString($token, $html);

        $this->actingAs($this->namedJudge());
        $this->get(route('judge.view-as', Role::BidAdmin->value));
        $html = $this->get(route('bid-admin.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('public-judge-link', $html);
        $this->assertStringNotContainsString($token, $html);
    }

    public function test_the_public_judge_is_greeted_with_just_welcome(): void
    {
        $this->actingAs($this->public);

        $this->get('/judge')->assertOk()
            ->assertSee('<h1 class="page-title">Welcome</h1>', false)
            ->assertDontSee('Welcome, Public');
    }

    public function test_a_named_judge_keeps_the_personal_greeting(): void
    {
        $this->actingAs($this->namedJudge());

        $this->get('/judge')->assertOk()->assertSee('Welcome, Avery.');
    }

    public function test_judge_pages_tell_search_engines_to_stay_out(): void
    {
        $noindex = '<meta name="robots" content="noindex, nofollow">';

        $this->get('/')->assertOk()->assertDontSee($noindex, false);
        $this->get('/judge-access')->assertOk()->assertSee($noindex, false);
        $this->post('/judge-access', ['link' => 'wrong'])->assertStatus(410)->assertSee($noindex, false);

        $this->actingAs($this->public);
        $this->get('/judge')->assertOk()->assertSee($noindex, false);
        $this->get('/account')->assertOk()->assertSee($noindex, false);
    }

    public function test_the_public_account_can_send_feedback_but_not_see_or_edit_it(): void
    {
        $this->actingAs($this->public);

        $this->post('/feedback', $this->feedback('A note from one visitor.'))->assertRedirect();
        $item = Feedback::where('user_id', $this->public->id)->sole();

        $this->get('/account')->assertOk()
            ->assertDontSee('A note from one visitor.')
            ->assertDontSee(route('feedback.update', $item));
        $this->get('/account/data')->assertOk()->assertDontSee('see and edit it here');

        $this->patch("/feedback/{$item->id}", $this->feedback('Overwritten by the next visitor.'))->assertForbidden();
        $this->assertSame('A note from one visitor.', $item->fresh()->comment);
    }

    public function test_a_named_judge_still_sees_and_edits_their_own_feedback(): void
    {
        $judge = $this->namedJudge();
        $this->actingAs($judge);

        $this->post('/feedback', $this->feedback('My own note.'))->assertRedirect();
        $item = Feedback::where('user_id', $judge->id)->sole();

        $this->get('/account')->assertOk()->assertSee('My own note.');
        $this->get('/account/data')->assertOk()->assertSee('see and edit it here');
        $this->patch("/feedback/{$item->id}", $this->feedback('My edited note.'))->assertRedirect();
        $this->assertSame('My edited note.', $item->fresh()->comment);
    }

    public function test_public_feedback_is_rate_limited(): void
    {
        $this->actingAs($this->public);

        foreach (range(1, AppServiceProvider::PUBLIC_FEEDBACK_PER_MINUTE) as $_) {
            $this->post('/feedback', $this->feedback())->assertRedirect();
        }
        $this->post('/feedback', $this->feedback())->assertStatus(429);
    }

    public function test_the_public_account_cannot_vote_and_its_attempts_are_rate_limited(): void
    {
        $this->actingAs($this->public);

        foreach (range(1, 60) as $_) {
            $this->post('/adjudicate/submit')->assertForbidden();
        }
        $this->post('/adjudicate/submit')->assertStatus(429);
    }

    public function test_magic_link_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/judge-access', ['link' => "1.wrong-{$i}"])->assertStatus(410);
        }
        $this->post('/judge-access', ['link' => '1.wrong'])->assertStatus(429);
    }

    public function test_one_visitor_logging_out_repeatedly_does_not_use_up_everyones_limit(): void
    {
        $from = fn (string $ip) => $this->actingAs($this->public)->withServerVariables(['REMOTE_ADDR' => $ip])->post('/logout');

        foreach (range(1, FortifyServiceProvider::AUTH_FORMS_PER_MINUTE) as $_) {
            $this->assertNotSame(429, $from('203.0.113.10')->getStatusCode());
        }
        $from('203.0.113.10')->assertStatus(429);

        $this->assertNotSame(429, $from('203.0.113.20')->getStatusCode());
    }
}
