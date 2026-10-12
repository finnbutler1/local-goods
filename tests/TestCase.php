<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
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

        $this->actingAs($user);

        return $user;
    }
}
