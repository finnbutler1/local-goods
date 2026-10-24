<?php

namespace App\Services\Corrections;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * Someone asks for a money mistake to be fixed (ADR 0028). Asking moves no
 * money: it writes one requested correction for a platform admin to decide.
 * Only the person's own items: an employee's own cleared redemption, a
 * merchant's own redemption or Stripe payout, the employer's own unspent
 * credits. The unique open_key lets the database allow only one open
 * request per item, even with two tabs pressing at once.
 */
class CorrectionRequests
{
    public const ALREADY_OPEN = 'There\'s already an open request for this. Its status shows here.';

    public function forRedemption(User $by, Redemption $redemption, string $reason, ?string $note): Correction
    {
        $owns = match ($by->role) {
            Role::Employee => $by->employee?->id === $redemption->employee_id,
            Role::Merchant => $by->merchant?->id === $redemption->merchant_id,
            default => false,
        };
        if (! $owns) {
            throw new LogicException('Only the employee or the merchant of a redemption may ask for it to be corrected.');
        }

        if (! $redemption->isApproved()) {
            throw new InvalidArgumentException('Only a cleared redemption can be refunded. A held one is reviewed instead.');
        }

        if (CorrectionLimits::redemptionLeftCents($redemption) <= 0) {
            throw new InvalidArgumentException('This redemption has already been refunded in full.');
        }

        return $this->open($by, Correction::TYPE_REDEMPTION_REFUND, $redemption->id, $reason, $note, [
            'redemption_id' => $redemption->id,
            'merchant_id' => $redemption->merchant_id,
            'employee_id' => $redemption->employee_id,
        ]);
    }

    public function forPayout(User $by, MerchantPayout $payout, string $reason, ?string $note): Correction
    {
        if ($by->role !== Role::Merchant || $by->merchant?->id !== $payout->merchant_id) {
            throw new LogicException('Only the merchant a payout belongs to may ask about it.');
        }

        if (! $payout->isStripe()) {
            throw new InvalidArgumentException('Simulated payouts move no real money, so there is nothing to send.');
        }

        return $this->open($by, Correction::TYPE_MERCHANT_ADJUSTMENT, $payout->id, $reason, $note, [
            'merchant_payout_id' => $payout->id,
            'merchant_id' => $payout->merchant_id,
            'redemption_id' => $payout->redemption_id,
        ]);
    }

    public function forEmployer(User $by, Employer $employer, string $reason, ?string $note): Correction
    {
        if ($by->role !== Role::EmployerAdmin) {
            throw new LogicException('Only the employer\'s HR admin may ask for unspent credits back.');
        }

        if ($employer->balanceCents() <= 0) {
            throw new InvalidArgumentException('There are no unspent credits to refund.');
        }

        return $this->open($by, Correction::TYPE_EMPLOYER_REFUND, $employer->id, $reason, $note, ['employer_id' => $employer->id]);
    }

    private function open(User $by, string $type, int $itemId, string $reason, ?string $note, array $about): Correction
    {
        try {
            return Correction::create($about + [
                'type' => $type,
                'status' => Correction::STATUS_REQUESTED,
                'open_key' => Correction::openKeyFor($type, $itemId),
                'requested_by_user_id' => $by->id,
                'requested_by_role' => $by->role->value,
                'reason_category' => $reason,
                'note' => ($note = trim((string) $note)) === '' ? null : $note,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException(self::ALREADY_OPEN);
        }
    }
}
