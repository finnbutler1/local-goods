<?php

namespace App\Services\Corrections;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\CreditLedger;
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
