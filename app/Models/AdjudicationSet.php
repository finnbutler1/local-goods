<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * The adjudication was retired with no votes (ADR 0041). A set stored as
     * published then behaves as closed, without any change to its row.
     */
    public static function retired(): bool
    {
        return (bool) config('reviewers.adjudication_retired');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::PUBLISHED && ! self::retired();
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED || ($this->status === self::PUBLISHED && self::retired());
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

    /**
     * How many BID and contest-judge adjudicators have a vote that counts: a
     * submitted batch (ADR 0020), or Submit all from before batches.
     * Personal contacts are counted apart (ADR 0019).
     */
    public function submittedCount(): int
    {
        return $this->countingParticipants()->reject(fn ($p) => $p->user->isPersonalContact())->count();
    }

    /** How many personal contacts have a vote that counts. */
    public function submittedCountForPersonalContacts(): int
    {
        return $this->countingParticipants()->filter(fn ($p) => $p->user->isPersonalContact())->count();
    }

    /** @return Collection<int, AdjudicationParticipant> */
    private function countingParticipants()
    {
        return $this->participants()->with('user')
            ->where(fn ($q) => $q->whereHas('assignments', fn ($a) => $a->whereNotNull('submitted_at'))
                ->orWhere(fn ($q) => $q->whereNotNull('submitted_at')->whereDoesntHave('assignments')))
            ->get();
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
