<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Partnership;
use App\Services\Districts\PartnershipRevenueShare;
use App\Services\Districts\PartnershipSettings;
use App\Support\MerchantFee;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The BID partnership (ADR 0031): the record, its change log and what the
 * revenue share would come to. Judges read it; a BID admin or the
 * platform admin changes it, in two steps (review writes nothing, then
 * confirm). Judges count as the public, so they see the revenue share in
 * words, without figures (as with fees, ADR 0029).
 */
class PartnershipController extends Controller
{
    public function index(PartnershipRevenueShare $share): View
    {
        return $this->page($share);
    }

    /** Step 1: check the new terms and show what changes. Writes nothing. */
    public function review(Request $request, PartnershipSettings $settings, PartnershipRevenueShare $share): View
    {
        Gate::authorize('change-partnership');
        $partnership = $this->partnership();
        [$terms, $reason] = $this->validated($request);

        try {
            $terms = $settings->check($terms, $partnership->status, $reason);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['terms' => $e->getMessage()]);
        }

        return $this->page($share, ['review' => ['terms' => $terms, 'reason' => $reason]]);
    }

    /** Step 2: the confirmed change, against the version that was reviewed. */
    public function store(Request $request, PartnershipSettings $settings): RedirectResponse
    {
        Gate::authorize('change-partnership');
        $partnership = $this->partnership();
        $request->validate([
            'reviewed_change_id' => ['required', 'integer', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the change.']);
        [$terms, $reason] = $this->validated($request, bps: true);

        try {
            $change = $settings->change($request->user(), $partnership, $terms, $reason, $request->integer('reviewed_change_id'));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('bid-admin.partnership')->with('error', $e->getMessage());
        }

        $what = implode(', ', array_map(fn ($term) => self::TERM_LABELS[$term], $change->changedTerms()));

        return redirect()->route('bid-admin.partnership')->with('status', "Partnership updated: {$what}. Logged with your initials.");
    }

    public const TERM_LABELS = [
        'status' => 'status', 'exclusive' => 'exclusivity', 'starts_on' => 'start date', 'ends_on' => 'end date',
        'revenue_share_bps' => 'revenue share', 'data_terms' => 'data terms',
    ];

    /** @return array{0: array<string, mixed>, 1: string} */
    private function validated(Request $request, bool $bps = false): array
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Partnership::STATUSES)],
            'exclusive' => ['nullable', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'revenue_share' => $bps ? ['nullable'] : ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'revenue_share_bps' => $bps ? ['required', 'integer', 'min:0', 'max:'.Partnership::MAX_REVENUE_SHARE_BPS] : ['nullable'],
            'data_terms' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['revenue_share.regex' => 'Enter a percent like 10 or 12.5 (0 for none).']);

        $shareBps = $bps ? (int) $data['revenue_share_bps'] : Money::toCents($data['revenue_share']);
        if ($shareBps > Partnership::MAX_REVENUE_SHARE_BPS) {
            throw ValidationException::withMessages(['revenue_share' => 'The revenue share can be at most '.Partnership::percent(Partnership::MAX_REVENUE_SHARE_BPS).'.']);
        }

        return [[
            'status' => $data['status'], 'exclusive' => (bool) ($data['exclusive'] ?? false), 'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null, 'revenue_share_bps' => $shareBps, 'data_terms' => $data['data_terms'],
        ], trim($data['reason'])];
    }

    private function partnership(): Partnership
    {
        return Partnership::forDistrict(District::default()) ?? abort(404);
    }

    private function page(PartnershipRevenueShare $share, array $extra = []): View
    {
        $district = District::default();
        $partnership = Partnership::forDistrict($district);
        $figures = MerchantFee::showsFiguresTo(auth()->user());

        return view('bid-admin.partnership', $extra + [
            'district' => $district,
            'partnership' => $partnership?->load('bid'),
            'changes' => $partnership?->changes()->with('changedBy')->get() ?? collect(),
            'change' => Gate::allows('change-partnership') && $partnership !== null,
            'figures' => $figures,
            'month' => $partnership && $figures ? $share->thisMonth($partnership) : null,
            'since' => $partnership && $figures ? $share->sinceStart($partnership) : null,
            'review' => null,
        ]);
    }
}
