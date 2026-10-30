<?php

namespace App\Models;

use App\Services\Solvency\SolvencyGuard;
use App\Services\Solvency\SolvencyHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One full check of the ledger history against the solvency rule
 * (ADR 0033), as shown on "Where the money is".
 */
class SolvencyCheckRun extends Model
{
    public const TRIGGER_COMMAND = 'command';

    public const TRIGGER_DEMO_ACTIVITY = 'demo_activity';

    public const TRIGGER_DEPLOY = 'deploy';

    public const TRIGGER_ADMIN = 'admin';

    /** A Stripe event recorded money that had already moved, and the check after it failed. */
    public const TRIGGER_STRIPE = 'stripe';

    public const TRIGGERS = [self::TRIGGER_COMMAND, self::TRIGGER_DEMO_ACTIVITY, self::TRIGGER_DEPLOY, self::TRIGGER_ADMIN, self::TRIGGER_STRIPE];

    protected $table = 'solvency_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'first_entry_at' => 'datetime',
            'last_entry_at' => 'datetime',
            'first_failure_at' => 'datetime',
            'failures' => 'array',
        ];
    }

    /** Stores the result; its end position becomes the guard's "already known" baseline. */
    public static function record(SolvencyHistory $history, string $trigger): self
    {
        SolvencyGuard::remember($history->final);
        $at = fn (?string $value) => $value === null ? null : Carbon::parse($value, 'UTC');

        return self::create([
            'trigger' => $trigger,
            'passed' => $history->passed(),
            'entries_checked' => $history->entries,
            'moments_checked' => $history->moments,
            'first_entry_at' => $at($history->firstEntryAt),
            'last_entry_at' => $at($history->lastEntryAt),
            'customer_funds_cents' => $history->final->customerFundsCents(),
            'operating_cents' => $history->final->operatingCents,
            'in_hand_cents' => $history->final->inHandCents(),
            'failed_moments' => $history->failedMoments,
            'first_failure_at' => $at($history->firstFailure()['from'] ?? null),
            'failures' => $history->passed() ? null : [
                'windows' => array_slice($history->windows, 0, 10),
                'window_count' => count($history->windows),
                'mismatches' => $history->mismatches,
            ],
            'duration_ms' => $history->durationMs,
        ]);
    }

    public static function latest(): ?self
    {
        return self::orderByDesc('id')->first();
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            self::TRIGGER_DEMO_ACTIVITY => 'the demo activity generator',
            self::TRIGGER_DEPLOY => 'a deploy',
            self::TRIGGER_ADMIN => 'the platform admin',
            self::TRIGGER_STRIPE => 'a Stripe event',
            default => 'the ledger:check-solvency command',
        };
    }
}
