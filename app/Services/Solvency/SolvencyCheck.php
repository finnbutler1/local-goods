<?php

namespace App\Services\Solvency;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry as E;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\OperatingSweep;
use Illuminate\Support\Facades\DB;

/**
 * The solvency rule (ADR 0033), read from the ledger. now() is the cheap
 * check of the current position (a handful of aggregate queries), used
 * after every money operation. history() replays every ledger line in
 * time order and checks the rule after each moment, so a moment of
 * insolvency in the past can't be hidden by a later correction.
 * recordsAgree() checks the ledger against the purchase, payout and
 * refund records it was written from. Read-only: never writes anything.
 */
class SolvencyCheck
{
    public function now(): SolvencyPosition
    {
        $byType = DB::table('credit_ledger_entries')->groupBy('type')->selectRaw('type, SUM(amount_cents) as total')
            ->pluck('total', 'type')->map(fn ($v) => (int) $v);
        $sum = fn (string ...$types) => array_sum(array_map(fn ($t) => $byType[$t] ?? 0, $types));

        $negatives = [];
        $negativeCents = 0;
        $accounts = [];
        foreach ([LedgerTranches::EMPLOYEE, LedgerTranches::EMPLOYER, LedgerTranches::MERCHANT] as $kind) {
            $column = LedgerTranches::ACCOUNT_COLUMNS[$kind];
            $accounts[$kind] = 0;
            foreach (DB::table('credit_ledger_entries')->whereNotNull($column)->groupBy($column)
                ->havingRaw('SUM(amount_cents) <> 0')->selectRaw("{$column} as id, SUM(amount_cents) as balance")->get() as $row) {
                $balance = (int) $row->balance;
                if ($balance > 0) {
                    $accounts[$kind] += $balance;
                } else {
                    $negatives[] = $kind.':'.$row->id;
                    $negativeCents += $balance;
                }
            }
        }
        $platform = (int) DB::table('credit_ledger_entries')->where('platform', true)->sum('amount_cents');
        if ($platform < 0) {
            $negatives[] = LedgerTranches::PLATFORM;
            $negativeCents += $platform;
        }

        // A redemption is held while its own line has no clearing or denial line yet.
        $held = DB::table('credit_ledger_entries')->whereNotNull('redemption_id')
            ->whereIn('type', [LedgerTranches::HOLDS_REDEMPTION, ...LedgerTranches::RESOLVES_HELD])
            ->groupBy('redemption_id')->havingRaw('SUM(amount_cents) < 0')
            ->selectRaw('-SUM(amount_cents) as held')->pluck('held')->map(fn ($v) => (int) $v);

        $refunded = $this->refundedByCorrection();
        $holds = DB::table('credit_ledger_entries')->whereNotNull('correction_id')->whereIn('type', LedgerTranches::REFUND_HOLD_TYPES)
            ->groupBy('correction_id')->selectRaw('correction_id, -SUM(amount_cents) as held')->pluck('held', 'correction_id')
            ->map(fn ($held, $id) => max(0, (int) $held - ($refunded[$id]['all'] ?? 0)))->filter();

        $stripeLines = DB::table('credit_ledger_entries as e')->join('merchant_payouts as p', 'p.id', '=', 'e.merchant_payout_id')
            ->where('p.status', '!=', MerchantPayout::STATUS_SIMULATED)
            ->whereIn('e.type', [E::TYPE_PAYOUT, E::TYPE_PAYOUT_REVERSED, E::TYPE_REFUND_REVERSAL])
            ->groupBy('e.type')->selectRaw('e.type, SUM(e.amount_cents) as total')->pluck('total', 'type')->map(fn ($v) => (int) $v);
        // Sweeps sent through Stripe (or reserved for it) are Stripe money out, like a transfer (ADR 0034).
        $stripeSwept = -(int) DB::table('credit_ledger_entries as e')->join('operating_sweeps as s', 's.id', '=', 'e.operating_sweep_id')
            ->where('s.channel', OperatingSweep::CHANNEL_STRIPE)->whereIn('e.type', LedgerTranches::SWEEP_TYPES)->sum('e.amount_cents');
        $stripeIn = (int) DB::table('credit_ledger_entries as e')->join('credit_purchases as c', 'c.id', '=', 'e.credit_purchase_id')
            ->where('c.status', CreditPurchase::STATUS_PAID)->whereIn('e.type', [E::TYPE_PURCHASE, E::TYPE_PLATFORM_FEE])->sum('e.amount_cents');

        return new SolvencyPosition(
            employerCents: $accounts[LedgerTranches::EMPLOYER],
            employeeCents: $accounts[LedgerTranches::EMPLOYEE],
            merchantCents: $accounts[LedgerTranches::MERCHANT],
            heldRedemptionsCents: (int) $held->sum(),
            heldRedemptions: $held->count(),
            refundHoldsCents: (int) $holds->sum(),
            refundHolds: $holds->count(),
            operatingCents: max(0, $platform),
            creditsPaidInCents: $sum(E::TYPE_PURCHASE),
            feesPaidInCents: $sum(E::TYPE_PLATFORM_FEE),
            paidOutCents: -$sum(E::TYPE_PAYOUT),
            pulledBackCents: $sum(E::TYPE_PAYOUT_REVERSED, E::TYPE_REFUND_REVERSAL),
            refundedCents: array_sum(array_column($refunded, 'all')),
            stripeInCents: $stripeIn,
            stripeOutCents: -($stripeLines[E::TYPE_PAYOUT] ?? 0) - ($stripeLines[E::TYPE_PAYOUT_REVERSED] ?? 0)
                - ($stripeLines[E::TYPE_REFUND_REVERSAL] ?? 0) + array_sum(array_column($refunded, 'stripe')) + $stripeSwept,
            negativeAccounts: $negatives,
            negativeCents: $negativeCents,
            sweptCents: -$sum(...LedgerTranches::SWEEP_TYPES),
        );
    }

