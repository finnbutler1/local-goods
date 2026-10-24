<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Correction;

/**
 * The reason categories a person picks when asking for a correction
 * (ADR 0028), by what they are asking about. Stored as the key; the label
 * is shown to the requester and the admin.
 */
final class CorrectionReasons
{
    public const EMPLOYEE_REDEMPTION = 'employee_redemption';

    public const MERCHANT_REDEMPTION = 'merchant_redemption';

    public const MERCHANT_PAYOUT = 'merchant_payout';

    public const EMPLOYER_CREDITS = 'employer_credits';

    private const REASONS = [
        self::EMPLOYEE_REDEMPTION => [
            'wrong_amount' => 'I was charged the wrong amount',
            'not_received' => 'I didn\'t get what I paid for',
            'charged_twice' => 'I was charged twice',
            'other' => 'Something else',
        ],
        self::MERCHANT_REDEMPTION => [
            'wrong_amount' => 'Wrong amount entered',
            'wrong_items' => 'Wrong items charged',
            'duplicate' => 'Charged twice by mistake',
            'other' => 'Something else',
        ],
        self::MERCHANT_PAYOUT => [
            'not_received' => 'The money never arrived',
            'wrong_amount' => 'The amount is wrong',
            'other' => 'Something else',
        ],
        self::EMPLOYER_CREDITS => [
            'bought_too_much' => 'We bought more credits than we need',
            'program_ending' => 'We are ending or pausing the program',
            'other' => 'Something else',
        ],
    ];

    /** @return array<string, string> key => label */
    public static function for(string $context): array
    {
        return self::REASONS[$context];
    }

    public static function label(string $context, string $key): string
    {
        return self::REASONS[$context][$key] ?? 'Something else';
    }

    /** Which list a correction's reason came from: by its type and who asked. */
    public static function contextOf(Correction $correction): string
    {
        return match ($correction->type) {
            Correction::TYPE_EMPLOYER_REFUND => self::EMPLOYER_CREDITS,
            Correction::TYPE_MERCHANT_ADJUSTMENT => self::MERCHANT_PAYOUT,
            default => $correction->requested_by_role === Role::Merchant->value ? self::MERCHANT_REDEMPTION : self::EMPLOYEE_REDEMPTION,
        };
    }
}
