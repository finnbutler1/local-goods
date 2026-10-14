<?php

namespace App\Services;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reviewers (judges, HR managers, merchants, BID staff, friends) sign in
 * with a personal magic link: no account setup, no password. Each gets a
 * judge-role login (read-only) and a link that works as often as they like
 * until judging ends (config/reviewers.php), they are revoked, or the admin
 * makes a new link.
 *
 * The link looks like https://…/judge-access#<user id>.<token>. Everything
 * after # stays in the browser, so the token never shows up in server or
 * CloudFront logs; the page posts it in the form body. Only a hash of the
 * token is stored, and it is compared in constant time.
 */
class ReviewerLinks
{
    /** Session key holding a fingerprint of the link the session was opened with. */
    public const SESSION_KEY = 'reviewer_link';

    private const LINK_PATTERN = '/^(\d{1,10})\.([A-Za-z0-9]{48})$/';

    /** @return array{0: User, 1: string} The new reviewer and their magic link. */
    public function invite(string $name, string $email, ReviewerType $type): array
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => Str::lower($email),
            'role' => Role::Judge,
            'reviewer_type' => $type,
            'invited_at' => now(),
            // Nobody knows this password, and judges can't log in with one anyway.
            'password' => Str::random(64),
        ])->save();

        return [$user, $this->newLink($user)];
    }

    /**
     * A fresh magic link. The previous link, and any session opened with it,
     * stops working, and a revoked reviewer gets access back.
     */
    public function newLink(User $user): string
    {
        // A link may only ever open a read-only reviewer account.
        if ($user->role !== Role::Judge) {
            throw new InvalidArgumentException('Magic links are only for reviewer (judge) accounts.');
        }

        $token = Str::random(48);

        $user->forceFill([
            'invite_token_hash' => hash('sha256', $token),
            'revoked_at' => null,
            'invited_at' => $user->invited_at ?? now(),
        ])->save();

        return route('judge-access.show').'#'.$user->id.'.'.$token;
    }

    /** The reviewer this link (the part after #) signs in, or null if it doesn't work. */
    public function userFor(string $link): ?User
    {
        if ($this->hasEnded() || ! preg_match(self::LINK_PATTERN, $link, $parts)) {
            return null;
        }

        $user = User::find((int) $parts[1]);

        if (! $user
            || $user->role !== Role::Judge
            || $user->isRevoked()
            || $user->invite_token_hash === null) {
            return null;
        }

        return hash_equals($user->invite_token_hash, hash('sha256', $parts[2])) ? $user : null;
    }

    /** Stored in the session at sign-in, so a new link ends sessions opened with the old one. */
    public function sessionFingerprint(User $user): ?string
    {
        return $user->invite_token_hash === null ? null : hash('sha256', 'session|'.$user->invite_token_hash);
    }

    /** Whether a reviewer's session (with this fingerprint) may go on. */
    public function sessionIsCurrent(User $user, mixed $fingerprint): bool
    {
        $expected = $this->sessionFingerprint($user);

        return ! $user->isRevoked()
            && ! $this->hasEnded()
            && $expected !== null
            && is_string($fingerprint)
            && hash_equals($expected, $fingerprint);
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('reviewers.judging_ends_at'), config('reviewers.timezone'));
    }

    public function hasEnded(): bool
    {
        return now()->greaterThan($this->endsAt());
    }

    /** Ends the reviewer's access now: no link, and any open session is closed on its next request. */
    public function revoke(User $user): void
    {
        $user->forceFill(['revoked_at' => now(), 'invite_token_hash' => null, 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
