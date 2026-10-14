<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One eval case in an adjudication set, with a frozen copy of the facts
 * adjudicators see. "disputed": the person's label and the AI disagreed;
 * "control": they agreed, mixed in so adjudicators can't tell which is which.
 * Locked once its set is published.
 */
class AdjudicationCase extends Model
{
    public const DISPUTED = 'disputed';
    public const CONTROL = 'control';

    /** The facts adjudicators see, in order, with their labels. Nothing else is shown. */
    public const FACTS = [
        'merchant' => 'Merchant',
        'category' => 'Category',
        'amount' => 'Amount',
        'local_time' => 'Local time',
        'balance_before' => 'Balance before',
        'visits_7d' => 'Visits here in the last 7 days',
        'visits_30d' => 'Visits here in the last 30 days',
        'past_redemptions' => 'Past redemptions',
        'median_past_amount' => 'Median past amount',
        'recent_history' => 'History',
    ];

    protected $fillable = ['case_id', 'kind', 'split', 'my_label', 'ai_label', 'tags', 'facts'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'facts' => 'array'];
    }

    protected static function booted(): void
    {
        $guard = function (AdjudicationCase $case) {
            if (! $case->set->isDraft()) {
                throw new LogicException('This adjudication set is published and locked. Start a new version to change it.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(AdjudicationSet::class, 'adjudication_set_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AdjudicationVote::class);
    }

    public function isDisputed(): bool
    {
        return $this->kind === self::DISPUTED;
    }
}
