<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Merchant extends Model
{
    protected $fillable = ['bid_id', 'name', 'category', 'address'];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}
