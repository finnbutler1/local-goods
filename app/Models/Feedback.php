<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackSeverity;
use App\Enums\FeedbackStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    protected $table = 'feedback';

    // status and fixed_commit_sha are set only by the platform admin, with forceFill.
    protected $fillable = ['page_url', 'category', 'rating', 'severity', 'comment'];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'severity' => FeedbackSeverity::class,
            'status' => FeedbackStatus::class,
            'rating' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The page link, only if it points into this app (it came from a form, so don't trust it). */
    public function safePageUrl(): ?string
    {
        return str_starts_with($this->page_url, url('/')) ? $this->page_url : null;
    }
}