    /**
     * Replays every ledger line in time order (created_at, then id) and
     * checks the rule after each moment: all lines with the same timestamp
     * are one moment, as one operation writes its lines together.
     */
    public function history(): SolvencyHistory
    {
        $started = hrtime(true);
        $stripePurchases = array_flip(DB::table('credit_purchases')->where('status', CreditPurchase::STATUS_PAID)->pluck('id')->all());
        $simulatedPayouts = array_flip(DB::table('merchant_payouts')->where('status', MerchantPayout::STATUS_SIMULATED)->pluck('id')->all());
        $stripeSweeps = array_flip(DB::table('operating_sweeps')->where('channel', OperatingSweep::CHANNEL_STRIPE)->pluck('id')->all());
        $refundActions = $this->refundActions();

        $balances = [];
        $gross = [LedgerTranches::EMPLOYEE => 0, LedgerTranches::EMPLOYER => 0, LedgerTranches::MERCHANT => 0, LedgerTranches::PLATFORM => 0];
        $negatives = [];
        $heldBy = [];
        $heldCents = 0;
        $holdBy = [];
        $holdCents = 0;
        $in = ['credits' => 0, 'fees' => 0, 'out' => 0, 'back' => 0, 'refunded' => 0, 'swept' => 0, 'stripe_in' => 0, 'stripe_out' => 0];

        $entries = 0;
        $moments = 0;
        $first = null;
        $moment = null;
        $windows = [];
        $open = null;
        $failedMoments = 0;

        $bump = function (array &$map, int|string $key, int $delta, int &$total): void {
            $old = $map[$key] ?? 0;
            $new = $old + $delta;
            $total += max(0, $new) - max(0, $old);
            $map[$key] = $new;
        };

        // A real closure with references: an arrow function would copy the totals once, when it is made.
        $position = function () use (&$gross, &$heldCents, &$heldBy, &$holdCents, &$holdBy, &$in, &$negatives) {
            return new SolvencyPosition(
                employerCents: $gross[LedgerTranches::EMPLOYER],
                employeeCents: $gross[LedgerTranches::EMPLOYEE],
                merchantCents: $gross[LedgerTranches::MERCHANT],
                heldRedemptionsCents: $heldCents,
                heldRedemptions: count(array_filter($heldBy, fn ($c) => $c > 0)),
                refundHoldsCents: $holdCents,
                refundHolds: count(array_filter($holdBy, fn ($c) => $c > 0)),
                operatingCents: $gross[LedgerTranches::PLATFORM],
                creditsPaidInCents: $in['credits'],
                feesPaidInCents: $in['fees'],
                paidOutCents: $in['out'],
                pulledBackCents: $in['back'],
                refundedCents: $in['refunded'],
                stripeInCents: $in['stripe_in'],
                stripeOutCents: $in['stripe_out'],
                negativeAccounts: array_keys($negatives),
                negativeCents: array_sum($negatives),
                sweptCents: $in['swept'],
            );
        };

        $check = function (string $at) use (&$windows, &$open, &$failedMoments, $position): void {
            $now = $position();
            if ($now->passes()) {
                if ($open !== null) {
                    $windows[] = $open + ['to' => $at];
                    $open = null;
                }

                return;
            }
            $failedMoments++;
            $worst = $now->shortfallCents() + $now->stripeShortfallCents() + $now->surplusCents();
            if ($open === null) {
                $open = ['from' => $at, 'problem' => $now->problem(), 'worst_cents' => $worst, 'worst_at' => $at, 'accounts' => $now->negativeAccounts];
            } elseif ($worst > $open['worst_cents']) {
                $open = ['worst_cents' => $worst, 'worst_at' => $at, 'problem' => $now->problem(), 'accounts' => $now->negativeAccounts] + $open;
            }
        };

        $lines = DB::table('credit_ledger_entries')->orderBy('created_at')->orderBy('id')
            ->select(['id', 'created_at', 'type', 'amount_cents', 'employee_id', 'employer_id', 'merchant_id', 'platform',
                'redemption_id', 'correction_id', 'credit_purchase_id', 'merchant_payout_id', 'operating_sweep_id'])->cursor();

        foreach ($lines as $line) {
            $at = (string) $line->created_at;
            if ($moment !== null && $at !== $moment) {
                $moments++;
                $check($moment);
            }
            $moment = $at;
            $first ??= $at;
            $entries++;

            $amount = (int) $line->amount_cents;
            $kind = match (true) {
                $line->employee_id !== null => LedgerTranches::EMPLOYEE,
                $line->employer_id !== null => LedgerTranches::EMPLOYER,
                $line->merchant_id !== null => LedgerTranches::MERCHANT,
                default => LedgerTranches::PLATFORM,
            };
            $key = $kind === LedgerTranches::PLATFORM ? $kind : $kind.':'.$line->{LedgerTranches::ACCOUNT_COLUMNS[$kind]};
            $bump($balances, $key, $amount, $gross[$kind]);
            if ($balances[$key] < 0) {
                $negatives[$key] = $balances[$key];
            } else {
                unset($negatives[$key]);
            }

            $type = $line->type;
            if ($line->redemption_id !== null && ($type === LedgerTranches::HOLDS_REDEMPTION || in_array($type, LedgerTranches::RESOLVES_HELD, true))) {
                $bump($heldBy, $line->redemption_id, -$amount, $heldCents);
            }
            if ($line->correction_id !== null && in_array($type, LedgerTranches::REFUND_HOLD_TYPES, true)) {
                $bump($holdBy, $line->correction_id, -$amount, $holdCents);
            }

            $stripePayout = $line->merchant_payout_id !== null && ! isset($simulatedPayouts[$line->merchant_payout_id]);
            match ($type) {
                E::TYPE_PURCHASE => $in['credits'] += $amount,
                E::TYPE_PLATFORM_FEE => $in['fees'] += $amount,
                E::TYPE_PAYOUT => $in['out'] -= $amount,
                E::TYPE_PAYOUT_REVERSED, E::TYPE_REFUND_REVERSAL => $in['back'] += $amount,
                E::TYPE_OPERATING_SWEEP, E::TYPE_OPERATING_SWEEP_RETURNED => $in['swept'] -= $amount,
                default => null,
            };
            if (in_array($type, LedgerTranches::SWEEP_TYPES, true) && isset($stripeSweeps[$line->operating_sweep_id])) {
                $in['stripe_out'] -= $amount;
            }
            if (in_array($type, [E::TYPE_PURCHASE, E::TYPE_PLATFORM_FEE], true) && isset($stripePurchases[$line->credit_purchase_id])) {
                $in['stripe_in'] += $amount;
            }
            if (in_array($type, [E::TYPE_PAYOUT, E::TYPE_PAYOUT_REVERSED, E::TYPE_REFUND_REVERSAL], true) && $stripePayout) {
                $in['stripe_out'] -= $amount;
            }
            if ($type === E::TYPE_EMPLOYER_REFUNDED && ($action = $refundActions[$line->correction_id.':'.$line->credit_purchase_id] ?? null) !== null) {
                $in['refunded'] += $action['amount'];
                $bump($holdBy, $line->correction_id, -$action['amount'], $holdCents);
                if ($action['stripe']) {
                    $in['stripe_out'] += $action['amount'];
                }
            }
        }

        if ($moment !== null) {
            $moments++;
            $check($moment);
        }
        if ($open !== null) {
            $windows[] = $open + ['to' => null];
        }

        return new SolvencyHistory(
            entries: $entries,
            moments: $moments,
            firstEntryAt: $first,
            lastEntryAt: $moment,
            final: $position(),
            windows: $windows,
            failedMoments: $failedMoments,
            mismatches: $this->recordsAgree(),
            durationMs: (int) round((hrtime(true) - $started) / 1e6),
        );
    }

