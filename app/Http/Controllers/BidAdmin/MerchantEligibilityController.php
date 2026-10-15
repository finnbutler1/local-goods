<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\MerchantReviewDecision;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantReviewQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The BID review queue for Merchant Eligibility Matching. Every business on
 * it is a real candidate, not a Local Goods participant. A BID admin
 * approves, rejects or asks for more research; each decision is a new row
 * in an append-only log. Approving never creates a merchant.
 *
 * Judges see it read-only. The shared public judge account sees totals
 * only, no business names (User::maySeeCandidateNames).
 */
class MerchantEligibilityController extends Controller
{
    public function index(Request $request, MerchantReviewQueue $queue): View
    {
        $items = $queue->items();

        return view('bid-admin.merchants', [
            'bid' => Bid::firstOrFail(),
            'items' => $request->user()->maySeeCandidateNames() ? $items : collect(),
            'totals' => $queue->totals($items),
            'showNames' => $request->user()->maySeeCandidateNames(),
        ]);
    }

    public function decide(Request $request, string $candidate, MerchantReviewQueue $queue): RedirectResponse
    {
        Gate::authorize('decide-merchant-eligibility');

        $assessment = $queue->assessmentFor($candidate);
        $facts = MerchantCandidates::load()[$candidate] ?? null;
        abort_if($assessment === null || $facts === null, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(MerchantReviewDecision::DECISIONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $decision = MerchantReviewDecision::create($data + [
            'candidate_id' => $candidate,
            'user_id' => $request->user()->id,
            'ai_classification' => $assessment['classification'],
            'ai_prompt_version' => $assessment['prompt_version'],
        ]);

        return redirect()->to(route('bid-admin.merchants').'#candidate-'.$candidate)
            ->with('status', "{$facts['name']}: {$decision->label()}. Recorded in the decision log. It is still a candidate, not a Local Goods participant.");
    }
}
