<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employer extends Model
{
    protected $fillable = ['bid_id', 'name', 'monthly_allowance_cents'];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
