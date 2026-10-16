<?php

namespace App\Services\Payments;

use App\Models\Bid;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Support\Money;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Carbon;

/**
 * Puts the ledger next to Stripe for Stripe-funded activity only (ADR 0015):
 * purchases paid in Stripe test mode against Stripe's payments, Stripe-
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
            ],
            'environment' => StripeEnvironment::label(),
            'error' => null,
            'mismatches' => [],
            'notes' => [],
            'other' => ['payments' => 0, 'payment_cents' => 0, 'transfers' => 0, 'transfer_cents' => 0, 'lines' => []],
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
            $balance = $this->stripe->retrieveBalance();
        } catch (StripeGatewayException $e) {
            $report['error'] = $e->getMessage();

            return $report;
        }

        [$intents, $otherIntents] = $intents->partition(fn ($pi) => $this->ourPayment($pi));
        [$transfers, $otherTransfers] = $transfers->partition(fn ($tr) => $this->ourTransfer($tr));
        $report['other'] = $this->otherEnvironments($otherIntents, $otherTransfers);

        $report['stripe'] = [
            'purchase_cents' => (int) $intents->sum(fn ($pi) => $pi['amount_received'] ?? $pi['amount']),
            'purchases' => $intents->count(),
            'transfer_cents' => (int) $transfers->sum(fn ($tr) => $tr['amount'] - ($tr['amount_reversed'] ?? 0)),
            'transfers' => $transfers->count(),
            'balance_cents' => self::usd($balance['available'] ?? []) + self::usd($balance['pending'] ?? []),
            'available_cents' => self::usd($balance['available'] ?? []),
        ];

        $report['mismatches'] = [...$this->comparePurchases($intents), ...$this->compareTransfers($transfers)];

        if ($local['purchase_cents'] !== $report['stripe']['purchase_cents']) {
            $report['mismatches'][] = 'Purchases: the ledger counts '.Money::format($local['purchase_cents']).' paid through Stripe; Stripe received '
                .Money::format($report['stripe']['purchase_cents']).'. Difference: '.Money::format($report['stripe']['purchase_cents'] - $local['purchase_cents']).'.';
        }
        if ($local['transfer_cents'] !== $report['stripe']['transfer_cents']) {
            $report['mismatches'][] = 'Transfers: the ledger counts '.Money::format($local['transfer_cents']).' sent to merchants through Stripe; Stripe shows '
                .Money::format($report['stripe']['transfer_cents']).'. Difference: '.Money::format($report['stripe']['transfer_cents'] - $local['transfer_cents']).'.';
        }

        $expected = $local['purchase_cents'] - $local['transfer_cents'];
        $report['expected_balance_cents'] = $expected;
        if ($report['stripe']['balance_cents'] !== $expected) {
            $report['notes'][] = 'Stripe\'s platform balance ('.Money::format($report['stripe']['balance_cents']).') differs from Stripe-paid purchases minus transfers ('
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

        return $report;
    }

    /** @return array{purchase_cents: int, purchases: int, transfer_cents: int, transfers: int} */
    private function localTotals(): array
    {
        $paid = CreditPurchase::where('status', CreditPurchase::STATUS_PAID);
        $sent = MerchantPayout::where('status', MerchantPayout::STATUS_SENT);

        return [
            'purchase_cents' => (int) (clone $paid)->sum('amount_cents'),
            'purchases' => (clone $paid)->count(),
            'transfer_cents' => (int) (clone $sent)->sum('amount_cents'),
            'transfers' => (clone $sent)->count(),
        ];
    }

    /** @return list<string> */
    private function comparePurchases($intents): array
    {
        $out = [];
        $paid = CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->get();

        foreach ($paid as $purchase) {
            $pi = $intents->get($purchase->stripe_payment_intent_id);
            if ($pi === null) {
                $out[] = 'Local Goods counts '.$purchase->reference.' ('.Money::format($purchase->amount_cents).') as paid through Stripe ('
                    .$purchase->stripe_payment_intent_id.'), but Stripe has no such successful payment since the last reset.';
            } elseif (($pi['amount_received'] ?? $pi['amount']) !== $purchase->amount_cents) {
                $out[] = $purchase->reference.' is '.Money::format($purchase->amount_cents).' in the ledger but Stripe received '
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
            } elseif (($tr['reversed'] ?? false) && $row->status === MerchantPayout::STATUS_SENT) {
                $out[] = 'Stripe shows transfer '.$tr['id'].' to '.$row->merchant->name.' as reversed, but Local Goods still counts it as paid.';
            }
        }

        $known = $rows->pluck('stripe_transfer_id')->filter()->all();
        foreach ($transfers->except($known) as $tr) {
            $out[] = 'Stripe has a '.Money::format($tr['amount']).' transfer ('.$tr['id'].') to '.($tr['destination'] ?? 'a connected account').' that Local Goods has no record of.';
        }

        return $out;
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

    /** The "From another environment, not counted" note: totals, then one line per object. */
    private function otherEnvironments($intents, $transfers): array
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

        return [
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
