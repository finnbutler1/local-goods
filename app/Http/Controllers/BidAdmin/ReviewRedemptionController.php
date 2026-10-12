<?php

namespace App\Http\Controllers\BidAdmin;

use App\Exceptions\RedemptionAlreadyReviewed;
use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Services\RedemptionReviewService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;

/**
 * Approve and Reject buttons for redemptions held by Redemption Fraud
 * Detection. Until login exists, every decision is made as the demo BID admin.
 */
class ReviewRedemptionController extends Controller
{
    public function __construct(private RedemptionReviewService $reviews) {}

    public function approve(Redemption $redemption): RedirectResponse
    {
        return $this->decide($redemption, 'approve',
            fn ($r) => 'Approved '.Money::format($r->amount_cents)." at {$r->merchant->name}. The reserved credits are spent.");
    }

    public function reject(Redemption $redemption): RedirectResponse
    {
        return $this->decide($redemption, 'reject',
            fn ($r) => 'Rejected '.Money::format($r->amount_cents)." at {$r->merchant->name}. The credits are back in the employee's balance.");
    }

    private function decide(Redemption $redemption, string $action, callable $message): RedirectResponse
    {
        $back = redirect()->to(route('bid-admin.dashboard')."#redemption-{$redemption->id}");

        try {
            $this->reviews->{$action}($redemption, BidAdminController::DEMO_ADMIN);
        } catch (RedemptionAlreadyReviewed $e) {
            $done = $e->redemption->isUnderReview() ? 'not under review' : "already {$e->redemption->status}";

            return $back->with('error', 'Nothing changed: this redemption is '.str_replace('_', ' ', $done).'.');
        }

        return $back->with('status', $message($redemption->load('merchant')));
    }
}
