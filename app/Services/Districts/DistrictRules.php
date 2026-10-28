<?php

namespace App\Services\Districts;

use App\Models\District;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The district rules (ADR 0031), in one place:
 *
 * 1. A merchant is approved for a district only if its location is inside
 *    the district's boundary (approveMerchant, and Merchant's saving hook
 *    for any other write).
 * 2. Credits from an employer's district program are spent only at
 *    merchants approved and live in that district (ensureRedeemable, called
 *    first thing in RedemptionService::redeem and before a pay code is
 *    issued or claimed).
 * 3. One active exclusive BID partnership per district lives in the
 *    database (partnerships.exclusive_active_key is unique), see
 *    PartnershipSettings.
 *
 * The public address checker uses ensureInside too (DistrictCheck), so the
 * map tells people exactly what enforcement would decide.
 */
class DistrictRules
{
    public const OUTSIDE = 'That location is outside the :district boundary, so it can\'t be approved for this district.';

    public const NO_LOCATION = 'This merchant has no location yet, so it can\'t be approved for a district.';

    public const NOT_REDEEMABLE = 'That shop isn\'t an approved Local Goods merchant in the :district, so your credits can\'t be spent there. Nothing was charged.';

    /** Is this point inside the district? Missing or impossible coordinates are never inside. */
    public static function isInside(District $district, mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        return $district->boundary()->contains((float) $latitude, (float) $longitude);
    }

    /** @throws InvalidArgumentException when the point is missing or outside */
    public static function ensureInside(District $district, mixed $latitude, mixed $longitude): void
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new InvalidArgumentException(self::NO_LOCATION);
        }

        if (! self::isInside($district, $latitude, $longitude)) {
            throw new InvalidArgumentException(str_replace(':district', $district->name, self::OUTSIDE));
        }
    }

    /**
     * Approve a merchant for a district, only when its location is inside.
     * Live (able to take credits) is separate: it follows Stripe onboarding.
     */
    public static function approveMerchant(Merchant $merchant, District $district, User $by): void
    {
        self::ensureInside($district, $merchant->latitude, $merchant->longitude);

        $merchant->forceFill([
            'district_id' => $district->id,
            'district_approved_at' => now(),
            'district_approved_by_user_id' => $by->id,
        ])->save();
    }

    /**
     * Rule 1 on every save: an approved merchant's location must be inside
     * its district. Called by Merchant's saving hook.
     */
    public static function guardSave(Merchant $merchant): void
    {
        if ($merchant->district_approved_at === null || $merchant->district_id === null) {
            return;
        }

        self::ensureInside(District::findOrFail($merchant->district_id), $merchant->latitude, $merchant->longitude);
    }

    /** The district whose rules apply to this employee's credits, or null when the employer runs no district program. */
    public static function programDistrictId(Employee $employee): ?int
    {
        $id = $employee->employer()->value('district_id');

        return $id === null ? null : (int) $id;
    }

    /** May this employee's credits be spent at this merchant? */
    public static function mayRedeem(Employee $employee, Merchant $merchant): bool
    {
        $districtId = self::programDistrictId($employee);

        return $districtId === null || (
            (int) $merchant->district_id === $districtId
            && $merchant->district_approved_at !== null
            && $merchant->live_at !== null
        );
    }

    /**
     * Rule 2. Refuses before anything is scored or written.
     *
     * @throws ValidationException on $field
     */
    public static function ensureRedeemable(Employee $employee, Merchant $merchant, string $field = 'merchant_id'): void
    {
        if (! self::mayRedeem($employee, $merchant)) {
            $name = District::find(self::programDistrictId($employee))?->name ?? 'district';

            throw ValidationException::withMessages([$field => str_replace(':district', $name, self::NOT_REDEEMABLE)]);
        }
    }
}
