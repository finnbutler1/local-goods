<?php

namespace App\Services\Solvency;

use App\Models\Merchant;
use App\Models\SolvencyCheckRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last line of defence behind the existing money guards (ADR 0033).
 * After every money operation we decide (allocating, redeeming, approving,
 * refunding, paying out) it checks the position, inside the operation's
 * transaction, and refuses (rolls back) if the operation left a bigger
 * shortfall than the one already known. Before a Stripe transfer it checks
 * the transfer would not. Money Stripe reports has already moved, so that
 * is recorded and never refused, only checked and flagged.
 *
 * It reads only at the end, never before the operation's own locks: on
 * MySQL a transaction's snapshot starts at its first plain read, and a read
 * before the locks would make the operation's own balance checks stale.
 * "Already known" is the shortfall the last full check found (zero when it
 * passed), so a problem already in the ledger is shown loudly on Where the
 * money is but doesn't stop the site; anything that makes it worse is
 * refused. A singleton: it counts nesting, so an operation inside another
 * is checked once, at the end of the outer one.
 */
class SolvencyGuard
{
    /** Cache key for the shortfalls the last full check found. */
    public const KNOWN_PROBLEM_KEY = 'solvency.known_problem';

    private int $depth = 0;

    private bool $paused = false;

    public function __construct(private SolvencyCheck $check) {}

    /**
     * Runs $write in a database transaction and checks the position at its
     * end, refusing (SolvencyRefused, nothing written) if it is worse.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public function transaction(string $what, callable $write): mixed
    {
        return DB::transaction(function () use ($what, $write) {
            $this->depth++;
            try {
                $result = $write();
            } finally {
                $this->depth--;
            }

            if ($this->depth === 0 && $this->active()) {
                $this->refuseIfWorse($what, $this->check->now());
            }

            return $result;
        });
    }

    /** Before real money leaves: would sending $cents to this merchant leave a bigger shortfall? */
    public function beforeTransfer(Merchant $merchant, int $cents): void
    {
        if (! $this->active()) {
            return;
        }

        $now = $this->check->now();
        // The transfer pays what the merchant is owed: in hand and its promise go down together,
        // except for any part above what it is owed, which is money going out with nothing promised.
        $uncovered = $cents - min($cents, max(0, $merchant->payableCents()));

        $shortfall = $now->shortfallCents() + $uncovered;
        $stripeShortfall = max(0, $now->stripeOutCents + $cents - $now->stripeInCents);
        $this->refuseIfWorse('a Stripe transfer of $'.number_format($cents / 100, 2).' to '.$merchant->name, $now, $shortfall, $stripeShortfall,
            $stripeShortfall > self::known()['stripe'] ? 'Stripe money out would be above Stripe money in by $'.number_format($stripeShortfall / 100, 2)
                : 'short by $'.number_format($shortfall / 100, 2));
    }

    /**
     * Money Stripe reports has already moved, so it is recorded whatever
     * this finds. If the rule fails afterwards, a full check is stored (it
     * shows on Where the money is) and becomes the known problem. Never
     * throws.
     */
    public function afterRecorded(string $what): void
    {
        if (! $this->active()) {
            return;
        }

        try {
            if (($now = $this->check->now())->passes()) {
                return;
            }
            Log::warning('Solvency check failed after recording money Stripe moved', ['what' => $what, 'problem' => $now->problem()]);
            SolvencyCheckRun::record($this->check->history(), SolvencyCheckRun::TRIGGER_STRIPE);
        } catch (Throwable $e) {
            Log::warning('Solvency check after a Stripe event could not run', ['what' => $what, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Runs $seed with the guard off, for the demo seeder only, which writes
     * history out of time order and is checked as a whole afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $seed
     * @return T
     */
    public function paused(callable $seed): mixed
    {
        $was = $this->paused;
        $this->paused = true;
        try {
            return $seed();
        } finally {
            $this->paused = $was;
        }
    }

    /** The shortfalls a full check found at the end of the history, as the baseline "already known" (SolvencyCheckRun::record). */
    public static function remember(SolvencyPosition $position): void
    {
        Cache::forever(self::KNOWN_PROBLEM_KEY, ['shortfall' => $position->shortfallCents(), 'stripe' => $position->stripeShortfallCents()]);
    }

    /** @return array{shortfall: int, stripe: int} */
    public static function known(): array
    {
        $known = Cache::get(self::KNOWN_PROBLEM_KEY);

        return ['shortfall' => (int) ($known['shortfall'] ?? 0), 'stripe' => (int) ($known['stripe'] ?? 0)];
    }

    private function active(): bool
    {
        return ! $this->paused && config('solvency.guard_enabled');
    }

    private function refuseIfWorse(string $what, SolvencyPosition $after, ?int $shortfall = null, ?int $stripeShortfall = null, ?string $problem = null): void
    {
        $known = self::known();
        $shortfall ??= $after->shortfallCents();
        $stripeShortfall ??= $after->stripeShortfallCents();

        if ($shortfall > $known['shortfall'] || $stripeShortfall > $known['stripe']) {
            Log::warning('Solvency guard refused an operation', ['what' => $what, 'problem' => $problem ?? $after->problem(), 'known' => $known]);

            throw new SolvencyRefused($what, $after, $problem);
        }

        // Better than the known problem: that is the new baseline, so it can't creep back up.
        if ([$shortfall, $stripeShortfall] !== [$known['shortfall'], $known['stripe']]) {
            Cache::forever(self::KNOWN_PROBLEM_KEY, ['shortfall' => $shortfall, 'stripe' => $stripeShortfall]);
        }
    }
}