    /**
     * The ledger next to the records it was written from: paid purchases,
     * payouts that went out, and employer refunds that went through. A
     * mismatch means a record moved money with no ledger line, or the other
     * way round. Returns one sentence per mismatch; empty when they agree.
     *
     * @return list<string>
     */
    public function recordsAgree(): array
    {
        $line = fn (string ...$types) => (int) DB::table('credit_ledger_entries')->whereIn('type', $types)->sum('amount_cents');
        $paid = CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES);
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $mismatches = [];

        $purchases = (int) (clone $paid)->sum('amount_cents') + (int) (clone $paid)->sum('platform_fee_cents');
        if ($purchases !== $line(E::TYPE_PURCHASE, E::TYPE_PLATFORM_FEE)) {
            $mismatches[] = 'Paid purchases come to '.$money($purchases).' but their ledger lines to '.$money($line(E::TYPE_PURCHASE, E::TYPE_PLATFORM_FEE)).'.';
        }

        // A payout reversed in the Stripe dashboard has its payout line and its payout_reversed line.
        $payouts = (int) MerchantPayout::paid()->sum('amount_cents');
        $payoutLines = -$line(E::TYPE_PAYOUT) - $line(E::TYPE_PAYOUT_REVERSED);
        if ($payouts !== $payoutLines) {
            $mismatches[] = 'Payouts sent or simulated come to '.$money($payouts).' but their ledger lines to '.$money($payoutLines).'.';
        }

