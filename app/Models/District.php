<?php

namespace App\Models;

use App\Services\Eligibility\BidBoundary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Business Improvement District and its official boundary (ADR 0031).
 * The MVP has one: the Golden Triangle BID. The boundary is DC's published
 * polygon, stored with its source and dates; nothing fetches it at runtime.
 */
class District extends Model
{
    protected $fillable = [
        'name', 'slug', 'boundary_geojson', 'boundary_sha256', 'description',
        'source_name', 'source_url', 'source_edited_on', 'retrieved_on',
    ];

    protected function casts(): array
    {
        return [
            'source_edited_on' => 'date',
            'retrieved_on' => 'date',
        ];
    }

    private ?BidBoundary $boundary = null;

    /** The one district of the MVP. */
    public static function default(): self
    {
        return self::where('slug', config('districts.default_slug'))->firstOrFail();
    }

    /** Point-in-polygon over the stored boundary (the same code that built the candidate list). */
    public function boundary(): BidBoundary
    {
        return $this->boundary ??= BidBoundary::fromGeoJson($this->boundary_geojson);
    }

    /**
     * The outer rings as [longitude, latitude] points, for drawing the map.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function outerRings(): array
    {
        return $this->boundary()->outerRings();
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function partnerships(): HasMany
    {
        return $this->hasMany(Partnership::class);
    }
}
