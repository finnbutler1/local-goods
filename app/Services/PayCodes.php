<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Districts\DistrictRules;
use App\Support\MerchantFee;
use App\Support\TaxBreakdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Pay in person (ADR 0026). An employee gets a one-time code for one
 * merchant and one amount; the merchant types it in and confirms. Nothing
 * moves until then: confirming makes the redemption through
 * RedemptionService::redeem, exactly like ordering to go (same balance
 * check, same Redemption Fraud Detection input, same TransferGate).
 *
 * The code is never logged and never in a URL. It's found by an HMAC keyed
 * with APP_KEY; the stored copy is encrypted. Every miss a merchant makes
 * (unknown, someone else's, expired, used, cancelled) gets the same
 * NOT_VALID message and counts towards MAX_FAILURES a minute.
 */
class PayCodes
{
    /** No 0/O, no 1/I/L: nothing that reads two ways at a counter. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    public const MAX_FAILURES = 5;

    public const NOT_VALID = 'Code not valid.';

    public const TOO_MANY_TRIES = 'Too many tries. Wait a minute and try again.';

    public const NOT_ENOUGH_CREDITS = 'Not enough credits for this payment. Nothing was charged.';

    public function __construct(private RedemptionService $redemptions) {}

    /**
     * A new code for this employee at this merchant. Any code they already
     * had is cancelled first: one live code per employee. Writes no ledger
     * entry; the balance is only checked here so they find out now, and is
     * checked again (for real) when the merchant confirms.
     *
     * The DC sales tax is fixed when the code is made (ADR 0027): the code's
     * amount is the total including tax, and confirming redeems with exactly
     * that snapshot.
     *
     * @param  list<array{merchant_product_id: ?int, name: string, unit_price_cents: int, quantity: int}>  $items
     */
    public function issue(Employee $employee, Merchant $merchant, int $amountCents, array $items = [], ?TaxBreakdown $tax = null): PayCode
    {
        $tax ??= TaxBreakdown::included($amountCents);

        if ($tax->totalCents !== $amountCents) {
            throw new LogicException('The tax must add up to the pay code amount.');
        }

        // The district rule (ADR 0031), checked here too so a code is never issued for a shop the credits can't be spent at.
        DistrictRules::ensureRedeemable($employee, $merchant, 'pay_merchant_id');

        return DB::transaction(function () use ($employee, $merchant, $amountCents, $items, $tax) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            if ($amountCents > $employee->balanceCents()) {
                throw ValidationException::withMessages(['amount' => 'That is more than the available balance.']);
            }

            PayCode::where('employee_id', $employee->id)->where('status', PayCode::STATUS_ACTIVE)->update([
                'status' => PayCode::STATUS_CANCELLED,
                'cancelled_by' => PayCode::BY_REPLACED,
                'cancelled_at' => now(),
            ]);

            // About 887 million codes, so a clash with another live code is
            // very rare; if it happens, draw again.
            do {
                $code = self::generate();
            } while (PayCode::where('code_hash', self::hash($code))->where('status', PayCode::STATUS_ACTIVE)->exists());

            return PayCode::create([
                'employee_id' => $employee->id,
                'merchant_id' => $merchant->id,
                'amount_cents' => $amountCents,
                'items' => $items === [] ? null : $items,
                'code_hash' => self::hash($code),
                'code' => $code,
                'status' => PayCode::STATUS_ACTIVE,
                'expires_at' => now()->addMinutes(PayCode::EXPIRES_MINUTES),
                ...$tax->columns(),
            ]);
        });
    }

    /** The employee's live code, if any (expired ones are marked expired first). */
    public function liveFor(Employee $employee): ?PayCode
    {
        $this->expireStale();

        return PayCode::where('employee_id', $employee->id)->where('status', PayCode::STATUS_ACTIVE)->latest('id')->first();
    }

    /** The employee cancels their own live code. A code that's no longer live is left as it is. */
    public function cancelByEmployee(PayCode $payCode): void
    {
        $this->expireStale();
        $this->cancel($payCode, PayCode::BY_EMPLOYEE);
    }

    /**
     * The merchant typed a code: their own live code, or NOT_VALID. Misses
     * count towards the per-merchant limit; nothing about why it missed is
     * ever shown.
     */
    public function lookup(Merchant $merchant, string $typed): PayCode
    {
        $key = self::failureKey($merchant);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw ValidationException::withMessages(['pay_code' => self::TOO_MANY_TRIES]);
        }

        $this->expireStale();
        $code = self::normalize($typed);
        $payCode = $code === null ? null : PayCode::where('code_hash', self::hash($code))
            ->where('merchant_id', $merchant->id)
            ->where('status', PayCode::STATUS_ACTIVE)
            ->first();

        if ($payCode === null) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['pay_code' => self::NOT_VALID]);
        }

        return $payCode;
    }

    /**
     * The merchant confirms. The code is claimed first (one UPDATE that only
     * succeeds while it's still live and this merchant's), so two presses
     * can never make two redemptions. Then the redemption is made exactly
     * like ordering to go; only the channel differs, and that is display only.
     */
    public function confirm(PayCode $payCode, Merchant $merchant, ?MerchantFee $fee = null): Redemption
    {
        // Checked before the claim: redeem() would refuse too, but its refusal would be taken for a balance one below.
        DistrictRules::ensureRedeemable($payCode->employee, $merchant, 'pay_code');

        $claimed = PayCode::whereKey($payCode->id)
            ->where('merchant_id', $merchant->id)
            ->where('status', PayCode::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->update(['status' => PayCode::STATUS_USED, 'used_at' => now()]);

        if ($claimed === 0) {
            throw ValidationException::withMessages(['pay_code' => self::NOT_VALID]);
        }

        $payCode->refresh();

        try {
            $redemption = $this->redemptions->redeem(
                $payCode->employee, $merchant, $payCode->amount_cents, $payCode->items ?? [], Redemption::CHANNEL_IN_PERSON,
                $payCode->taxBreakdown(), $fee,
            );
        } catch (ValidationException) {
            // The balance check: they spent the credits since getting the code.
            $payCode->forceFill(['status' => PayCode::STATUS_CANCELLED, 'cancelled_by' => PayCode::BY_BALANCE, 'cancelled_at' => now(), 'used_at' => null])->save();

            throw ValidationException::withMessages(['pay_code' => self::NOT_ENOUGH_CREDITS]);
        } catch (Throwable $e) {
            // Nothing was recorded (redeem writes in one transaction), so the code can be tried again.
            PayCode::whereKey($payCode->id)->where('status', PayCode::STATUS_USED)->whereNull('redemption_id')
                ->update(['status' => PayCode::STATUS_ACTIVE, 'used_at' => null]);

            throw $e;
        }

        $payCode->forceFill(['redemption_id' => $redemption->id])->save();

        return $redemption;
    }

    /** The merchant declines a live code of theirs. */
    public function decline(PayCode $payCode, Merchant $merchant): void
    {
        $this->expireStale();

        if ($payCode->merchant_id !== $merchant->id) {
            throw ValidationException::withMessages(['pay_code' => self::NOT_VALID]);
        }

        $this->cancel($payCode, PayCode::BY_MERCHANT);
    }

    /** Active codes past their time become expired. Cheap, and run wherever codes are read. */
    public function expireStale(): void
    {
        PayCode::where('status', PayCode::STATUS_ACTIVE)->where('expires_at', '<=', now())
            ->update(['status' => PayCode::STATUS_EXPIRED]);
    }

    /** Uppercase, no spaces or dashes; null unless it could be a code at all. */
    public static function normalize(string $typed): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $typed));

        return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH ? $code : null;
    }

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, 'pay-code|'.config('app.key'));
    }

    public static function failureKey(Merchant $merchant): string
    {
        return 'pay-code-fail|merchant:'.$merchant->id;
    }

    private function cancel(PayCode $payCode, string $by): void
    {
        PayCode::whereKey($payCode->id)->where('status', PayCode::STATUS_ACTIVE)->update([
            'status' => PayCode::STATUS_CANCELLED,
            'cancelled_by' => $by,
            'cancelled_at' => now(),
        ]);
    }
}
