<?php

namespace App\Http\Controllers\BidAdmin;

use App\Exceptions\RedemptionAlreadyReviewed;
use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Services\RedemptionReviewService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Approve and Reject buttons for redemptions held by Redemption Fraud
 * Detection. The decision is recorded as the signed-in user. Only BID admins
 * and the platform admin may decide (RedemptionPolicy); judges get 403.
 */
class ReviewRedemptionController extends Controller
{
    public function __construct(private RedemptionReviewService $reviews) {}

    public function approve(Request $request, Redemption $redemption): RedirectResponse
    {
        return $this->decide($request, $redemption, 'approve',
            fn ($r) => 'Approved '.Money::format($r->amount_cents)." at {$r->merchant->name}. The reserved credits are spent.");
    }

    public function reject(Request $request, Redemption $redemption): RedirectResponse
    {
        return $this->decide($request, $redemption, 'reject',
            fn ($r) => 'Rejected '.Money::format($r->amount_cents)." at {$r->merchant->name}. The credits are back in the employee's balance.");
    }

    private function decide(Request $request, Redemption $redemption, string $action, callable $message): RedirectResponse
    {
        Gate::authorize('review', $redemption);

        $back = redirect()->to(route('bid-admin.dashboard')."#redemption-{$redemption->id}");

        try {
            $this->reviews->{$action}($redemption, $request->user());
        } catch (RedemptionAlreadyReviewed $e) {
            $done = $e->redemption->isUnderReview() ? 'not under review' : "already {$e->redemption->status}";

            return $back->with('error', 'Nothing changed: this redemption is '.str_replace('_', ' ', $done).'.');
        }

        return $back->with('status', $message($redemption->load('merchant')));
    }
}
