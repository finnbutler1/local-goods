<?php

namespace App\Services\Payments;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\OperatingAccount;
use App\Models\OperatingSweep;
use App\Models\User;
use App\Services\Solvency\SolvencyGuard;
use App\Support\Money;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;

/**
 * The only way money Local Goods has earned leaves the customer funds
 * account (ADR 0034): a sweep to Local Goods Operating, decided by the
 * platform admin with a reason, against the amount they reviewed.
 *
 * Reserve, then send. The sweep row and its operating_sweep ledger line are
 * written inside SolvencyGuard::transaction, so the guard checks the
 * position with the sweep already in it: sweeping more than was earned
 * takes the platform account below zero, which is a shortfall in customer
 * funds, and everything rolls back. A Stripe sweep counts as Stripe money
 * out from that moment, so the Stripe floor is checked there too. Only then,
 * after commit, is Stripe asked. A definite refusal writes an
 * operating_sweep_returned line (the money is unswept again); a lost answer
 * stays pending with the same idempotency key, so a retry can't pay twice.
 *
 * In Stripe test mode only money earned on Stripe-paid activity is swept,
 * by a real transfer; money earned on simulated activity is never swept
 * with real money. With Stripe off, sweeps are simulated (ledger only).
 * Only ever sends to this environment's Local Goods Operating account,
 * never a merchant: that is MerchantPayouts' job.
 */
class OperatingSweeps
{
    private const LOCK = 'operating-sweep';

    public function __construct(private StripeGateway $stripe, private SolvencyGuard $solvency) {}

    /** stripe when Stripe test mode is on (a real transfer), otherwise simulated. */
    public static function channel(): string
    {
        return StripeMode::active() ? OperatingSweep::CHANNEL_STRIPE : OperatingSweep::CHANNEL_SIMULATED;
    }

    /** What may be swept now on this server's channel, in cents: never more than the platform account holds. */
    public function sweepable(): int
    {
        $platform = (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents');
        $stripe = $this->stripeEarnedUnswept();

        return max(0, self::channel() === OperatingSweep::CHANNEL_STRIPE ? min($platform, $stripe) : $platform - max(0, $stripe));
    }

    /**
     * Earned on Stripe-paid activity and not yet swept through Stripe:
     * platform fees (both kinds) on Stripe-paid purchases, merchant fees on sales paid
     * out through Stripe, the BID program fee (always Stripe), less what refunds gave back, less Stripe sweeps.
     */
    public function stripeEarnedUnswept(): int
    {
        $stripeRedemptions = MerchantPayout::where('status', '!=', MerchantPayout::STATUS_SIMULATED)->whereNotNull('redemption_id')->select('redemption_id');

        $earned = (int) CreditLedgerEntry::where('platform', true)->whereIn('type', CreditLedgerEntry::PURCHASE_FEE_TYPES)
            ->whereIn('credit_purchase_id', CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->select('id'))->sum('amount_cents')
            + (int) CreditLedgerEntry::where('platform', true)
                ->whereIn('type', [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED])
                ->whereIn('redemption_id', $stripeRedemptions)->sum('amount_cents')
            // The BID program fee (ADR 0044) is only ever written from a verified Stripe payment.
            + (int) CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_BID_PROGRAM_FEE)->sum('amount_cents');

        $swept = -(int) CreditLedgerEntry::whereIn('type', [CreditLedgerEntry::TYPE_OPERATING_SWEEP, CreditLedgerEntry::TYPE_OPERATING_SWEEP_RETURNED])
            ->whereIn('operating_sweep_id', OperatingSweep::where('channel', OperatingSweep::CHANNEL_STRIPE)->select('id'))->sum('amount_cents');

        return $earned - $swept;
    }

