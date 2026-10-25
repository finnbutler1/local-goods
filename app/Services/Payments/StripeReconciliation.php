<?php

namespace App\Services\Payments;

use App\Models\Bid;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\Money;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Carbon;

/**
 * Puts the ledger next to Stripe for Stripe-funded activity only (ADR 0015):
 * purchases paid in Stripe test mode (credits plus any platform fee, ADR
 * 0021) against Stripe's payments, Stripe-
 * channel payouts against Stripe's transfers, and the platform balance.
 * Simulated purchases and payouts are not part of the check; they are only
 * counted. Each difference comes back as a plain-English sentence.
 *
 * The window starts at the last Reset demo (when the BID row was seeded),
 * because a reset clears Local Goods' records but not Stripe's.
 *
 * Only this environment's objects are counted (StripeEnvironment): local
 * development and production can share one Stripe sandbox. An object
 * counts if its tag matches, or if it has no tag (made before tagging) but
 * this database already knows its id. Everything else is listed in a
 * separate "From another environment, not counted" note with its totals.
 *
 * Corrections (ADR 0028): refund reversals Local Goods made are taken off
 * the transfers it counts (Stripe nets them off too), each reversal and
 * each card refund to an employer is matched by id and amount, and refunds
 * have their own total. With no corrections every number is as before.
 *
 * The merchant fee (ADR 0029): a transfer is the sale less the fee, so the
 * fee stays in the platform's balance, and the expected balance already
 * includes it. Each sent transfer plus its fee must be its redemption's
 * total, and the platform account's fee lines must add up to the fees on
 * cleared sales less what completed refunds gave back. With no fee (the
 * default) every number and sentence is as before.
 */
class StripeReconciliation
{
    public function __construct(private StripeGateway $stripe) {}

