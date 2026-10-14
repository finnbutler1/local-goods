<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Demo logins we no longer use. demo:seed-accounts never creates them again,
 * and cleanUp() (run once by a migration) removes them: deleted for good if
 * nothing points at them, otherwise left in place but revoked, so no record
 * loses its author.
 */
class RetiredDemoAccounts
{
    /** The old demo judge, replaced by Public judge access (ADR 0010). */
    public const EMAILS = ['casey.brooks@example.com'];

    public static function isRetired(string $email): bool
    {
        return in_array(strtolower($email), self::EMAILS, true);
    }

    /** @return array{deleted: int, revoked: int} */
    public static function cleanUp(): array
    {
        $result = ['deleted' => 0, 'revoked' => 0];

        // Only judge accounts: never touch a login someone reused the address for.
        foreach (User::whereIn('email', self::EMAILS)->where('role', Role::Judge)->get() as $user) {
            if (self::hasRecords($user)) {
                $user->forceFill(['revoked_at' => $user->revoked_at ?? now(), 'invite_token_hash' => null])->save();
                $result['revoked']++;
            } else {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                $user->delete();
                $result['deleted']++;
            }
        }

        return $result;
    }

    /** Anything that points at this account, by id or by name. */
    public static function hasRecords(User $user): bool
    {
        return DB::table('feedback')->where('user_id', $user->id)->exists()
            || DB::table('employees')->where('user_id', $user->id)->exists()
            || DB::table('redemptions')->where('reviewed_by_user_id', $user->id)->exists()
            || DB::table('redemptions')->where('reviewed_by', $user->name)->exists()
            || DB::table('credit_ledger_entries')->where('decided_by', $user->name)->exists();
    }
}
