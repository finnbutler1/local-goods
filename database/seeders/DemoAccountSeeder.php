<?php

namespace Database\Seeders;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates or updates one demo login per role from config/demo.php. Safe to run
 * on every deploy: accounts are matched by email, a changed password in the
 * environment replaces the old one, and nothing is ever deleted. Accounts with
 * a blank password are skipped. The judge account is the shared Public
 * judge access reviewer, which has no password at all (see seedPublicReviewer).
 */
class DemoAccountSeeder extends Seeder
{
    /**
     * @return array{saved: list<string>, skipped: list<string>}
     */
    public function run(): array
    {
        $saved = [];
        $skipped = [];

        foreach (config('demo.accounts') as $role => $account) {
            if ($role === Role::Judge->value) {
                self::seedPublicReviewer($account);
                $saved[] = $role;

                continue;
            }

            if (blank($account['password'] ?? null)) {
                $skipped[] = $role;

                continue;
            }

            $user = User::firstOrNew(['email' => $account['email']]);
            $user->forceFill(['name' => $account['name'], 'role' => Role::from($role)]);

            if (! $user->exists || ! Hash::check($account['password'], $user->password)) {
                $user->password = $account['password']; // hashed by the model cast
            }

            $user->save();
            $saved[] = $role;
        }

        self::linkDemoEmployee();

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * The one shared "Public judge access" reviewer, found by its type so
     * there is only ever one. Created if missing; an existing one keeps its
     * magic link (so the README link keeps working) and its revoked state.
     * Nobody knows its password, and reviewers can't log in with one anyway.
     */
    public static function seedPublicReviewer(array $account): void
    {
        $user = User::where('role', Role::Judge)->where('reviewer_type', ReviewerType::Public)->first()
            ?? User::firstOrNew(['email' => $account['email']]);

        if ($user->exists && $user->role !== Role::Judge) {
            return; // never turn someone else's login into the public reviewer
        }

        $user->forceFill([
            'name' => $account['name'],
            'role' => Role::Judge,
            'reviewer_type' => ReviewerType::Public,
            'invited_at' => $user->invited_at ?? now(),
        ]);
        $user->password ??= Str::random(64);
        $user->save();
    }

    /**
     * Gives the demo employee's login the seeded wallet (one employee in the MVP).
     * Runs here and after the demo data is seeded, so either order works.
     */
    public static function linkDemoEmployee(): void
    {
        $user = User::where('email', config('demo.accounts.employee.email'))->first();

        if ($user && ! $user->employee()->exists()) {
            Employee::whereNull('user_id')->orderBy('id')->first()?->update(['user_id' => $user->id]);
        }
    }
}
