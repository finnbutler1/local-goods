<?php

namespace App\Services;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Invites reviewers (judges, HR managers, merchants, BID staff, friends).
 * Each gets a judge-role login and a signed link, valid for 7 days, where
 * they choose their own password. Only a hash of the link's token is kept,
 * and it is cleared when the link is used, so a link works once. No password
 * is ever emailed or shown: the admin copies the link and sends it.
 */
class ReviewerInvites
{
    public const LINK_DAYS = 7;

    /** @return array{0: User, 1: string} The new reviewer and their invite link. */
    public function invite(string $name, string $email, ReviewerType $type): array
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => Str::lower($email),
            'role' => Role::Judge,
            'reviewer_type' => $type,
            'invited_at' => now(),
            // Nobody knows this password, so the account can't log in until the link is used.
            'password' => Str::random(64),
        ])->save();

        return [$user, $this->newLink($user)];
    }

    /** A fresh link. Any earlier link stops working, and a revoked reviewer gets access back. */
    public function newLink(User $user): string
    {
        $token = Str::random(48);

        $user->forceFill([
            'invite_token_hash' => hash('sha256', $token),
            'revoked_at' => null,
            'invited_at' => $user->invited_at ?? now(),
        ])->save();

        return URL::temporarySignedRoute('invite.show', now()->addDays(self::LINK_DAYS), [
            'user' => $user->id,
            'token' => $token,
        ]);
    }

    /** Whether $token is this reviewer's current, unused invite token. */
    public function tokenMatches(User $user, string $token): bool
    {
        return $user->invite_token_hash !== null
            && ! $user->isRevoked()
            && hash_equals($user->invite_token_hash, hash('sha256', $token));
    }

    /** Sets the reviewer's chosen password and uses up the link. */
    public function accept(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'invite_token_hash' => null])->save();
    }

    /** Ends the reviewer's access now: no login, no link, and any open session is closed. */
    public function revoke(User $user): void
    {
        $user->forceFill(['revoked_at' => now(), 'invite_token_hash' => null, 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
