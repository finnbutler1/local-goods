<?php

namespace App\Services\Corrections;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\CreditLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The platform admin's decision on a correction (ADR 0028), always with a
 * written reason the requester sees. Only a platform admin decides.
 *
 * Approving runs in one transaction: it claims the request (only a
 * requested one can be decided, so two admins pressing at once decide it
 * once), locks the item, re-reads every other correction on it and refuses
 * anything that would refund more than was paid. It then writes the
 * correction's actions. A simulated action completes in the same
 * transaction; a Stripe action is sent by CorrectionProcessor after the
 * commit, and its ledger lines are written when Stripe's webhook confirms.
 */
class CorrectionDecisions
{
    public const NOT_SENT_YET = 'The merchant hasn\'t been paid for this through Stripe yet. Send it first (Reconciliation → Retry owed transfers), then refund.';

    public const REVERSED_IN_DASHBOARD = 'This payment was reversed in the Stripe dashboard. Sort it out by hand in Stripe, then record a note.';

    public function __construct(private CreditLedger $ledger, private CorrectionProcessor $processor) {}

    public function reject(Correction $correction, User $by, string $reason): Correction
    {
        $reason = $this->checkDecider($by, $reason);

        $changed = Correction::whereKey($correction->id)->where('status', Correction::STATUS_REQUESTED)->update([
            'status' => Correction::STATUS_REJECTED, 'open_key' => null,
            'decided_by_user_id' => $by->id, 'decided_at' => now(), 'decision_reason' => $reason,
        ]);

        if ($changed === 0) {
            throw new InvalidArgumentException('This request was already decided.');
        }

        return $correction->refresh();
    }

    /**
     * Refund all or part of a cleared redemption to the employee: chosen menu
     * items (with their snapshotted tax), or for a typed-in amount any amount
     * up to what is left. A Stripe-paid redemption is refunded by reversing
     * that part of its transfer; one with no Stripe transfer (simulated) is
     * refunded at once, netted from what the merchant is owed.
     *
     * @param  array<int, int>  $quantities  redemption item id => how many (itemized redemptions)
     */
    public function approveRedemptionRefund(Correction $correction, User $by, string $reason, array $quantities = [], ?int $amountCents = null): Correction
    {
        $reason = $this->checkDecider($by, $reason);
        $this->checkType($correction, Correction::TYPE_REDEMPTION_REFUND);

        $correction = DB::transaction(function () use ($correction, $by, $reason, $quantities, $amountCents) {
            $this->claim($correction, $by, $reason);

            // Lock the redemption: a second approval on it waits here, then sees this one.
            $redemption = Redemption::whereKey($correction->redemption_id)->lockForUpdate()->firstOrFail();

            if (! $redemption->isApproved()) {
                throw new InvalidArgumentException('Only a cleared redemption can be refunded.');
            }

            $payout = MerchantPayout::where('redemption_id', $redemption->id)->lockForUpdate()->first();

            if ($payout !== null && $payout->status === MerchantPayout::STATUS_REVERSED) {
                throw new InvalidArgumentException(self::REVERSED_IN_DASHBOARD);
            }

            if ($payout !== null && $payout->status !== MerchantPayout::STATUS_SENT) {
                throw new InvalidArgumentException(self::NOT_SENT_YET);
            }

            if ($redemption->items()->exists()) {
                $refund = CorrectionLimits::itemRefund($redemption, $quantities, $correction->id);
            } else {
                $refund = ['amount_cents' => CorrectionLimits::amountRefund($redemption, (int) $amountCents, $correction->id),
                    'subtotal_cents' => null, 'tax_cents' => null, 'tax_lines' => null, 'items' => []];
            }

            // The overall guard, whatever the kind of refund.
            if (CorrectionLimits::redemptionRefundedCents($redemption, $correction->id) + $refund['amount_cents'] > $redemption->amount_cents) {
                throw new InvalidArgumentException('That would refund more than this redemption was for.');
            }

            $correction->update(array_intersect_key($refund, array_flip(['amount_cents', 'subtotal_cents', 'tax_cents', 'tax_lines'])));
            foreach ($refund['items'] as $item) {
                $correction->items()->create($item);
            }

            if ($payout === null) {
                $this->ledger->refundRedemption($correction, $refund['amount_cents']);
                $correction->actions()->create(['kind' => CorrectionAction::KIND_SIMULATED, 'amount_cents' => $refund['amount_cents'],
                    'status' => CorrectionAction::STATUS_SUCCEEDED, 'completed_at' => now()]);
                $this->complete($correction);
            } else {
                $correction->actions()->create(['kind' => CorrectionAction::KIND_TRANSFER_REVERSAL, 'merchant_payout_id' => $payout->id,
                    'amount_cents' => $refund['amount_cents'], 'status' => CorrectionAction::STATUS_PENDING]);
                $correction->update(['status' => Correction::STATUS_PROCESSING]);
            }

            return $correction;
        });

        // After the commit: a Stripe problem never undoes the decision; it is written on the correction.
        $this->processor->run($correction->refresh());

        return $correction->refresh();
    }

