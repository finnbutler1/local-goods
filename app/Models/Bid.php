<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bid extends Model
{
    protected $fillable = ['name', 'city'];

    public function employers(): HasMany
    {
        return $this->hasMany(Employer::class);
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }
}
