<?php

namespace App\Services\Solvency;

/**
 * The result of replaying the whole ledger (ADR 0033): how many lines and
 * moments were checked, over what dates, the position at the end, and
 * every window of time in which the rule failed (from, to, the worst
 * moment and the accounts below zero then). A window with no "to" is
 * still open at the end of the history.
 */
final readonly class SolvencyHistory
{
    /**
     * @param  list<array{from: string, to: ?string, problem: ?string, worst_cents: int, worst_at: string, accounts: list<string>}>  $windows
     * @param  list<string>  $mismatches  from SolvencyCheck::recordsAgree()
     */
    public function __construct(
        public int $entries,
        public int $moments,
        public ?string $firstEntryAt,
        public ?string $lastEntryAt,
        public SolvencyPosition $final,
        public array $windows,
        public int $failedMoments,
        public array $mismatches,
        public int $durationMs,
    ) {}

    /** Solvent and balanced at every moment, Stripe floor held, records agree. */
    public function passed(): bool
    {
        return $this->windows === [] && $this->mismatches === [];
    }

    public function firstFailure(): ?array
    {
        return $this->windows[0] ?? null;
    }

    /** One plain sentence: what was checked and what was found. */
    public function summary(): string
    {
        $checked = number_format($this->entries).' ledger '.($this->entries === 1 ? 'entry' : 'entries').' at '.number_format($this->moments).' '.($this->moments === 1 ? 'moment' : 'moments');

        if ($this->passed()) {
            return 'Pass: '.$checked.', every moment solvent and every cent accounted for.';
        }

        $first = $this->firstFailure();

        return 'Fail: '.$checked.'. '.($first !== null
            ? 'First failed at '.$first['from'].' UTC: '.$first['problem'].'. '.count($this->windows).' '.(count($this->windows) === 1 ? 'window' : 'windows').', '.$this->failedMoments.' failing '.($this->failedMoments === 1 ? 'moment' : 'moments').'.'
            : '').implode(' ', $this->mismatches);
    }
}
