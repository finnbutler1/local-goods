<?php

namespace App\Services\Payments;

use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\CreditLedgerEntry;
use App\Models\District;
use App\Models\User;
use App\Services\Solvency\SolvencyGuard;
use App\Support\BidProgramFee;
use App\Support\DemoLogins;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The only place a BID program fee payment changes (ADR 0044).
 *
 * - request(): the program year's one payment row, made or reused. The
 *   amount comes from the district's tier, never from the request. Its
 *   unique fee_key ("<district>:<year>") means double clicks, two tabs and
 *   retries all land on the same row, so a year is charged at most once.
 * - markPaidByStripe(): the only way the fee reaches the ledger, from a
 *   verified webhook. Checks amount, currency and metadata against the row
 *   and records nothing on any mismatch; flips only a pending row, so a
 *   replay writes nothing. One platform ledger line: Local Goods revenue,
 *   never customer funds, never on an employer or employee balance.
 * - note(): a platform admin's note on a payment. Never moves money: the
 *   fee is not refundable in the app.
 */
class BidProgramFees
{
    public function __construct(private SolvencyGuard $solvency) {}

    public function request(District $district, User $by): BidProgramFeePayment
    {
        if (! BidProgramFee::charges()) {
            throw new InvalidArgumentException('The program fee is not being charged here, so nothing was started.');
        }
        if ($by->isReadOnly()) {
            throw new LogicException('Judges can\'t pay the program fee.');
        }

        try {
            return DB::transaction(function () use ($district, $by) {
                $current = District::whereKey($district->id)->lockForUpdate()->firstOrFail();
                $year = BidProgramFee::programYear($current);

                if (! BidProgramFee::isSetUp($current)) {
                    throw new InvalidArgumentException('Local Goods hasn\'t set this district\'s member businesses and program start date yet, so nothing is due.');
                }
                if ($year === null) {
                    throw new InvalidArgumentException('The program hasn\'t started yet, so nothing is due.');
                }

                $key = BidProgramFee::key($current, $year['year']);
                $existing = BidProgramFeePayment::where('fee_key', $key)->first();
                if ($existing !== null) {
                    return $existing->isPaid()
                        ? throw new InvalidArgumentException('This program year is already paid.')
                        : $existing;
                }

                $tier = BidProgramFee::tierFor($current->member_businesses);
                $cap = (int) config('demo.bid_program_fee_cap_cents');
                if (DemoLogins::isSharedLogin($by) && $tier['fee_cents'] > $cap) {
                    throw new InvalidArgumentException('The demo BID admin can pay up to '.Money::format($cap).'.');
                }

                return BidProgramFeePayment::create([
                    'district_id' => $current->id,
                    'program_year' => $year['year'],
                    'period_starts_on' => $year['starts_on']->format('Y-m-d'),
                    'period_ends_on' => $year['ends_on']->format('Y-m-d'),
                    'tier' => $tier['name'],
                    'member_businesses' => $current->member_businesses,
                    'amount_cents' => $tier['fee_cents'],
                    'currency' => config('services.stripe.currency'),
                    'status' => BidProgramFeePayment::STATUS_PENDING,
                    'fee_key' => $key,
                    'payment_token' => (string) Str::uuid(),
                    'requested_by_user_id' => $by->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request made the year's row first (the lock makes this rare): use that one.
            $year = BidProgramFee::programYear($district->refresh());
            $existing = BidProgramFeePayment::where('fee_key', BidProgramFee::key($district, $year['year']))->firstOrFail();

            return $existing->isPaid() ? throw new InvalidArgumentException('This program year is already paid.') : $existing;
        }
    }

    /**
     * Records a verified Stripe payment of the fee. Throws on any mismatch
     * (nothing written); false when the row was already paid (nothing written).
     */
    public function markPaidByStripe(BidProgramFeePayment $payment, array $paymentIntent, ?Carbon $at = null): bool
    {
        $amount = (int) ($paymentIntent['amount_received'] ?? $paymentIntent['amount'] ?? -1);
        $currency = strtolower((string) ($paymentIntent['currency'] ?? ''));
        $metadata = $paymentIntent['metadata'] ?? [];

        if ($amount !== $payment->amount_cents || $currency !== $payment->currency) {
            throw new InvalidArgumentException('The Stripe payment ('.Money::format($amount).' '.strtoupper($currency).') does not match program fee payment #'
                .$payment->id.' ('.Money::format($payment->amount_cents).' '.strtoupper($payment->currency).').');
        }
        if (($metadata['lg_bid_fee_id'] ?? null) !== (string) $payment->id
            || ($metadata['lg_district_id'] ?? null) !== (string) $payment->district_id
            || ($metadata['lg_program_year'] ?? null) !== (string) $payment->program_year) {
            throw new InvalidArgumentException('The Stripe payment\'s details do not match program fee payment #'.$payment->id.'.');
        }

        $at ??= now();
        $charge = is_array($paymentIntent['latest_charge'] ?? null) ? $paymentIntent['latest_charge']['id'] : ($paymentIntent['latest_charge'] ?? null);

        // Stripe already has the money: recorded, then checked (ADR 0033).
        $paid = DB::transaction(function () use ($payment, $paymentIntent, $charge, $at) {
            $changed = BidProgramFeePayment::whereKey($payment->id)
                ->where('status', BidProgramFeePayment::STATUS_PENDING)
                ->update([
                    'status' => BidProgramFeePayment::STATUS_PAID,
                    'stripe_payment_intent_id' => $paymentIntent['id'],
                    'stripe_charge_id' => $charge,
                    'paid_at' => $at,
                    'payment_error' => null,
                ]);

            $payment->refresh();

            if ($changed === 0) {
                return false;
            }

            CreditLedgerEntry::create([
                'platform' => true,
                'amount_cents' => $payment->amount_cents,
                'type' => CreditLedgerEntry::TYPE_BID_PROGRAM_FEE,
                'bid_program_fee_payment_id' => $payment->id,
                'description' => 'BID program fee, program year '.$payment->program_year.' ('.$payment->tier.' tier), paid in Stripe test mode ('.$paymentIntent['id'].')',
                'created_at' => $at,
            ]);

            return true;
        });

        if ($paid) {
            $this->solvency->afterRecorded('a Stripe payment of the BID program fee, program year '.$payment->program_year);
        }

        return $paid;
    }

    /** A platform admin's note on a payment (for example about a refund made outside the app). Moves no money. */
    public function note(BidProgramFeePayment $payment, User $by, string $note): void
    {
        if (! $by->hasRole(Role::PlatformAdmin) || $by->isReadOnly()) {
            throw new LogicException('Only the platform admin writes a note on a program fee payment.');
        }
        if (($note = trim($note)) === '') {
            throw new InvalidArgumentException('Write the note.');
        }

        $payment->forceFill(['admin_note' => Str::limit($note, 2000, ''), 'admin_note_by_user_id' => $by->id, 'admin_noted_at' => now()])->save();
    }
}
