<?php

namespace App\Support;

use App\Models\User;

/**
 * The four shared demo logins (config/demo.php) that judges may be given:
 * BID admin, employer, employee and merchant. Everyone holding one uses the
 * same account, so nothing one person does may lock the others out. The
 * platform admin (Finn's own login) and the read-only judge are not in here.
 */
class DemoLogins
{
    public const ROLES = ['bid_admin', 'employer_admin', 'employee', 'merchant'];

    public const PASSWORD_LOCKED = 'This demo login is shared with other judges, so its password can\'t be changed.';

    public static function isSharedLogin(?User $user): bool
    {
        return $user !== null && self::isSharedEmail((string) $user->email);
    }

    public static function isSharedEmail(string $email): bool
    {
        return in_array(strtolower(trim($email)), self::emails(), true);
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return collect(self::ROLES)
            ->map(fn (string $role) => strtolower(trim((string) config("demo.accounts.$role.email"))))
            ->filter()
            ->values()
            ->all();
    }
}
