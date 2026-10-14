<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reviewer's blind call on one adjudication case: normal or suspicious, with a reason. */
class AdjudicationVote extends Model
{
    protected $fillable = ['user_id', 'label', 'reason'];

    public function adjudicationCase(): BelongsTo
    {
        return $this->belongsTo(AdjudicationCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