        // A sweep's line is written when it is reserved; a refused one also has its returned line (ADR 0034).
        $sweeps = (int) OperatingSweep::whereIn('status', [OperatingSweep::STATUS_PENDING, ...OperatingSweep::DONE_STATUSES])->sum('amount_cents');
        $sweepLines = -$line(...LedgerTranches::SWEEP_TYPES);
        if ($sweeps !== $sweepLines) {
            $mismatches[] = 'Sweeps to Local Goods Operating come to '.$money($sweeps).' but their ledger lines to '.$money($sweepLines).'.';
        }

        $refunds = (int) CorrectionAction::where('status', CorrectionAction::STATUS_SUCCEEDED)->whereNotNull('credit_purchase_id')
            ->whereHas('correction', fn ($q) => $q->where('type', Correction::TYPE_EMPLOYER_REFUND))->sum('amount_cents');
        $refundLines = array_sum(array_column($this->refundedByCorrection(), 'all'));
        if ($refunds !== $refundLines) {
            $mismatches[] = 'Employer refunds that went through come to '.$money($refunds).' but the ledger records '.$money($refundLines).'.';
        }

        return $mismatches;
    }

    /** "merchant:3" → "Farragut Grind (merchant)"; employees only by initials and number. */
    public static function accountLabel(string $key): string
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);

        return match ($kind) {
            LedgerTranches::MERCHANT => (Merchant::find($id)?->name ?? 'Merchant #'.$id).' (merchant)',
            LedgerTranches::EMPLOYEE => (Employee::find($id)?->publicLabel() ?? '#'.$id).' (employee)',
            LedgerTranches::EMPLOYER => (Employer::find($id)?->name ?? 'Employer #'.$id).' (employer)',
            default => 'Local Goods platform account',
        };
    }

    /**
     * Employer refunds that went out, by correction: the employer_refunded
     * line (amount 0) is matched to its succeeded action, which holds the
     * amount. "stripe" is the part refunded to a card in test mode.
     *
     * @return array<int, array{all: int, stripe: int}>
     */
    private function refundedByCorrection(): array
    {
        $out = [];
        foreach ($this->refundActions() as $key => $action) {
            if (! $action['recorded']) {
                continue;
            }
            $id = (int) explode(':', $key)[0];
            $out[$id]['all'] = ($out[$id]['all'] ?? 0) + $action['amount'];
            $out[$id]['stripe'] = ($out[$id]['stripe'] ?? 0) + ($action['stripe'] ? $action['amount'] : 0);
        }

        return $out;
    }

    /**
     * Succeeded employer-refund actions keyed "correction:purchase" (one
     * action per payment per refund), and whether the ledger has its line.
     *
     * @return array<string, array{amount: int, stripe: bool, recorded: bool}>
     */
    private function refundActions(): array
    {
        $recorded = DB::table('credit_ledger_entries')->where('type', E::TYPE_EMPLOYER_REFUNDED)
            ->get(['correction_id', 'credit_purchase_id'])->mapWithKeys(fn ($r) => [$r->correction_id.':'.$r->credit_purchase_id => true]);

        return DB::table('correction_actions')->where('status', CorrectionAction::STATUS_SUCCEEDED)->whereNotNull('credit_purchase_id')
            ->get(['correction_id', 'credit_purchase_id', 'amount_cents', 'kind'])
            ->mapWithKeys(fn ($a) => [$a->correction_id.':'.$a->credit_purchase_id => [
                'amount' => (int) $a->amount_cents,
                'stripe' => $a->kind === CorrectionAction::KIND_CARD_REFUND,
                'recorded' => isset($recorded[$a->correction_id.':'.$a->credit_purchase_id]),
            ]])->all();
    }
}
