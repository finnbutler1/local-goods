<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Services\ReviewerInvites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewerInviteTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-test-password-123';

    /** Invites a synthetic reviewer as the platform admin and returns the link shown once. */
    private function invite(string $email = 'casey.judge@example.test'): string
    {
        $this->signIn(Role::PlatformAdmin);

        $this->post('/admin/reviewers', ['name' => 'Casey Morgan', 'email' => $email, 'reviewer_type' => 'contest_judge'])
            ->assertRedirect('/admin/reviewers')
            ->assertSessionHas('invite_link');

        return session('invite_link')['url'];
    }

    private function accept(string $link): \Illuminate\Testing\TestResponse
    {
        auth()->logout();

        return $this->post($link, ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    public function test_the_platform_admin_creates_a_read_only_reviewer_login(): void
    {
        $link = $this->invite();

        $user = User::where('email', 'casey.judge@example.test')->firstOrFail();
        $this->assertSame(Role::Judge, $user->role);
        $this->assertSame(ReviewerType::ContestJudge, $user->reviewer_type);
        $this->assertNotNull($user->invited_at);
        $this->assertNull($user->first_login_at);

        // The token in the link is never stored, only its hash.
        $this->assertStringNotContainsString($user->invite_token_hash, $link);
        $this->assertStringContainsString('signature=', $link);

        $this->get('/admin/reviewers')->assertOk()->assertSee('Casey Morgan')->assertSee('Contest judge')->assertSee('Invited');
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

    public function test_the_link_sets_a_password_logs_in_and_works_only_once(): void
    {
        $link = $this->invite();
        auth()->logout();

        $this->get($link)->assertOk()->assertSee('Choose a password', false)->assertSee('casey.judge@example.test');

        $this->accept($link)->assertRedirect('/judge');
        $this->assertAuthenticatedAs($user = User::where('email', 'casey.judge@example.test')->first());
        $this->assertNotNull($user->first_login_at);
        $this->assertNull($user->invite_token_hash);

        // Used once: the same link is refused.
        $this->accept($link)->assertForbidden()->assertSee('expired or was already used');
        $this->get($link)->assertForbidden();

        // And the chosen password works on the normal login page.
        $this->post('/login', ['email' => 'casey.judge@example.test', 'password' => self::PASSWORD])->assertRedirect('/dashboard');
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->invite();
        auth()->logout();

        $this->travel(ReviewerInvites::LINK_DAYS)->days();
        $this->travel(1)->minutes();

        $this->get($link)->assertForbidden()->assertSee('Ask Finn for a new one');
        $this->accept($link)->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $link = $this->invite();
        auth()->logout();
        $user = User::where('email', 'casey.judge@example.test')->first();

        $this->get(preg_replace('#/invite/\d+/[^?]+#', "/invite/{$user->id}/wrong-token", $link))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
        $this->get(preg_replace('#/invite/\d+/#', '/invite/'.User::factory()->role(Role::Judge)->create()->id.'/', $link))->assertForbidden();
    }

    public function test_a_new_link_replaces_the_old_one(): void
    {
        $old = $this->invite();
        $user = User::where('email', 'casey.judge@example.test')->first();

        $this->post("/admin/reviewers/{$user->id}/link")->assertSessionHas('invite_link');
        $new = session('invite_link')['url'];

        auth()->logout();
        $this->get($old)->assertForbidden();
        $this->get($new)->assertOk();
    }

    public function test_revoking_ends_access_and_open_sessions(): void
    {
        $link = $this->invite();
        $this->accept($link);
        $user = User::where('email', 'casey.judge@example.test')->first();

        $this->signIn(Role::PlatformAdmin);
        $this->post("/admin/reviewers/{$user->id}/revoke")->assertSessionHas('status');
        $this->assertNotNull($user->fresh()->revoked_at);
        $this->get('/admin/reviewers')->assertSee('Revoked');

        // An open session is logged out on the next request...
        $this->actingAs($user->fresh());
        $this->get('/judge')->assertRedirect('/login');
        $this->assertGuest();

        // ...and the password no longer logs in.
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertGuest();
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
        $user = User::factory()->role(Role::Judge)->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $first = $user->fresh();
        $this->assertNotNull($first->first_login_at);
        $this->assertNotNull($first->last_seen_at);
        $this->assertNull($first->previous_visit_at);

        $this->travel(2)->days();
        $this->get('/judge');
        $this->assertTrue($user->fresh()->last_seen_at->isToday());

        $this->post('/logout');
        $this->travel(1)->days();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $again = $user->fresh();
        $this->assertTrue($again->first_login_at->equalTo($first->first_login_at));
        $this->assertNotNull($again->previous_visit_at);
        $this->assertTrue($again->previous_visit_at->lt($again->last_seen_at));
    }

    public function test_the_invite_screen_never_shows_a_password(): void
    {
        $this->invite();
        $html = $this->get('/admin/reviewers')->getContent();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertDoesNotMatchRegularExpression('/\$2y\$/', $html); // no password hash either
    }
}
