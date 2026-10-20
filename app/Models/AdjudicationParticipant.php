<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A reviewer taking part in one adjudication set: their answer to "Have you
 * looked at the eval results page before today?", the batches of cases they
 * were given (ADR 0020), and when they finished: saw every case, said they
 * were done, or (before batches) pressed Submit all.
 */
class AdjudicationParticipant extends Model
{
    protected $fillable = ['user_id', 'seen_eval_before'];

    protected function casts(): array
    {
        return ['seen_eval_before' => 'boolean', 'submitted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(AdjudicationSet::class, 'adjudication_set_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AdjudicationAssignment::class);
    }

    /** Finished: no more batches for them. */
    public function hasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Pressed Submit all before batches existed (ADR 0011): voted on every
     * case, and every one of those votes counts.
     */
    public function submittedBeforeBatches(): bool
    {
        return $this->hasSubmitted() && ! $this->assignments()->exists();
    }

    /**
     * The batch they are working on (not yet submitted), in their order.
     *
     * @return Collection<int, AdjudicationAssignment>
     */
    public function openBatch(): Collection
    {
        return $this->assignments()->whereNull('submitted_at')->orderBy('batch')->orderBy('position')->with('adjudicationCase')->get();
    }
}
