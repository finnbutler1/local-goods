<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekday the demo activity generator ran (ADR 0032): the plan drawn
 * for that date (the same date always draws the same plan), why it stopped
 * early if it did, and the last reconciliation it saw.
 */
class DemoActivityDay extends Model
{
    protected $fillable = ['date', 'seed', 'plan', 'halted_reason', 'reconciliation_misses', 'last_reconciliation'];

    protected function casts(): array
    {
        return ['date' => 'date', 'plan' => 'array', 'last_reconciliation' => 'array'];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DemoActivityAction::class, 'day_id')->orderBy('id');
    }

    public function isHalted(): bool
    {
        return $this->halted_reason !== null;
    }
}
