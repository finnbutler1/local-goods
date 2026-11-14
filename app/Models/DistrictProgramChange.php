<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a district's member-business count or program start date
 * (ADR 0044): who, when, old → new and why. Append-only, like
 * MerchantFeeChange. Only DistrictProgramSettings::change writes one.
 */
class DistrictProgramChange extends Model
{
    protected $fillable = [
        'district_id', 'member_businesses', 'previous_member_businesses',
        'program_starts_on', 'previous_program_starts_on', 'reason', 'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['program_starts_on' => 'date', 'previous_program_starts_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('District program changes are an audit log and never edited.'));
        static::deleting(fn () => throw new LogicException('District program changes are an audit log and never deleted.'));
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
