<?php

namespace App\Services\Solvency;

use App\Models\CreditLedgerEntry as E;

/**
 * Which tranche every ledger line belongs to (ADR 0033).
 *
 * Customer funds held are a promise to pay: employer credits not yet given
 * out, employee balances, what merchants are owed and not yet transferred,
 * and anything held pending a decision. Operating funds are what Local
 * Goods has actually earned: the platform account, today only fees, which
 * are 0. Unspent credits are always the employer's money, never ours.
 *
 * Money in hand is the money in the customer funds account (the Stripe
 * platform balance, ADR 0034). A sweep moves earned fees out of it to Local
 * Goods Operating: the platform account goes down by the same amount, so
 * what is swept is no longer promised to anyone and the rule is unchanged.
 *
 * A line's tranche is decided by its account, and every line has exactly
 * one account (CreditLedgerEntry refuses anything else), so every cent is
 * in one tranche and only one. Each type lives on one account only; a new
 * type must be added here or LedgerTranchesTest fails.
 */
final class LedgerTranches
{
    public const CUSTOMER = 'customer_funds';

    public const OPERATING = 'operating_funds';

    public const EMPLOYEE = 'employee';

    public const EMPLOYER = 'employer';

    public const MERCHANT = 'merchant';

    public const PLATFORM = 'platform';

    /** The ledger column that names each account kind. */
    public const ACCOUNT_COLUMNS = [
        self::EMPLOYEE => 'employee_id',
        self::EMPLOYER => 'employer_id',
        self::MERCHANT => 'merchant_id',
        self::PLATFORM => 'platform',
    ];

    /** Every entry type and the one account it is written on. */
    public const ACCOUNT_OF_TYPE = [
        E::TYPE_PURCHASE => self::EMPLOYER,
        E::TYPE_ALLOCATION_OUT => self::EMPLOYER,
        E::TYPE_REVIEW_DENIED_TO_EMPLOYER => self::EMPLOYER,
        E::TYPE_EMPLOYER_REFUND_HOLD => self::EMPLOYER,
        E::TYPE_EMPLOYER_REFUNDED => self::EMPLOYER,
        E::TYPE_EMPLOYER_REFUND_RELEASED => self::EMPLOYER,

        E::TYPE_ALLOWANCE => self::EMPLOYEE,
        E::TYPE_REDEMPTION => self::EMPLOYEE,
        E::TYPE_REVIEW_APPROVED => self::EMPLOYEE,
        E::TYPE_REVIEW_REJECTED => self::EMPLOYEE,
        E::TYPE_REFUND => self::EMPLOYEE,

        E::TYPE_MERCHANT_EARNED => self::MERCHANT,
        E::TYPE_PAYOUT => self::MERCHANT,
        E::TYPE_PAYOUT_REVERSED => self::MERCHANT,
        E::TYPE_MERCHANT_FEE => self::MERCHANT,
        E::TYPE_MERCHANT_FEE_RETURNED => self::MERCHANT,
        E::TYPE_MERCHANT_REFUNDED => self::MERCHANT,
        E::TYPE_REFUND_REVERSAL => self::MERCHANT,

        E::TYPE_PLATFORM_FEE => self::PLATFORM,
        E::TYPE_EMPLOYER_PLATFORM_FEE => self::PLATFORM,
        E::TYPE_BID_PROGRAM_FEE => self::PLATFORM,
        E::TYPE_PLATFORM_MERCHANT_FEE => self::PLATFORM,
        E::TYPE_PLATFORM_MERCHANT_FEE_RETURNED => self::PLATFORM,
        E::TYPE_OPERATING_SWEEP => self::PLATFORM,
        E::TYPE_OPERATING_SWEEP_RETURNED => self::PLATFORM,
    ];

    /**
     * Lines that move money in hand, with their own amount: paid purchases
     * and platform fees (both kinds) and the BID program fee (ADR 0044) come in, payouts go out, reversed transfers and
     * refund reversals come back, sweeps leave for Local Goods Operating
     * (and a refused one comes back). The one other movement, an employer
     * refund going out, is an amount-0 employer_refunded line whose amount
     * is on its correction action.
     */
    public const MONEY_IN_HAND_TYPES = [
        E::TYPE_PURCHASE,
        E::TYPE_PLATFORM_FEE,
        E::TYPE_EMPLOYER_PLATFORM_FEE,
        E::TYPE_BID_PROGRAM_FEE,
        E::TYPE_PAYOUT,
        E::TYPE_PAYOUT_REVERSED,
        E::TYPE_REFUND_REVERSAL,
        E::TYPE_OPERATING_SWEEP,
        E::TYPE_OPERATING_SWEEP_RETURNED,
    ];

    /** Lines that move earned fees between the customer funds account and Local Goods Operating (ADR 0034). */
    public const SWEEP_TYPES = [
        E::TYPE_OPERATING_SWEEP,
        E::TYPE_OPERATING_SWEEP_RETURNED,
    ];

    /** A redemption's own line: its credits are held until one of the RESOLVES_HELD lines. */
    public const HOLDS_REDEMPTION = E::TYPE_REDEMPTION;

    /** The lines that end a redemption's hold: cleared (the merchant earns it) or denied (credits go back). */
    public const RESOLVES_HELD = [
        E::TYPE_MERCHANT_EARNED,
        E::TYPE_REVIEW_REJECTED,
        E::TYPE_REVIEW_DENIED_TO_EMPLOYER,
    ];

    /** Lines that open or release an employer refund hold, with their own amount. */
    public const REFUND_HOLD_TYPES = [
        E::TYPE_EMPLOYER_REFUND_HOLD,
        E::TYPE_EMPLOYER_REFUND_RELEASED,
    ];

    public static function accountOf(string $type): string
    {
        return self::ACCOUNT_OF_TYPE[$type] ?? throw new \LogicException("Ledger entry type {$type} has no tranche (ADR 0033).");
    }

    public static function trancheOf(string $type): string
    {
        return self::accountOf($type) === self::PLATFORM ? self::OPERATING : self::CUSTOMER;
    }
}
