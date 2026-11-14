<?php

namespace App\Services\Sandbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How many Claude calls sandboxes may make (ADR 0045): a cap per sandbox and
 * a cap per day across all of them. The per-sandbox count lives in the
 * sandbox's own cache (inside its file); the daily count is a small locked
 * file in the sandbox folder. Neither touches the main database.
 */
class SandboxAiBudget
{
    public const SANDBOX_KEY = 'sandbox.ai_calls';

    public function __construct(private SandboxFiles $files) {}

    /** Takes one call from both budgets. False means: don't call the AI. */
    public function take(): bool
    {
        // The database store won't increment a missing key, so make it first. Outlives the sandbox (24 hours).
        Cache::add(self::SANDBOX_KEY, 0, now()->addDays(2));
        $used = (int) Cache::increment(self::SANDBOX_KEY);
        if ($used > (int) config('sandbox.ai_calls_per_sandbox')) {
            return false;
        }

        return $this->takeFromToday();
    }

    public function usedInThisSandbox(): int
    {
        return (int) Cache::get(self::SANDBOX_KEY, 0);
    }

    public function usedToday(): int
    {
        $count = @file_get_contents($this->dayFile());

        return $count === false ? 0 : (int) $count;
    }

    private function dayFile(): string
    {
        return $this->files->dir().'/ai-calls-'.Carbon::now('America/New_York')->toDateString().'.count';
    }

    private function takeFromToday(): bool
    {
        $handle = @fopen($this->dayFile(), 'c+');
        if ($handle === false) {
            return false; // can't count, so don't spend
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }
            $used = (int) stream_get_contents($handle);
            if ($used >= (int) config('sandbox.ai_calls_per_day')) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($used + 1));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
