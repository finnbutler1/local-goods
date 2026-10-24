<?php

namespace App\Http\Controllers\BidAdmin;

use App\Enums\DenialReason;
use App\Exceptions\RedemptionAlreadyReviewed;
use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Services\RedemptionReviewService;
use App\Support\Money;
use App\Support\PayoutOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Approve and Deny for redemptions held by Redemption Fraud Detection
 * (ADR 0016). Both need a short reason; Deny also says why, which decides
 * where the credit goes. Recorded as the signed-in user. Only BID admins and
 * the platform admin may decide (RedemptionPolicy); judges get 403.
 */
class ReviewRedemptionController extends Controller
{
    public function __construct(private RedemptionReviewService $reviews) {}

    public function approve(Request $request, Redemption $redemption): RedirectResponse
    {
        Gate::authorize('review', $redemption);
        $data = $request->validateWithBag("review{$redemption->id}", $this->reasonRules());

        return $this->decide($redemption, fn () => $this->reviews->approve($redemption, $request->user(), $data['reason']),
            fn ($r) => 'Approved '.Money::format($r->amount_cents)." at {$r->merchant->name} for {$r->employee->initials()} ".PayoutOutcome::sentence($r));
    }

    public function deny(Request $request, Redemption $redemption): RedirectResponse
    {
        Gate::authorize('review', $redemption);
        $data = $request->validateWithBag("review{$redemption->id}", $this->reasonRules() + [
            'denial' => ['required', Rule::enum(DenialReason::class)],
        ], ['denial.required' => 'Choose why it is denied: that decides where the credit goes.']);
        $why = DenialReason::from($data['denial']);

        return $this->decide($redemption, fn () => $this->reviews->deny($redemption, $request->user(), $data['reason'], $why),
            fn ($r) => 'Denied '.Money::format($r->amount_cents)." at {$r->merchant->name} for {$r->employee->initials()} No transfer; the credit is back with the {$why->returnsTo()}.");
    }

    /** @return array<string, list<string>> */
    private function reasonRules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    private function decide(Redemption $redemption, callable $action, callable $message): RedirectResponse
    {
        $back = redirect()->to(route('bid-admin.dashboard')."#redemption-{$redemption->id}");

        try {
            $action();
        } catch (RedemptionAlreadyReviewed $e) {
            $done = $e->redemption->isUnderReview() ? 'not under review' : 'already '.match (true) {
                $e->redemption->isCancelled() => 'cancelled at the counter',
                $e->redemption->isRejected() => 'denied',
                default => $e->redemption->status,
            };

            return $back->with('error', 'Nothing changed: this redemption is '.str_replace('_', ' ', $done).'.');
        }

        return $back->with('status', $message($redemption->refresh()->load('merchant', 'employee')));
    }
}