    /**
     * Refund unspent credits to the employer, up to its balance (credits
     * bought and not given out). The credits are held at once. The refund
     * goes back to the payments that bought them, newest first: merchant
     * transfers are funded oldest first, so the newest payments are the
     * least likely to have been claimed. Each payment gives back at most
     * what is left of it (credits only, never the platform fee; for a Stripe
     * payment, less what transfers claimed). A Stripe payment gets a Stripe
     * refund; a simulated purchase is returned at once, simulated.
     */
    public function approveEmployerRefund(Correction $correction, User $by, string $reason, int $amountCents): Correction
    {
        $reason = $this->checkDecider($by, $reason);
        $this->checkType($correction, Correction::TYPE_EMPLOYER_REFUND);

        $correction = DB::transaction(function () use ($correction, $by, $reason, $amountCents) {
            $this->claim($correction, $by, $reason);

            // Lock the employer (as allocating credits does), then its purchases.
            $employer = Employer::whereKey($correction->employer_id)->lockForUpdate()->firstOrFail();
            $purchases = CreditPurchase::where('employer_id', $employer->id)->whereIn('status', CreditPurchase::PAID_STATUSES)
                ->orderByDesc('purchased_at')->orderByDesc('id')->lockForUpdate()->get();

            if ($amountCents <= 0) {
                throw new InvalidArgumentException('Enter an amount more than $0.00.');
            }

            if ($amountCents > $employer->balanceCents()) {
                throw new InvalidArgumentException('Only '.Money::format(max(0, $employer->balanceCents())).' of unspent credits can be refunded.');
            }

            $plan = [];
            $left = $amountCents;
            foreach ($purchases as $purchase) {
                if ($left === 0) {
                    break;
                }
                if (($take = min($left, $purchase->refundableCents())) > 0) {
                    $plan[] = [$purchase, $take];
                    $left -= $take;
                }
            }

            if ($left > 0) {
                throw new InvalidArgumentException('Only '.Money::format($amountCents - $left).' can go back to the payments that bought these credits.');
            }

            $correction->update(['amount_cents' => $amountCents]);
            $this->ledger->holdEmployerRefund($correction, $amountCents);

            foreach ($plan as [$purchase, $take]) {
                $simulated = ! $purchase->isStripe();
                $correction->actions()->create([
                    'kind' => $simulated ? CorrectionAction::KIND_SIMULATED : CorrectionAction::KIND_CARD_REFUND,
                    'credit_purchase_id' => $purchase->id, 'amount_cents' => $take,
                    'status' => $simulated ? CorrectionAction::STATUS_SUCCEEDED : CorrectionAction::STATUS_PENDING,
                    'completed_at' => $simulated ? now() : null,
                ]);
                if ($simulated) {
                    $this->ledger->completeEmployerRefund($correction, $purchase, $take);
                }
            }

            if (! self::completeIfDone($correction)) {
                $correction->update(['status' => Correction::STATUS_PROCESSING]);
            }

            return $correction;
        });

        $this->processor->run($correction->refresh());

        return $correction->refresh();
    }

    /**
     * "I wasn't paid": the merchant is owed a Stripe transfer the ledger
     * shows but never got. Reuses the existing payout path (the same as
     * Reconciliation → Retry owed transfers): the payout row itself is sent,
     * for exactly its amount, through MerchantPayouts and the TransferGate.
     * No new kind of Stripe transfer. A sent or simulated payout has nothing
     * owed; one reversed in the Stripe dashboard is sorted out by hand.
     */
    public function approveMerchantAdjustment(Correction $correction, User $by, string $reason): Correction
    {
        $reason = $this->checkDecider($by, $reason);
        $this->checkType($correction, Correction::TYPE_MERCHANT_ADJUSTMENT);

        $correction = DB::transaction(function () use ($correction, $by, $reason) {
            $this->claim($correction, $by, $reason);
            $payout = MerchantPayout::whereKey($correction->merchant_payout_id)->lockForUpdate()->firstOrFail();

            if ($payout->status === MerchantPayout::STATUS_REVERSED) {
                throw new InvalidArgumentException(self::REVERSED_IN_DASHBOARD);
            }

            if (! in_array($payout->status, MerchantPayout::SENDABLE_STATUSES, true)) {
                throw new InvalidArgumentException($payout->status === MerchantPayout::STATUS_SENT
                    ? 'This payout was transferred ('.$payout->stripe_transfer_id.'), so nothing is owed on it. Reject the request with what you found.'
                    : 'Simulated payouts move no real money, so there is nothing to send.');
            }

            if ($payout->amount_cents > $payout->merchant->payableCents()) {
                throw new InvalidArgumentException('The ledger shows less than '.Money::format($payout->amount_cents).' owed to this merchant, so nothing is sent.');
            }

            $correction->update(['amount_cents' => $payout->amount_cents, 'status' => Correction::STATUS_PROCESSING]);
            $correction->actions()->create(['kind' => CorrectionAction::KIND_ADJUSTMENT_TRANSFER, 'merchant_payout_id' => $payout->id,
                'amount_cents' => $payout->amount_cents, 'status' => CorrectionAction::STATUS_PENDING]);

            return $correction;
        });

        $this->processor->run($correction->refresh());

        return $correction->refresh();
    }

