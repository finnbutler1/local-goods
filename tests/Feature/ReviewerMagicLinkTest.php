<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Services\ReviewerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reviewers sign in with a personal magic link: no account setup, no
 * password. It works again and again until judging ends, they are revoked,
 * or the admin makes a new link, and it only ever opens a judge account.
 */
class ReviewerMagicLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewers.judging_ends_at' => '2026-11-30 23:59']);
        $this->travelTo('2026-10-20 12:00');
    }

    /** Invites a synthetic reviewer as the platform admin and returns the link shown once. */
    private function invite(string $email = 'casey.judge@example.test'): string
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/admin/reviewers', ['name' => 'Casey Morgan', 'email' => $email, 'reviewer_type' => 'contest_judge'])
            ->assertRedirect('/admin/reviewers')
            ->assertSessionHas('new_reviewer_link');

        $link = session('new_reviewer_link')['url'];
        $this->signOut();

        return $link;
    }

    private function reviewer(): User
    {
        return User::where('email', 'casey.judge@example.test')->firstOrFail();
    }

    /** What the sign-in page posts: the part of the link after #. */
    private function open(string $link): TestResponse
    {
        $this->get('/judge-access')->assertOk();

        return $this->post('/judge-access', ['link' => Str::after($link, '#')]);
    }

    private function signOut(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    public function test_the_platform_admin_creates_a_read_only_reviewer_with_a_magic_link(): void
    {
        $link = $this->invite();

        $user = $this->reviewer();
        $this->assertSame(Role::Judge, $user->role);
        $this->assertSame(ReviewerType::ContestJudge, $user->reviewer_type);
        $this->assertNotNull($user->invited_at);
        $this->assertNull($user->first_login_at);

        // The link is /judge-access#<id>.<token>: the token is after #, so it is never sent to the server in the address.
        $this->assertMatchesRegularExpression('#^http://localhost(:\d+)?/judge-access\#'.$user->id.'\.[A-Za-z0-9]{48}$#', $link);

        // Only a hash of the token is stored.
        $token = Str::afterLast($link, '.');
        $this->assertSame(hash('sha256', $token), $user->invite_token_hash);
        $this->assertStringNotContainsString($token, json_encode(User::all()->map->getAttributes()));

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reviewers')->assertOk()->assertSee('Casey Morgan')->assertSee('Contest judge')->assertSee('Link ready')->assertDontSee($token);
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_platform_admin_can_invite(Role $role): void
    {
        $this->signIn($role);

        $this->get('/admin/reviewers')->assertForbidden();
        $this->post('/admin/reviewers', ['name' => 'X', 'email' => 'x@example.test', 'reviewer_type' => 'friend'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public static function otherRoles(): array
    {
        return collect(Role::cases())->reject(fn ($r) => $r === Role::PlatformAdmin)
            ->mapWithKeys(fn (Role $r) => [$r->value => [$r]])->all();
    }

    public function test_the_link_signs_in_straight_away_and_works_again_and_again(): void
    {
        $link = $this->invite();

        foreach (range(1, 3) as $visit) {
            $this->open($link)->assertRedirect('/judge');
            $this->assertAuthenticatedAs($this->reviewer());
            $this->get('/judge')->assertOk();
            $this->post('/logout');
            $this->assertGuest();
        }

        $user = $this->reviewer();
        $this->assertNotNull($user->first_login_at);
        $this->assertNotNull($user->last_seen_at);
        $this->assertNotNull($user->invite_token_hash, 'Using the link does not use it up.');
    }

    public function test_the_admin_can_open_reviewers_in_a_browser_that_used_a_judge_link(): void
    {
        $link = $this->invite();
        $this->open($link)->assertRedirect('/judge');

        // Same browser session, now the platform admin: the judge's session
        // fingerprint must not be mistaken for the one-time "new link" message.
        $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin/reviewers')->assertOk();
    }

    public function test_the_sign_in_page_never_asks_for_a_password_or_contains_the_token(): void
    {
        $link = $this->invite();

        $html = $this->get('/judge-access')->assertOk()->assertSee('Signing you in')->getContent();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString(Str::afterLast($link, '.'), $html);
    }

    public function test_the_link_ends_when_judging_ends_and_so_do_open_sessions(): void
    {
        $link = $this->invite();
        $this->open($link)->assertRedirect('/judge');

        $this->travelTo(Carbon::parse('2026-11-30 23:58', 'America/New_York'));
        $this->get('/judge')->assertOk();

        $this->travelTo(Carbon::parse('2026-12-01 00:01', 'America/New_York'));
        $this->get('/judge')->assertStatus(410)->assertSee('This link has ended');
        $this->assertGuest();
        $this->open($link)->assertStatus(410);
        $this->assertGuest();
    }

    public function test_the_end_date_comes_from_judging_ends_at(): void
    {
        $link = $this->invite();

        config(['reviewers.judging_ends_at' => '2026-10-20 07:00']); // 11:00 UTC, before "now"
        $this->open($link)->assertStatus(410);

        config(['reviewers.judging_ends_at' => '2026-12-31 23:59']);
        $this->open($link)->assertRedirect('/judge');

        config(['reviewers.judging_ends_at' => '2026-10-20 07:00']);
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reviewers')->assertSee('Judging has ended')->assertSee('Ended');
    }

    public function test_revoking_ends_the_link_and_open_sessions(): void
    {
        $link = $this->invite();
        $this->open($link);
        $user = $this->reviewer();

        $this->signIn(Role::PlatformAdmin);
        $this->post("/admin/reviewers/{$user->id}/revoke")->assertSessionHas('status');
        $this->assertNotNull($user->fresh()->revoked_at);
        $this->assertNull($user->fresh()->invite_token_hash);
        $this->get('/admin/reviewers')->assertSee('Revoked');

        // A session opened before the revoke ends on its next request...
        $this->be($user->fresh());
        $this->get('/judge')->assertStatus(410)->assertSee('This link has ended');
        $this->assertGuest();

        // ...and the link no longer signs in.
        $this->open($link)->assertStatus(410);
        $this->assertGuest();
    }

    public function test_a_new_link_kills_the_old_one_and_its_sessions(): void
    {
        $old = $this->invite();
        $this->open($old)->assertRedirect('/judge');
        $oldSession = session()->all();
        $user = $this->reviewer();

        $this->signIn(Role::PlatformAdmin);
        $this->post("/admin/reviewers/{$user->id}/link")->assertSessionHas('new_reviewer_link');
        $new = session('new_reviewer_link')['url'];
        $this->assertNotSame($old, $new);

        // The browser still holding the old link's session is signed out on its next click.
        $this->flushSession();
        $this->be($user->fresh())->withSession($oldSession);
        $this->get('/judge')->assertStatus(410);
        $this->assertGuest();

        $this->open($old)->assertStatus(410);
        $this->assertGuest();
        $this->open($new)->assertRedirect('/judge');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_judge_session_that_did_not_come_from_their_link_is_ended(): void
    {
        $this->invite();

        // For example a session from before magic links, or a remember-me cookie.
        $this->be($this->reviewer());
        $this->get('/judge')->assertStatus(410);
        $this->assertGuest();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // more tries than the rate limit allows
        $link = Str::after($this->invite(), '#');
        [$id, $token] = explode('.', $link);
        $otherJudge = User::factory()->role(Role::Judge)->create();
        app(ReviewerLinks::class)->newLink($otherJudge);

        foreach ([
            $id.'.'.Str::random(48),                  // wrong token
            $id.'.'.substr($token, 0, 47).($token[47] === 'x' ? 'y' : 'x'), // one character changed
            $otherJudge->id.'.'.$token,               // another reviewer's id
            $id.'.'.$token.'x',                       // too long
            $id.'.'.substr($token, 0, 20),            // too short
            '.'.$token,                               // no id
            $token,                                   // no id or dot
            '',                                       // nothing
            '999999.'.$token,                         // no such user
        ] as $tampered) {
            $this->post('/judge-access', ['link' => $tampered])->assertStatus(410)->assertSee('This link has ended');
            $this->assertGuest();
        }

        $this->post('/judge-access', ['link' => [$link]])->assertStatus(410);
        $this->post('/judge-access')->assertStatus(410);
        $this->assertGuest();
    }

    public static function nonJudgeRoles(): array
    {
        return collect(Role::cases())->reject(fn ($r) => $r === Role::Judge)
            ->mapWithKeys(fn (Role $r) => [$r->value => [$r]])->all();
    }

    #[DataProvider('nonJudgeRoles')]
    public function test_a_link_can_never_sign_in_a_non_judge(Role $role): void
    {
        $user = User::factory()->role($role)->create();

        // Even with a valid token hash planted on the account, it is refused.
        $token = Str::random(48);
        $user->forceFill(['invite_token_hash' => hash('sha256', $token)])->save();
        $this->post('/judge-access', ['link' => $user->id.'.'.$token])->assertStatus(410);
        $this->assertGuest();

        // A link can't be made for them, by code or from the admin page.
        $this->assertThrows(fn () => app(ReviewerLinks::class)->newLink($user), InvalidArgumentException::class);
        $this->signIn(Role::PlatformAdmin);
        $this->post("/admin/reviewers/{$user->id}/link")->assertNotFound();
    }

    public function test_reviewers_cannot_log_in_with_a_password(): void
    {
        $judge = User::factory()->role(Role::Judge)->create(['password' => 'a-long-test-password-123']);

        $this->post('/login', ['email' => $judge->email, 'password' => 'a-long-test-password-123']);
        $this->assertGuest();
    }

    public function test_the_link_route_is_rate_limited(): void
    {
        foreach (range(1, 10) as $_) {
            $this->post('/judge-access', ['link' => '1.'.Str::random(48)])->assertStatus(410);
        }

        $this->post('/judge-access', ['link' => '1.'.Str::random(48)])->assertStatus(429);
    }

    public function test_old_password_invite_links_show_the_ended_page(): void
    {
        $this->get('/invite/1/some-old-token?expires=1&signature=abc')->assertStatus(410)->assertSee('This link has ended');
    }

    public function test_a_browser_holding_an_old_session_can_open_the_new_link(): void
    {
        $old = $this->invite();
        $this->open($old);
        $oldSession = session()->all();

        $this->signIn(Role::PlatformAdmin);
        $this->post('/admin/reviewers/'.$this->reviewer()->id.'/link');
        $new = session('new_reviewer_link')['url'];

        // Same browser, still signed in with the old link's session.
        $this->flushSession();
        $this->be($this->reviewer())->withSession($oldSession);
        $this->open($new)->assertRedirect('/judge');
        $this->assertAuthenticatedAs($this->reviewer());
        $this->get('/judge')->assertOk();
    }

    public function test_only_reviewer_accounts_can_be_revoked(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $bidAdmin = User::factory()->role(Role::BidAdmin)->create();

        $this->post("/admin/reviewers/{$bidAdmin->id}/revoke")->assertNotFound();
        $this->assertNull($bidAdmin->fresh()->revoked_at);
    }

    public function test_visits_are_recorded(): void
    {
        $link = $this->invite();

        $this->open($link);
        $first = $this->reviewer();
        $this->assertNotNull($first->first_login_at);
        $this->assertNotNull($first->last_seen_at);
        $this->assertNull($first->previous_visit_at);

        $this->travel(2)->days();
        $this->get('/judge');
        $this->assertTrue($this->reviewer()->last_seen_at->isToday());

        $this->post('/logout');
        $this->travel(1)->days();
        $this->open($link);
        $again = $this->reviewer();
        $this->assertTrue($again->first_login_at->equalTo($first->first_login_at));
        $this->assertNotNull($again->previous_visit_at);
        $this->assertTrue($again->previous_visit_at->lt($again->last_seen_at));
    }

    public function test_the_admin_page_never_shows_a_password(): void
    {
        $this->invite();
        $this->signIn(Role::PlatformAdmin);
        $html = $this->get('/admin/reviewers')->getContent();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertDoesNotMatchRegularExpression('/(choose|set|chosen) (a|their|your own) password/i', strip_tags($html));
        $this->assertDoesNotMatchRegularExpression('/\$2y\$/', $html); // no password hash either
    }
}
