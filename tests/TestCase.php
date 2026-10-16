<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ReviewerLinks;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Logs in as a user with $role, reusing one already created in this test
     * (so "who decided" stays the same person). A new employee login gets the
     * seeded demo wallet; the BID admin is Sam Taylor, as in the demo.
     */
    protected function signIn(Role $role): User
    {
        $user = User::where('role', $role)->first() ?? User::factory()->role($role)->create(match ($role) {
            Role::Employee => ['name' => 'Jordan Rivera'],
            Role::BidAdmin => ['name' => 'Sam Taylor'],
            default => [],
        });

        if ($role === Role::Employee && ! $user->employee()->exists()) {
            Employee::whereNull('user_id')->orderBy('id')->first()?->update(['user_id' => $user->id]);
        }

        if ($role === Role::Merchant && ! $user->merchant()->exists()) {
            Merchant::whereNull('user_id')->where('name', config('demo.accounts.merchant.business'))
                ->first()?->update(['user_id' => $user->id]);
        }

        $this->actingAs($user);

        return $user;
    }

    /**
     * Reviewers (judges) only ever sign in with their magic link, and their
     * session is ended unless it carries that link's fingerprint. Acting as a
     * judge in a test gives them a link and that fingerprint, like a real
     * sign-in. Use be() for a judge session without one.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && $user->isReadOnly()) {
            $links = app(ReviewerLinks::class);

            if ($user->invite_token_hash === null && ! $user->isRevoked()) {
                $links->newLink($user);
            }

            $this->withSession([ReviewerLinks::SESSION_KEY => $links->sessionFingerprint($user)]);
        }

        return parent::actingAs($user, $guard);
    }
}
