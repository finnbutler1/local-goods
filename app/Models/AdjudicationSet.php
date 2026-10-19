<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One version of the blind adjudication set (ADR 0011). A draft can be edited
 * by the platform admin; once published its cases are locked, and changing
 * them needs a new version. Closing it ends voting and shows every vote.
 */
class AdjudicationSet extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const CLOSED = 'closed';

    protected $fillable = ['version', 'status', 'source_run', 'prompt_version', 'published_at', 'closed_at'];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** The set reviewers vote on (or the last one they voted on): the newest published or closed version. */
    public static function current(): ?self
    {
        return self::where('status', '!=', self::DRAFT)->orderByDesc('version')->first();
    }

    /** The set being prepared, if there is one. */
    public static function draft(): ?self
    {
        return self::where('status', self::DRAFT)->orderByDesc('version')->first();
    }

    /**
     * True while voting is open and this user may vote but hasn't pressed
     * Submit all: the eval pages then hide the per-case disagreements, which
     * would show them the person's label and the AI's call.
     */
    public static function hidesCaseDetailsFor(?User $user): bool
    {
        if ($user === null || ! $user->canAdjudicate()) {
            return false;
        }

        $set = self::current();

        return $set !== null && $set->isOpen() && ! $set->hasSubmitted($user);
    }

    /** The voting deadline shown to reviewers, in Washington DC time (config/reviewers.php). */
    public static function votingEndsAt(): Carbon
    {
        return Carbon::parse(config('reviewers.adjudication_ends_at'), config('reviewers.timezone'));
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AdjudicationCase::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AdjudicationParticipant::class);
    }

    public function participantFor(User $user): ?AdjudicationParticipant
    {
        return $this->participants()->where('user_id', $user->id)->first();
    }

    public function hasSubmitted(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->whereNotNull('submitted_at')->exists();
    }

    /** How many BID and contest-judge adjudicators have pressed Submit all (personal contacts are counted apart, ADR 0019). */
    public function submittedCount(): int
    {
        return $this->submittedParticipants()->reject(fn ($p) => $p->user->isPersonalContact())->count();
    }

    /** How many personal contacts have pressed Submit all. */
    public function submittedCountForPersonalContacts(): int
    {
        return $this->submittedParticipants()->filter(fn ($p) => $p->user->isPersonalContact())->count();
    }

    /** @return Collection<int, AdjudicationParticipant> */
    private function submittedParticipants()
    {
        return $this->participants()->whereNotNull('submitted_at')->with('user')->get();
    }

    /**
     * This set's cases in a random-looking order that is different for each
     * reviewer and stays the same for one reviewer across visits.
     *
     * @return Collection<int, AdjudicationCase>
     */
    public function casesInOrderFor(User $user)
    {
        return $this->cases()->get()
            ->sortBy(fn (AdjudicationCase $c) => hash('sha256', "{$this->id}|{$user->id}|{$c->case_id}"))
            ->values();
    }
}