    /**
     * Stop a failed correction: nothing more is sent, and an employer
     * refund's credits that never went back are the employer's again. Not
     * while any part's outcome at Stripe is still unknown (a lost answer):
     * retry first, so a refund that did happen is never also released.
     */
    public function close(Correction $correction, User $by, string $reason): Correction
    {
        $reason = $this->checkDecider($by, $reason);

        return DB::transaction(function () use ($correction, $by, $reason) {
            $changed = Correction::whereKey($correction->id)->where('status', Correction::STATUS_FAILED)
                ->whereDoesntHave('actions', fn ($q) => $q->where('status', CorrectionAction::STATUS_PROCESSING))
                ->update(['status' => Correction::STATUS_CLOSED, 'open_key' => null, 'decision_reason' => $reason, 'decided_by_user_id' => $by->id]);

            if ($changed === 0) {
                throw new InvalidArgumentException('Only a failed correction with no step still waiting on Stripe can be closed. Retry it first.');
            }

            $correction->refresh();
            $unsent = (int) $correction->actions()->where('status', '!=', CorrectionAction::STATUS_SUCCEEDED)->sum('amount_cents');

            if ($correction->type === Correction::TYPE_EMPLOYER_REFUND && $unsent > 0) {
                $this->ledger->releaseEmployerRefund($correction, $unsent);
            }

            return $correction;
        });
    }

    /**
     * Retry a failed (or unconfirmed) correction's Stripe actions. Safe to
     * press any number of times: an action whose outcome is unknown keeps its
     * idempotency key, and only a definite refusal gets a new one.
     */
    public function retry(Correction $correction, User $by): Correction
    {
        $this->checkDecider($by, 'retry');

        if (! in_array($correction->status, [Correction::STATUS_FAILED, Correction::STATUS_PROCESSING], true)) {
            throw new InvalidArgumentException('Only a failed or processing correction can be retried.');
        }

        $this->processor->run($correction);

        return $correction->refresh();
    }

    /** Marks a correction completed once every action has succeeded. Call inside the transaction that finished the last one. */
    public function complete(Correction $correction): void
    {
        self::completeIfDone($correction);
    }

    public static function completeIfDone(Correction $correction): bool
    {
        if ($correction->actions()->where('status', '!=', CorrectionAction::STATUS_SUCCEEDED)->exists()) {
            return false;
        }

        return Correction::whereKey($correction->id)->whereIn('status', [Correction::STATUS_APPROVED, Correction::STATUS_PROCESSING, Correction::STATUS_FAILED])
            ->update(['status' => Correction::STATUS_COMPLETED, 'open_key' => null, 'completed_at' => now(), 'failure_reason' => null]) > 0;
    }

    /** Only a requested correction can be decided; this flips it to approved, or refuses. Inside the approval transaction. */
    private function claim(Correction $correction, User $by, string $reason): void
    {
        $changed = Correction::whereKey($correction->id)->where('status', Correction::STATUS_REQUESTED)->update([
            'status' => Correction::STATUS_APPROVED,
            'decided_by_user_id' => $by->id, 'decided_at' => now(), 'decision_reason' => $reason,
        ]);

        if ($changed === 0) {
            throw new InvalidArgumentException('This request was already decided.');
        }

        $correction->refresh();
    }

    private function checkDecider(User $by, string $reason): string
    {
        if (! $by->hasRole(Role::PlatformAdmin)) {
            throw new LogicException('Only a platform admin decides corrections.');
        }

        if (($reason = trim($reason)) === '') {
            throw new InvalidArgumentException('Write a reason. The person who asked will see it.');
        }

        return $reason;
    }

    private function checkType(Correction $correction, string $type): void
    {
        if ($correction->type !== $type) {
            throw new LogicException('That is not a '.str_replace('_', ' ', $type).'.');
        }
    }
}