    public function report(): array
    {
        $since = Bid::orderBy('id')->value('created_at');
        $since = $since ? Carbon::parse($since) : now()->subDays(30);

        $report = [
            'active' => StripeMode::active(),
            'problem' => StripeMode::problem(),
            'since' => $since,
            'simulated' => [
                'purchases' => CreditPurchase::where('status', CreditPurchase::STATUS_SIMULATED)->count(),
                'purchase_cents' => (int) CreditPurchase::where('status', CreditPurchase::STATUS_SIMULATED)->sum('amount_cents'),
                'payouts' => MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->count(),
                'payout_cents' => (int) MerchantPayout::where('status', MerchantPayout::STATUS_SIMULATED)->sum('amount_cents'),
                'refunds' => CorrectionAction::where('kind', CorrectionAction::KIND_SIMULATED)->count(),
                'refund_cents' => (int) CorrectionAction::where('kind', CorrectionAction::KIND_SIMULATED)->sum('amount_cents'),
            ],
            'environment' => StripeEnvironment::label(),
            'error' => null,
            'mismatches' => [],
            'notes' => [],
            'other' => ['payments' => 0, 'payment_cents' => 0, 'transfers' => 0, 'transfer_cents' => 0, 'refunds' => 0, 'refund_cents' => 0, 'lines' => []],
        ];

        $local = $this->localTotals();
        $report['ledger'] = $local;

        if (! $report['active']) {
            return $report;
        }

        try {
            $intents = collect($this->stripe->listPaymentIntents($since->getTimestamp()))
                ->filter(fn ($pi) => isset($pi['metadata']['lg_purchase_token']) && ($pi['status'] ?? null) === 'succeeded')
                ->keyBy('id');
            $transfers = collect($this->stripe->listTransfers($since->getTimestamp()))
                ->filter(fn ($tr) => isset($tr['metadata']['lg_payout_key']))
                ->keyBy('id');
            $refunds = collect($this->stripe->listRefunds($since->getTimestamp()))
                ->filter(fn ($re) => isset($re['metadata']['lg_correction_key']))
                ->keyBy('id');
            $balance = $this->stripe->retrieveBalance();
        } catch (StripeGatewayException $e) {
            $report['error'] = $e->getMessage();

            return $report;
        }

        [$intents, $otherIntents] = $intents->partition(fn ($pi) => $this->ourPayment($pi));
        [$transfers, $otherTransfers] = $transfers->partition(fn ($tr) => $this->ourTransfer($tr));
        [$refunds, $otherRefunds] = $refunds->partition(fn ($re) => $this->ourRefund($re));
        $report['other'] = $this->otherEnvironments($otherIntents, $otherTransfers, $otherRefunds);
        $succeeded = $refunds->where('status', 'succeeded');

        $report['stripe'] = [
            'purchase_cents' => (int) $intents->sum(fn ($pi) => $pi['amount_received'] ?? $pi['amount']),
            'purchases' => $intents->count(),
            'transfer_cents' => (int) $transfers->sum(fn ($tr) => $tr['amount'] - ($tr['amount_reversed'] ?? 0)),
            'transfers' => $transfers->count(),
            'refund_cents' => (int) $succeeded->sum('amount'),
            'refunds' => $succeeded->count(),
            'balance_cents' => self::usd($balance['available'] ?? []) + self::usd($balance['pending'] ?? []),
            'available_cents' => self::usd($balance['available'] ?? []),
        ];

        $report['mismatches'] = [...$this->comparePurchases($intents), ...$this->compareTransfers($transfers),
            ...$this->compareReversals($transfers), ...$this->compareRefunds($refunds), ...$this->compareMerchantFees()];

        if ($local['purchase_cents'] !== $report['stripe']['purchase_cents']) {
            $report['mismatches'][] = 'Purchases: the ledger counts '.Money::format($local['purchase_cents']).' paid through Stripe'.self::feeSplit($local).'; Stripe received '
                .Money::format($report['stripe']['purchase_cents']).'. Difference: '.Money::format($report['stripe']['purchase_cents'] - $local['purchase_cents']).'.';
        }
        // The fee's own ledger lines, on the platform account, must add up to the fees those purchases charged.
        if ($local['fee_cents'] !== $local['fee_line_cents']) {
            $report['mismatches'][] = 'Platform fees: Stripe-paid purchases charged '.Money::format($local['fee_cents']).' in platform fees, but the platform account in the ledger has '
                .Money::format($local['fee_line_cents']).' for them.';
        }
        if ($local['transfer_cents'] !== $report['stripe']['transfer_cents']) {
            $report['mismatches'][] = 'Transfers: the ledger counts '.Money::format($local['transfer_cents']).' sent to merchants through Stripe; Stripe shows '
                .Money::format($report['stripe']['transfer_cents']).'. Difference: '.Money::format($report['stripe']['transfer_cents'] - $local['transfer_cents']).'.';
        }

        if ($local['refund_cents'] !== $report['stripe']['refund_cents']) {
            $report['mismatches'][] = 'Refunds to employers: the ledger counts '.Money::format($local['refund_cents']).' refunded to cards through Stripe; Stripe shows '
                .Money::format($report['stripe']['refund_cents']).'. Difference: '.Money::format($report['stripe']['refund_cents'] - $local['refund_cents']).'.';
        }

        $expected = $local['purchase_cents'] - $local['transfer_cents'] - $local['refund_cents'];
        $report['expected_balance_cents'] = $expected;
        if ($report['stripe']['balance_cents'] !== $expected) {
            $report['notes'][] = 'Stripe\'s platform balance ('.Money::format($report['stripe']['balance_cents']).') differs from Stripe-paid purchases minus transfers'.($local['refund_cents'] > 0 ? ' and refunds' : '').' ('
                .Money::format($expected).') by '.Money::format($report['stripe']['balance_cents'] - $expected).'. That is expected: Stripe keeps its card fees, and the balance also holds test activity from before the last Reset demo and from other environments sharing this Stripe account. Not counted as a mismatch.';
        }

        $unsent = MerchantPayout::whereIn('status', MerchantPayout::UNPAID_STRIPE_STATUSES)->with('merchant')->get();
        foreach ($unsent as $row) {
            $report['notes'][] = Money::format($row->amount_cents).' owed to '.$row->merchant->name.' through Stripe is not sent yet ('
                .match ($row->status) {
                    MerchantPayout::STATUS_OWED => 'waiting for the merchant to finish Stripe onboarding',
                    MerchantPayout::STATUS_REVERSED => 'reversed in Stripe; a person decides what happens next',
                    default => $row->failure_reason ?? 'being sent',
                }.').';
        }

        foreach (CorrectionAction::whereIn('kind', [CorrectionAction::KIND_TRANSFER_REVERSAL, CorrectionAction::KIND_CARD_REFUND])
            ->whereIn('status', [CorrectionAction::STATUS_PENDING, CorrectionAction::STATUS_PROCESSING, CorrectionAction::STATUS_FAILED])->get() as $action) {
            $report['notes'][] = 'Correction #'.$action->correction_id.': '.Money::format($action->amount_cents)
                .($action->kind === CorrectionAction::KIND_CARD_REFUND ? ' card refund' : ' refund reversal').' not confirmed yet ('
                .($action->status === CorrectionAction::STATUS_FAILED ? 'Stripe refused it: '.$action->failure_reason : 'waiting for Stripe\'s webhook').'). Not in the totals above until it is.';
        }

        return $report;
    }

