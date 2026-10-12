<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangelogEntry extends Model
{
    protected $fillable = ['title', 'body', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
