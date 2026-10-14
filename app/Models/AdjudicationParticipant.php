<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer taking part in one adjudication set: their answer to "Have you
 * looked at the eval results page before today?" and when they pressed Submit all.
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

    public function hasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
