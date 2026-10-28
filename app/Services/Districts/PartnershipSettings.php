<?php

namespace App\Services\Districts;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\District;
use App\Models\Partnership;
use App\Models\PartnershipChange;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only place a BID partnership is created or changed (ADR 0031).
 * Platform admin only, always with a reason, only against the version the
 * admin reviewed, and each change is one append-only partnership_changes
 * row. Status only moves forward (draft → active → ended). One active
 * exclusive partnership per district: checked here for a clear message and
 * enforced by the unique exclusive_active_key in the database.
 */
class PartnershipSettings
{
    public const ALREADY_EXCLUSIVE = 'This district already has an active exclusive partnership, so nothing was saved. End that one first.';

    /** Status => the statuses it may move to (itself included, for other edits). */
    private const MOVES = [
        Partnership::STATUS_DRAFT => [Partnership::STATUS_DRAFT, Partnership::STATUS_ACTIVE, Partnership::STATUS_ENDED],
        Partnership::STATUS_ACTIVE => [Partnership::STATUS_ACTIVE, Partnership::STATUS_ENDED],
        Partnership::STATUS_ENDED => [Partnership::STATUS_ENDED],
    ];

    /**
     * A new partnership and its first log row. $by is null only for the demo
     * seed, which says so in the reason.
     *
     * @param  array<string, mixed>  $terms
     */
    public function create(?User $by, Bid $bid, District $district, array $terms, string $reason, bool $isDemo = false): Partnership
    {
        if ($by !== null) {
            $this->ensurePlatformAdmin($by);
        }

        $terms = $this->check($terms, Partnership::STATUS_DRAFT, $reason);

        return DB::transaction(function () use ($by, $bid, $district, $terms, $reason, $isDemo) {
            $this->ensureNoOtherExclusive($district->id, $terms, null);
            $partnership = $this->save(fn () => Partnership::create([
                'bid_id' => $bid->id, 'district_id' => $district->id, 'is_demo' => $isDemo, ...$terms,
            ]));

            PartnershipChange::create([
                'partnership_id' => $partnership->id, 'changed_by_user_id' => $by?->id,
                'reason' => trim($reason), 'before' => null, 'after' => $partnership->terms(),
            ]);

            return $partnership;
        });
    }

    /** @param  array<string, mixed>  $terms */
    public function change(User $by, Partnership $partnership, array $terms, string $reason, int $reviewedChangeId): PartnershipChange
    {
        $this->ensurePlatformAdmin($by);
        $terms = $this->check($terms, $partnership->status, $reason);

        return DB::transaction(function () use ($by, $partnership, $terms, $reason, $reviewedChangeId) {
            $partnership = Partnership::whereKey($partnership->id)->lockForUpdate()->firstOrFail();

            if ($partnership->latestChangeId() !== $reviewedChangeId) {
                throw new InvalidArgumentException('The partnership was changed since you reviewed this, so nothing was saved. Review the change again.');
            }

            $before = $partnership->terms();
            $partnership->fill($terms);

            if ($partnership->terms() === $before) {
                throw new InvalidArgumentException('Those are already the terms, so nothing was saved.');
            }

            $this->ensureNoOtherExclusive($partnership->district_id, $terms, $partnership->id);
            $this->save(fn () => $partnership->save());

            return PartnershipChange::create([
                'partnership_id' => $partnership->id, 'changed_by_user_id' => $by->id,
                'reason' => trim($reason), 'before' => $before, 'after' => $partnership->terms(),
            ]);
        });
    }

    /**
     * Validates and normalises the terms; refuses a status moving backwards.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    public function check(array $terms, string $fromStatus, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Write a reason for the change.');
        }

        $status = (string) ($terms['status'] ?? '');
        if (! in_array($status, Partnership::STATUSES, true)) {
            throw new InvalidArgumentException('Choose draft, active or ended.');
        }
        if (! in_array($status, self::MOVES[$fromStatus] ?? [], true)) {
            throw new InvalidArgumentException("A partnership can't go from {$fromStatus} back to {$status}. Start a new one instead.");
        }

        $bps = (int) ($terms['revenue_share_bps'] ?? -1);
        if ($bps < 0 || $bps > Partnership::MAX_REVENUE_SHARE_BPS) {
            throw new InvalidArgumentException('The revenue share is from 0% to '.Partnership::percent(Partnership::MAX_REVENUE_SHARE_BPS).'.');
        }

        $startsOn = Carbon::parse((string) ($terms['starts_on'] ?? ''))->toDateString();
        $endsOn = ($terms['ends_on'] ?? null) ? Carbon::parse((string) $terms['ends_on'])->toDateString() : null;
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        if (trim((string) ($terms['data_terms'] ?? '')) === '') {
            throw new InvalidArgumentException('Write the data terms.');
        }

        return [
            'status' => $status,
            'exclusive' => (bool) ($terms['exclusive'] ?? false),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'revenue_share_bps' => $bps,
            'data_terms' => trim((string) $terms['data_terms']),
        ];
    }

    private function ensurePlatformAdmin(User $by): void
    {
        if (! $by->hasRole(Role::PlatformAdmin)) {
            throw new LogicException('Only the platform admin changes a BID partnership.');
        }
    }

    /** @param  array<string, mixed>  $terms */
    private function ensureNoOtherExclusive(int $districtId, array $terms, ?int $exceptId): void
    {
        if (! $terms['exclusive'] || $terms['status'] !== Partnership::STATUS_ACTIVE) {
            return;
        }

        $taken = Partnership::where('exclusive_active_key', $districtId)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw new InvalidArgumentException(self::ALREADY_EXCLUSIVE);
        }
    }

    /** The unique index is the last word: a race that slips past the check above still can't make two. */
    private function save(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException(self::ALREADY_EXCLUSIVE);
        }
    }
}
