<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\MerchantFeeChange;
use App\Models\User;
use App\Support\MerchantFee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only place the merchant fee setting changes (ADR 0029). Platform
 * admin only, within the cap, always with a reason, and only against the
 * setting the admin reviewed: if someone changed it in between, nothing is
 * written and they review again. Each change is a new audit row; nothing
 * already made changes, because every redemption keeps its own snapshot.
 */
class MerchantFeeSettings
{
    public function change(User $by, int $bps, int $fixedCents, string $reason, int $reviewedChangeId): MerchantFeeChange
    {
        if (! $by->hasRole(Role::PlatformAdmin)) {
            throw new LogicException('Only the platform admin changes the Local Goods fee.');
        }

        self::checkCap($bps, $fixedCents);

        if (($reason = trim($reason)) === '') {
            throw new InvalidArgumentException('Write a reason for the change.');
        }

        return DB::transaction(function () use ($by, $bps, $fixedCents, $reason, $reviewedChangeId) {
            $current = MerchantFee::current();

            if ($current->changeId !== $reviewedChangeId) {
                throw new InvalidArgumentException('The fee was changed since you reviewed this, so nothing was saved. Review the change again.');
            }

            if ($current->bps === $bps && $current->fixedCents === $fixedCents) {
                throw new InvalidArgumentException('That is already the fee, so nothing was saved.');
            }

            return MerchantFeeChange::create([
                'fee_bps' => $bps, 'fixed_cents' => $fixedCents,
                'previous_bps' => $current->bps, 'previous_fixed_cents' => $current->fixedCents,
                'reason' => $reason, 'changed_by_user_id' => $by->id,
            ]);
        });
    }

    public static function checkCap(int $bps, int $fixedCents): void
    {
        if ($bps < 0 || $bps > MerchantFee::MAX_BPS || $fixedCents < 0 || $fixedCents > MerchantFee::MAX_FIXED_CENTS) {
            throw new InvalidArgumentException('The fee can be at most '.MerchantFee::percent(MerchantFee::MAX_BPS).' plus $1.00 per sale.');
        }
    }
}
