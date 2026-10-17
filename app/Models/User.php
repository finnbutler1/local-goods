<?php

namespace App\Models;

use App\Enums\ReviewerType;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// role is deliberately not fillable: only seeders and admins set it, with forceFill.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'invite_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'reviewer_type' => ReviewerType::class,
            'invited_at' => 'datetime',
            'first_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'previous_visit_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** "Sam Taylor" becomes "S.T.": staff are shown by initials in audit trails, like employees. */
    public static function initialsOf(string $name): string
    {
        return collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)).'.')
            ->implode('');
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Judges (every invited reviewer) can look at everything but change nothing. */
    public function isReadOnly(): bool
    {
        return $this->role === Role::Judge;
    }

    /** The one shared reviewer account behind the README's public judge link. */
    public function isPublicReviewer(): bool
    {
        return $this->isReadOnly() && $this->reviewer_type === ReviewerType::Public;
    }

    /**
     * Real merchant candidates' names (Merchant Eligibility Matching) are
     * shown only to BID and platform admins and to named reviewers. The
     * shared public reviewer account counts as public: its link is in the
     * README, so it sees totals only, like a logged-out visitor. So does a
     * reviewer with no type, since we can't say who they are.
     */
    public function maySeeCandidateNames(): bool
    {
        if ($this->hasRole(Role::BidAdmin, Role::PlatformAdmin)) {
            return true;
        }

        return $this->isReadOnly() && $this->reviewer_type !== null && $this->reviewer_type !== ReviewerType::Public;
    }

    /**
     * Blind adjudication of the fraud eval (ADR 0011): only named BID and
     * contest-judge reviewers vote. Not the shared public account, friends,
     * other reviewer types or staff (the person who labeled the cases).
     */
    public function canAdjudicate(): bool
    {
        return $this->isReadOnly()
            && ! $this->isRevoked()
            && in_array($this->reviewer_type, [ReviewerType::Bid, ReviewerType::ContestJudge], true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /** The wallet an employee login belongs to (only for the employee role). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** The business a merchant login runs (only for the merchant role). */
    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }
}
