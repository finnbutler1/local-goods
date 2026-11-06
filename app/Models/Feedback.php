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

    /** The page link, only if it points into this app, at its address now or a previous one (it came from a form, so don't trust it). */
    public function safePageUrl(): ?string
    {
        foreach ([url('/'), ...config('app.previous_urls', [])] as $root) {
            $root = rtrim($root, '/');
            // The root, then nothing or a path, query or fragment: not https://uselocalgoods.com.example.org.
            if (str_starts_with($this->page_url, $root) && in_array(substr($this->page_url, strlen($root), 1), ['', '/', '?', '#'], true)) {
                return $this->page_url;
            }
        }

        return null;
    }
}
