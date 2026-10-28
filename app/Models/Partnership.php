<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A BID's partnership for a district (ADR 0031). Only PartnershipSettings
 * changes one, and every change is logged in partnership_changes.
 * exclusive_active_key is derived here on every save, so the unique index
 * allows one active exclusive partnership per district whatever writes it.
 */
class Partnership extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ENDED];

    /** The terms a change can set, in the order the change log shows them. */
    public const TERMS = ['status', 'exclusive', 'starts_on', 'ends_on', 'revenue_share_bps', 'data_terms'];

    public const MAX_REVENUE_SHARE_BPS = 5000;

    public const DEMO_LABEL = 'Demo partnership — not an actual agreement.';

    public const DEFAULT_DATA_TERMS = 'The BID can export its district\'s aggregate data at any time. Employee data stays private: '
        .'the BID never sees employee names, only counts and totals.';

    protected $fillable = [
        'bid_id', 'district_id', 'exclusive', 'starts_on', 'ends_on', 'revenue_share_bps', 'data_terms', 'status', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'exclusive' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'revenue_share_bps' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Partnership $partnership) {
            $partnership->exclusive_active_key = $partnership->exclusive && $partnership->status === self::STATUS_ACTIVE
                ? $partnership->district_id
                : null;
        });
    }

    /** The district's partnership to show: the active one, else the newest. */
    public static function forDistrict(District $district): ?self
    {
        return self::where('district_id', $district->id)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [self::STATUS_ACTIVE])
            ->latest('id')->first();
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(PartnershipChange::class)->latest('id');
    }

    /** The newest change id, the version a review is made against (0 when there is none). */
    public function latestChangeId(): int
    {
        return (int) PartnershipChange::where('partnership_id', $this->id)->max('id');
    }

    /** @return array<string, mixed> the terms as plain values, for the change log */
    public function terms(): array
    {
        return [
            'status' => $this->status,
            'exclusive' => (bool) $this->exclusive,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'revenue_share_bps' => (int) $this->revenue_share_bps,
            'data_terms' => $this->data_terms,
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_ENDED => 'Ended',
            default => 'Draft',
        };
    }

    /** "2.5%" for 250 basis points. */
    public static function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** Judges count as the public (ADR 0029): words, no figures. */
    public function revenueShareWords(): string
    {
        return $this->revenue_share_bps > 0 ? 'A revenue share is agreed' : 'No revenue share';
    }
}