    /**
     * Reserves the sweep (ledger line, guarded), then sends it. Throws
     * InvalidArgumentException with a plain sentence when it can't be made;
     * SolvencyRefused if the guard refuses. Never throws for a Stripe failure:
     * that is written on the sweep.
     */
    public function sweep(User $by, int $cents, string $reason, int $reviewedAgainst): OperatingSweep
    {
        if (! $by->hasRole(Role::PlatformAdmin)) {
            throw new LogicException('Only a platform admin sweeps money to Local Goods Operating.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Write a reason of 5 to 500 characters. It is kept with the sweep.');
        }

        $channel = self::channel();
        $account = OperatingAccount::current();
        if ($channel === OperatingSweep::CHANNEL_STRIPE && ! $account?->transfers_active) {
            throw new InvalidArgumentException('Local Goods Operating is not set up in Stripe yet. Create it on Reconciliation first; nothing was swept.');
        }

        $sweep = Cache::lock(self::LOCK, 30)->block(10, fn () => $this->solvency->transaction('a sweep of '.Money::format($cents).' to Local Goods Operating',
            function () use ($by, $cents, $reason, $reviewedAgainst, $channel) {
                if (OperatingSweep::where('open_key', OperatingSweep::OPEN_KEY)->lockForUpdate()->exists()) {
                    throw new InvalidArgumentException('Another sweep is still being sent. Retry or wait for it on Reconciliation; nothing was swept.');
                }

                $sweepable = $this->sweepable();
                if ($reviewedAgainst !== $sweepable) {
                    throw new InvalidArgumentException('The amount that can be swept has changed since you reviewed it (now '.Money::format($sweepable).'). Review again; nothing was swept.');
                }
                if ($cents < 1 || $cents > $sweepable) {
                    throw new InvalidArgumentException('You can sweep between $0.01 and '.Money::format($sweepable).'. Nothing was swept.');
                }

                $stripe = $channel === OperatingSweep::CHANNEL_STRIPE;
                $sweep = OperatingSweep::create([
                    'amount_cents' => $cents, 'channel' => $channel,
                    'status' => $stripe ? OperatingSweep::STATUS_PENDING : OperatingSweep::STATUS_SIMULATED,
                    'open_key' => $stripe ? OperatingSweep::OPEN_KEY : null,
                    'reason' => $reason, 'reviewed_against_cents' => $reviewedAgainst, 'decided_by_user_id' => $by->id,
                    'swept_at' => $stripe ? null : now(),
                ]);
                CreditLedgerEntry::create([
                    'platform' => true, 'amount_cents' => -$cents, 'type' => CreditLedgerEntry::TYPE_OPERATING_SWEEP,
                    'operating_sweep_id' => $sweep->id, 'decided_by' => $by->id,
                    'description' => 'Swept to Local Goods Operating ('.($stripe ? 'Stripe test mode' : 'simulated, no real money').'): '.$reason,
                ]);

                return $sweep;
            }));

        return $sweep->isStripe() ? $this->send($sweep) : $sweep;
    }

    /** Sends (or re-sends, same key) a pending Stripe sweep. Never throws for a Stripe failure. */
    public function send(OperatingSweep $sweep): OperatingSweep
    {
        $sweep->refresh();
        if ($sweep->status !== OperatingSweep::STATUS_PENDING || ! StripeMode::active()) {
            return $sweep;
        }

        $destination = self::destination();
        $key = $sweep->idempotency_key ?? self::idempotencyKey($sweep);
        $sweep->update(['idempotency_key' => $key]);

        try {
            $transfer = $this->stripe->createTransfer([
                'amount' => $sweep->amount_cents,
                'currency' => config('services.stripe.currency'),
                'destination' => $destination,
                'transfer_group' => 'lg-sweep-'.$sweep->id,
                'description' => 'Local Goods: money Local Goods earned, swept to Local Goods Operating (test mode)',
                'metadata' => ['lg_sweep_key' => $key, 'lg_sweep_id' => (string) $sweep->id] + StripeEnvironment::tag(),
            ], $key);
        } catch (StripeGatewayException $e) {
            if (! $e->definite) {
                // We don't know whether Stripe made it: stays pending, same key next time.
                $sweep->update(['failure_reason' => $e->getMessage()]);

                return $sweep;
            }

            return $this->solvency->transaction('a sweep Stripe refused coming back', function () use ($sweep, $e) {
                $sweep->update(['status' => OperatingSweep::STATUS_FAILED, 'open_key' => null, 'attempts' => $sweep->attempts + 1, 'failure_reason' => $e->getMessage()]);
                CreditLedgerEntry::create([
                    'platform' => true, 'amount_cents' => $sweep->amount_cents, 'type' => CreditLedgerEntry::TYPE_OPERATING_SWEEP_RETURNED,
                    'operating_sweep_id' => $sweep->id, 'description' => 'Sweep refused by Stripe, so nothing left the customer funds account: '.$e->getMessage(),
                ]);

                return $sweep;
            });
        }

        $sweep->update(['status' => OperatingSweep::STATUS_SENT, 'open_key' => null, 'stripe_transfer_id' => $transfer['id'], 'swept_at' => now(), 'failure_reason' => null]);

        return $sweep;
    }

    /**
     * One key per sweep and attempt: this server, the sweep, when it was
     * made, and how many times Stripe definitely refused it.
     */
    public static function idempotencyKey(OperatingSweep $sweep): string
    {
        return 'lg-sweep-'.StripeEnvironment::instance().'-s'.$sweep->id.'-'.$sweep->created_at->getTimestamp().'-'.$sweep->attempts;
    }

    /**
     * Where a sweep may go: this environment's Local Goods Operating account,
     * and never an account a merchant uses. Refuses (LogicException) rather
     * than send anywhere else.
     */
    public static function destination(): string
    {
        $id = OperatingAccount::current()?->stripe_account_id
            ?? throw new LogicException('There is no Local Goods Operating account to sweep to.');

        if (Merchant::where('stripe_account_id', $id)->exists()) {
            throw new LogicException('Refusing to sweep: the operating account id belongs to a merchant.');
        }

        return $id;
    }
}
