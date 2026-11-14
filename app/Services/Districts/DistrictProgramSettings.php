<?php

namespace App\Services\Districts;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Models\DistrictProgramChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only place a district's member-business count and program start date
 * change (ADR 0044), the shape of MerchantFeeSettings. Platform admin only,
 * always with a reason, and each change writes an append-only audit row
 * (who, when, old → new, why) in the same transaction. The BID never picks
 * its own tier: the count decides it.
 *
 * The start date is fixed once any program year has been paid, so paid
 * years keep their dates. The member count can still change; it moves the
 * tier for years not yet paid (paid years keep their own snapshot).
 */
class DistrictProgramSettings
{
    public const MAX_MEMBER_BUSINESSES = 1_000_000;

    public function change(User $by, District $district, int $memberBusinesses, string $startsOn, string $reason): DistrictProgramChange
    {
        if (! $by->hasRole(Role::PlatformAdmin) || $by->isReadOnly()) {
            throw new LogicException('Only the platform admin sets a district\'s member businesses and program start date.');
        }

        if ($memberBusinesses < 1 || $memberBusinesses > self::MAX_MEMBER_BUSINESSES) {
            throw new InvalidArgumentException('Member businesses must be a whole number from 1 to '.number_format(self::MAX_MEMBER_BUSINESSES).'.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, config('fraud.timezone'));
        if ($date === false || $date->format('Y-m-d') !== $startsOn) {
            throw new InvalidArgumentException('Give the program start date as a real date (YYYY-MM-DD).');
        }

        if (($reason = trim($reason)) === '') {
            throw new InvalidArgumentException('Write a reason for the change.');
        }

        return DB::transaction(function () use ($by, $district, $memberBusinesses, $startsOn, $reason) {
            $current = District::whereKey($district->id)->lockForUpdate()->firstOrFail();
            $previousStart = $current->program_starts_on?->format('Y-m-d');

            if ($current->member_businesses === $memberBusinesses && $previousStart === $startsOn) {
                throw new InvalidArgumentException('Those are already the district\'s details, so nothing was saved.');
            }

            if ($previousStart !== null && $previousStart !== $startsOn
                && BidProgramFeePayment::where('district_id', $current->id)->where('status', BidProgramFeePayment::STATUS_PAID)->exists()) {
                throw new InvalidArgumentException('A program year has already been paid, so the start date can\'t change: paid years keep their dates.');
            }

            $change = DistrictProgramChange::create([
                'district_id' => $current->id,
                'member_businesses' => $memberBusinesses,
                'previous_member_businesses' => $current->member_businesses,
                'program_starts_on' => $startsOn,
                'previous_program_starts_on' => $previousStart,
                'reason' => $reason,
                'changed_by_user_id' => $by->id,
            ]);

            $current->forceFill(['member_businesses' => $memberBusinesses, 'program_starts_on' => $startsOn])->save();
            $district->refresh();

            return $change;
        });
    }
}
