<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One case given to one adjudicator, in one of their batches of 10 (ADR 0020).
 * Its vote counts once the batch is submitted.
 */
class AdjudicationAssignment extends Model
{
    protected $fillable = ['adjudication_participant_id', 'adjudication_case_id', 'batch', 'position'];

    protected function casts(): array
    {
        return ['batch' => 'integer', 'position' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(AdjudicationParticipant::class, 'adjudication_participant_id');
    }

    public function adjudicationCase(): BelongsTo
    {
        return $this->belongsTo(AdjudicationCase::class);
    }
}
