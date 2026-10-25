<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\BidImpactMetrics;
use App\Services\CompletedRefunds;
use App\Services\EmployerProgram;
use App\Support\StripeMode;
use Illuminate\View\View;

/**
 * The BID's view of the whole program: who takes part and how many dollars
 * stayed in the district. "Recirculated" counts cleared redemptions only,
 * net of refunds that completed (this month: completed this month; ADR 0028).
 * The merchant roster is the fictional participating merchants, never the
 * real candidates in the Merchant Eligibility Matching queue.
 */
class ParticipationController extends Controller
{
    public function __invoke(): View
    {
        [$monthStart] = EmployerProgram::month();
        $cleared = fn () => Redemption::where('status', Redemption::STATUS_APPROVED);

        $roster = Merchant::orderBy('name')
            ->withCount(['redemptions as month_visits' => fn ($q) => $q->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)])
            ->withSum(['redemptions as month_cents' => fn ($q) => $q->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)], 'amount_cents')
            ->withSum('ledgerEntries as owed_cents', 'amount_cents')
            ->withSum(['payouts as owed_stripe_cents' => fn ($q) => $q->whereIn('status', MerchantPayout::UNPAID_STRIPE_STATUSES)], 'amount_cents')
            ->withMax(['payouts as last_payout_at' => fn ($q) => $q->paid()], 'paid_at')
            ->get();
        // Completed refunds come off where they completed (CompletedRefunds); display only.
        $refunds = CompletedRefunds::rows($monthStart);
        $byMerchant = $refunds->groupBy('merchant_id')->map(fn ($rows) => (int) $rows->sum('cents'));
        $roster->each(function (Merchant $merchant) use ($byMerchant) {
            $merchant->month_refunded_cents = $byMerchant->get($merchant->id, 0);
            $merchant->month_cents = (int) $merchant->month_cents - $merchant->month_refunded_cents;
        });
        $monthRefunded = (int) $refunds->sum('cents');

        return view('bid-admin.participation', [
            'bid' => Bid::first(),
            'employers' => Employer::count(),
            'enrolled' => Employee::count(),
            'staff' => (int) Employer::sum('staff_count'),
            'activeEmployees' => $cleared()->where('created_at', '>=', $monthStart)->distinct()->count('employee_id'),
            'merchants' => $roster->count(),
            'monthCents' => (int) $cleared()->where('created_at', '>=', $monthStart)->sum('amount_cents') - $monthRefunded,
            'allTimeCents' => (int) $cleared()->sum('amount_cents') - BidImpactMetrics::refundedCents(),
            'monthRefundedCents' => $monthRefunded,
            'allTimeRefundedCents' => BidImpactMetrics::refundedCents(),
            'heldCents' => (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents'),
            'roster' => $roster,
            'stripe' => StripeMode::active(),
            'month' => now()->setTimezone(config('fraud.timezone')),
        ]);
    }
}
