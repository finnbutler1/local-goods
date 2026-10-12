<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates or updates one demo login per role from config/demo.php. Safe to run
 * on every deploy: accounts are matched by email, a changed password in the
 * environment replaces the old one, and nothing is ever deleted. Accounts with
 * a blank password are skipped.
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
            if (blank($account['password'])) {
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