    /**
     * purchase_cents is what the employer paid: credits plus platform fees
     * (fees are 0 while the fee is off).
     *
     * transfer_cents is sent transfers less the refund reversals on them
     * (ADR 0028), as Stripe nets them off; refund_cents is card refunds to
     * employers Stripe confirmed.
     *
     * @return array{purchase_cents: int, credit_cents: int, fee_cents: int, fee_line_cents: int, purchases: int, transfer_cents: int, transfers: int, refund_reversal_cents: int, refund_cents: int, refunds: int}
     */
    private function localTotals(): array
    {
        $paid = CreditPurchase::where('status', CreditPurchase::STATUS_PAID);
        $sent = MerchantPayout::where('status', MerchantPayout::STATUS_SENT);
        $credits = (int) (clone $paid)->sum('amount_cents');
        $fees = (int) (clone $paid)->sum('platform_fee_cents');
        $reversed = (int) CorrectionAction::where('kind', CorrectionAction::KIND_TRANSFER_REVERSAL)->where('status', CorrectionAction::STATUS_SUCCEEDED)
            ->whereIn('merchant_payout_id', (clone $sent)->select('id'))->sum('amount_cents');
        $refunds = CorrectionAction::where('kind', CorrectionAction::KIND_CARD_REFUND)->where('status', CorrectionAction::STATUS_SUCCEEDED);

        return [
            'purchase_cents' => $credits + $fees,
            'credit_cents' => $credits,
            'fee_cents' => $fees,
            'fee_line_cents' => (int) CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE)
                ->whereIn('credit_purchase_id', (clone $paid)->select('id'))->sum('amount_cents'),
            'purchases' => (clone $paid)->count(),
            'transfer_cents' => (int) (clone $sent)->sum('amount_cents') - $reversed,
            'transfers' => (clone $sent)->count(),
            'refund_reversal_cents' => $reversed,
            'refund_cents' => (int) (clone $refunds)->sum('amount_cents'),
            'refunds' => (clone $refunds)->count(),
            // Kept by the platform on Stripe-paid sales, net of fees given back on refunds (ADR 0029). Already inside the expected balance.
            'merchant_fee_cents' => (int) CreditLedgerEntry::where('platform', true)
                ->whereIn('type', [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED])
                ->whereIn('redemption_id', (clone $sent)->select('redemption_id'))->sum('amount_cents'),
        ];
    }

    /**
     * The merchant fee (ADR 0029): every sent transfer plus the fee held
     * back from it is its redemption's total, at the redemption's own
     * snapshot; and the platform account's merchant fee lines are the fees
     * on cleared sales less the shares completed refunds gave back.
     *
     * @return list<string>
     */
    private function compareMerchantFees(): array
    {
        $out = [];

        foreach (MerchantPayout::where('status', MerchantPayout::STATUS_SENT)->whereNotNull('redemption_id')->with('redemption')->get() as $row) {
            if ($row->redemption && ($row->amount_cents + $row->fee_cents !== $row->redemption->amount_cents || $row->fee_cents !== (int) $row->redemption->merchant_fee_cents)) {
                $out[] = 'Transfer '.$row->stripe_transfer_id.' ('.Money::format($row->amount_cents).') plus the Local Goods fee held back ('.Money::format($row->fee_cents)
                    .') should be redemption '.$row->redemption->reference().'\'s '.Money::format($row->redemption->amount_cents).' less its '.Money::format((int) $row->redemption->merchant_fee_cents).' fee.';
            }
        }

        $charged = (int) Redemption::where('status', Redemption::STATUS_APPROVED)->sum('merchant_fee_cents');
        $returned = (int) Correction::where('type', Correction::TYPE_REDEMPTION_REFUND)->where('status', Correction::STATUS_COMPLETED)->sum('fee_cents');
        $lines = (int) CreditLedgerEntry::where('platform', true)
            ->whereIn('type', [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED])->sum('amount_cents');

        if ($lines !== $charged - $returned) {
            $out[] = 'Local Goods fees on merchant sales: cleared sales charged '.Money::format($charged).' and refunds gave back '.Money::format($returned)
                .', but the platform account in the ledger has '.Money::format($lines).' for them.';
        }

        return $out;
    }

    /** @return list<string> */
    private function comparePurchases($intents): array
    {
        $out = [];
        $paid = CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->get();

        foreach ($paid as $purchase) {
            $pi = $intents->get($purchase->stripe_payment_intent_id);
            if ($pi === null) {
                $out[] = 'Local Goods counts '.$purchase->reference.' ('.Money::format($purchase->totalCents()).') as paid through Stripe ('
                    .$purchase->stripe_payment_intent_id.'), but Stripe has no such successful payment since the last reset.';
            } elseif (($pi['amount_received'] ?? $pi['amount']) !== $purchase->totalCents()) {
                $out[] = $purchase->reference.' is '.Money::format($purchase->totalCents()).' in the ledger'
                    .($purchase->platform_fee_cents > 0 ? ' (credits '.Money::format($purchase->amount_cents).' + platform fee '.Money::format($purchase->platform_fee_cents).')' : '')
                    .' but Stripe received '
                    .Money::format($pi['amount_received'] ?? $pi['amount']).' ('.$pi['id'].').';
            }
        }

        $known = $paid->pluck('stripe_payment_intent_id')->filter()->all();
        foreach ($intents->except($known) as $pi) {
            $pending = CreditPurchase::where('payment_token', $pi['metadata']['lg_purchase_token'])->where('status', CreditPurchase::STATUS_PENDING)->first();
            $out[] = $pending
                ? 'Stripe received '.Money::format($pi['amount_received'] ?? $pi['amount']).' for '.$pending->reference.' ('.$pi['id'].'), but Local Goods still shows it pending: the webhook has not arrived. Check the webhook endpoint in Stripe.'
                : 'Stripe has a '.Money::format($pi['amount_received'] ?? $pi['amount']).' payment ('.$pi['id'].') that Local Goods has no ledger record of (for example a purchase paid twice). Check before refunding it in Stripe.';
        }

        return $out;
    }

    /** @return list<string> */
    private function compareTransfers($transfers): array
    {
        $out = [];
        $rows = MerchantPayout::whereIn('status', [MerchantPayout::STATUS_SENT, MerchantPayout::STATUS_REVERSED])->with('merchant')->get();

        foreach ($rows as $row) {
            $tr = $transfers->get($row->stripe_transfer_id);
            if ($tr === null) {
                $out[] = 'Local Goods recorded transfer '.$row->stripe_transfer_id.' of '.Money::format($row->amount_cents).' to '.$row->merchant->name.', but Stripe has no such transfer since the last reset.';
            } elseif ($tr['amount'] !== $row->amount_cents) {
                $out[] = 'Transfer '.$tr['id'].' is '.Money::format($row->amount_cents).' in the ledger but '.Money::format($tr['amount']).' in Stripe.';
            } elseif (($tr['reversed'] ?? false) && $row->status === MerchantPayout::STATUS_SENT && self::refundReversedCents($row) !== $tr['amount']) {
                $out[] = 'Stripe shows transfer '.$tr['id'].' to '.$row->merchant->name.' as reversed, but Local Goods still counts it as paid.';
            }
        }

        $known = $rows->pluck('stripe_transfer_id')->filter()->all();
        foreach ($transfers->except($known) as $tr) {
            $out[] = 'Stripe has a '.Money::format($tr['amount']).' transfer ('.$tr['id'].') to '.($tr['destination'] ?? 'a connected account').' that Local Goods has no record of.';
        }

        return $out;
    }

    /** Refund reversals (ADR 0028) Stripe confirmed on this payout's transfer. */
    private static function refundReversedCents(MerchantPayout $row): int
    {
        return (int) CorrectionAction::where('kind', CorrectionAction::KIND_TRANSFER_REVERSAL)->where('merchant_payout_id', $row->id)
            ->where('status', CorrectionAction::STATUS_SUCCEEDED)->sum('amount_cents');
    }

    /**
     * Every refund reversal (ADR 0028) against the reversals Stripe lists on
     * our transfers: each one recorded here must be there with the same
     * amount, and each one of ours Stripe has must be recorded here.
     *
     * @return list<string>
     */
    private function compareReversals($transfers): array
    {
        $out = [];
        $stripe = $transfers->flatMap(fn ($tr) => collect($tr['reversals']['data'] ?? [])->map(fn ($r) => $r + ['transfer' => $tr['id']]))
            ->filter(fn ($r) => isset($r['metadata']['lg_correction_key']))->keyBy('id');
        $actions = CorrectionAction::where('kind', CorrectionAction::KIND_TRANSFER_REVERSAL)->with('payout.merchant')->get();

        foreach ($actions->where('status', CorrectionAction::STATUS_SUCCEEDED) as $action) {
            $reversal = $stripe->get($action->stripe_id);
            if ($reversal === null) {
                $out[] = 'Local Goods recorded refund reversal '.$action->stripe_id.' of '.Money::format($action->amount_cents).' on transfer '.$action->payout->stripe_transfer_id
                    .' to '.$action->payout->merchant->name.', but Stripe shows no such reversal since the last reset.';
            } elseif ($reversal['amount'] !== $action->amount_cents) {
                $out[] = 'Refund reversal '.$action->stripe_id.' is '.Money::format($action->amount_cents).' in the ledger but '.Money::format($reversal['amount']).' in Stripe.';
            }
        }

        $byKey = $actions->keyBy('idempotency_key');
        foreach ($stripe->except($actions->where('status', CorrectionAction::STATUS_SUCCEEDED)->pluck('stripe_id')->all()) as $reversal) {
            $out[] = $byKey->has($reversal['metadata']['lg_correction_key']) || $actions->firstWhere('stripe_id', $reversal['id'])
                ? 'Stripe reversed '.Money::format($reversal['amount']).' of transfer '.$reversal['transfer'].' for a refund ('.$reversal['id'].'), but Local Goods hasn\'t recorded it yet: the webhook has not arrived. Check the webhook endpoint in Stripe.'
                : 'Stripe has a '.Money::format($reversal['amount']).' refund reversal ('.$reversal['id'].') on transfer '.$reversal['transfer'].' that Local Goods has no record of.';
        }

        return $out;
    }

    /**
     * Card refunds to employers (ADR 0028), by id and amount, both ways.
     *
     * @return list<string>
     */
    private function compareRefunds($refunds): array
    {
        $out = [];
        $actions = CorrectionAction::where('kind', CorrectionAction::KIND_CARD_REFUND)->with('creditPurchase')->get();

        foreach ($actions->where('status', CorrectionAction::STATUS_SUCCEEDED) as $action) {
            $refund = $refunds->get($action->stripe_id);
            if ($refund === null) {
                $out[] = 'Local Goods recorded card refund '.$action->stripe_id.' of '.Money::format($action->amount_cents).' on '.$action->creditPurchase->reference.', but Stripe shows no such refund since the last reset.';
            } elseif ($refund['amount'] !== $action->amount_cents) {
                $out[] = 'Card refund '.$action->stripe_id.' is '.Money::format($action->amount_cents).' in the ledger but '.Money::format($refund['amount']).' in Stripe.';
            } elseif (($refund['status'] ?? null) !== 'succeeded') {
                $out[] = 'Local Goods counts card refund '.$action->stripe_id.' as done, but Stripe shows it as '.($refund['status'] ?? 'unknown').'.';
            }
        }

        $byKey = $actions->keyBy('idempotency_key');
        foreach ($refunds->where('status', 'succeeded')->except($actions->where('status', CorrectionAction::STATUS_SUCCEEDED)->pluck('stripe_id')->all()) as $refund) {
            $out[] = $byKey->has($refund['metadata']['lg_correction_key']) || $actions->firstWhere('stripe_id', $refund['id'])
                ? 'Stripe refunded '.Money::format($refund['amount']).' ('.$refund['id'].'), but Local Goods hasn\'t recorded it yet: the webhook has not arrived. Check the webhook endpoint in Stripe.'
                : 'Stripe has a '.Money::format($refund['amount']).' refund ('.$refund['id'].') that Local Goods has no record of.';
        }

        return $out;
    }

    /** " (credits $X + platform fees $Y)", or nothing while no Stripe-paid purchase has a fee. */
    private static function feeSplit(array $local): string
    {
        return $local['fee_cents'] > 0
            ? ' (credits '.Money::format($local['credit_cents']).' + platform fees '.Money::format($local['fee_cents']).')'
            : '';
    }

    /** Made by this environment: tagged for it, or untagged (before tagging) but already known to this database. */
    private function ourPayment(array $pi): bool
    {
        return match (StripeEnvironment::of($pi['metadata'] ?? null)) {
            StripeEnvironment::SAME => true,
            StripeEnvironment::OTHER => false,
            default => CreditPurchase::where('stripe_payment_intent_id', $pi['id'])
                ->orWhere('payment_token', $pi['metadata']['lg_purchase_token'])->exists(),
        };
    }

    private function ourTransfer(array $tr): bool
    {
        return match (StripeEnvironment::of($tr['metadata'] ?? null)) {
            StripeEnvironment::SAME => true,
            StripeEnvironment::OTHER => false,
            default => MerchantPayout::stripe()->where(fn ($q) => $q->where('stripe_transfer_id', $tr['id'])
                ->orWhere('idempotency_key', $tr['metadata']['lg_payout_key']))->exists(),
        };
    }

    /** Made by this environment: tagged for it, or untagged but already known to this database. */
    private function ourRefund(array $refund): bool
    {
        return match (StripeEnvironment::of($refund['metadata'] ?? null)) {
            StripeEnvironment::SAME => true,
            StripeEnvironment::OTHER => false,
            default => CorrectionAction::where('stripe_id', $refund['id'])->orWhere('idempotency_key', $refund['metadata']['lg_correction_key'])->exists(),
        };
    }

    /** The "From another environment, not counted" note: totals, then one line per object. */
    private function otherEnvironments($intents, $transfers, $refunds): array
    {
        $lines = [];

        foreach ($intents as $pi) {
            $amount = Money::format($pi['amount_received'] ?? $pi['amount']);
            $lines[] = StripeEnvironment::of($pi['metadata'] ?? null) === StripeEnvironment::OTHER
                ? 'A '.$amount.' payment ('.$pi['id'].') made by another environment ('.StripeEnvironment::label($pi['metadata']).').'
                : 'A '.$amount.' payment ('.$pi['id'].') with no environment tag (made before tagging) and no record here. Most likely another environment\'s; if it was made from this one, check before refunding.';
        }

        foreach ($transfers as $tr) {
            $amount = Money::format($tr['amount'] - ($tr['amount_reversed'] ?? 0));
            $to = ' to '.($tr['destination'] ?? 'a connected account');
            $lines[] = StripeEnvironment::of($tr['metadata'] ?? null) === StripeEnvironment::OTHER
                ? 'A '.$amount.' transfer ('.$tr['id'].')'.$to.' made by another environment ('.StripeEnvironment::label($tr['metadata']).').'
                : 'A '.$amount.' transfer ('.$tr['id'].')'.$to.' with no environment tag (made before tagging) and no record here.';
        }

        foreach ($refunds as $refund) {
            $lines[] = 'A '.Money::format($refund['amount']).' refund ('.$refund['id'].') made by another environment ('.StripeEnvironment::label($refund['metadata']).').';
        }

        return [
            'refunds' => $refunds->count(),
            'refund_cents' => (int) $refunds->sum('amount'),
            'payments' => $intents->count(),
            'payment_cents' => (int) $intents->sum(fn ($pi) => $pi['amount_received'] ?? $pi['amount']),
            'transfers' => $transfers->count(),
            'transfer_cents' => (int) $transfers->sum(fn ($tr) => $tr['amount'] - ($tr['amount_reversed'] ?? 0)),
            'lines' => $lines,
        ];
    }

    private static function usd(array $amounts): int
    {
        return (int) collect($amounts)->where('currency', 'usd')->sum('amount');
    }
}
