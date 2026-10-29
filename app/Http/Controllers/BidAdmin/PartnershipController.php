<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\Bid;
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

    /**
     * Step 1: check the new terms and show what changes. Writes nothing.
     * With no partnership yet, it starts one: always as a draft.
     */
    public function review(Request $request, PartnershipSettings $settings, PartnershipRevenueShare $share): View
    {
        Gate::authorize('change-partnership');
        $partnership = Partnership::forDistrict(District::default());
        [$terms, $reason] = $this->validated($request, start: $partnership === null);

        try {
            $terms = $settings->check($terms, $partnership?->status ?? Partnership::STATUS_DRAFT, $reason);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['terms' => $e->getMessage()]);
        }

        return $this->page($share, ['review' => ['terms' => $terms, 'reason' => $reason]]);
    }

    /** Step 2: the confirmed change, against the version that was reviewed (0 = no partnership yet). */
    public function store(Request $request, PartnershipSettings $settings): RedirectResponse
    {
        Gate::authorize('change-partnership');
        $district = District::default();
        $partnership = Partnership::forDistrict($district);
        $request->validate([
            'reviewed_change_id' => ['required', 'integer', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the change.']);
        $starting = $request->integer('reviewed_change_id') === 0;
        [$terms, $reason] = $this->validated($request, bps: true, start: $starting);

        if ($starting) {
            if ($partnership !== null) {
                return redirect()->route('bid-admin.partnership')->with('error', self::STARTED_MEANWHILE);
            }

            try {
                $settings->create($request->user(), Bid::orderBy('id')->firstOrFail(), $district, $terms, $reason);
            } catch (InvalidArgumentException $e) {
                return redirect()->route('bid-admin.partnership')->with('error', $e->getMessage());
            }

            return redirect()->route('bid-admin.partnership')->with('status', 'Partnership started as a draft. Logged with your initials; make it active once it is agreed.');
        }

        if ($partnership === null) {
            abort(404);
        }

        try {
            $change = $settings->change($request->user(), $partnership, $terms, $reason, $request->integer('reviewed_change_id'));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('bid-admin.partnership')->with('error', $e->getMessage());
        }

        $what = implode(', ', array_map(fn ($term) => self::TERM_LABELS[$term], $change->changedTerms()));

        return redirect()->route('bid-admin.partnership')->with('status', "Partnership updated: {$what}. Logged with your initials.");
    }

    public const STARTED_MEANWHILE = 'Someone started a partnership for this district since you reviewed this, so nothing was saved. Review it below.';

    public const TERM_LABELS = [
        'status' => 'status', 'exclusive' => 'exclusivity', 'starts_on' => 'start date', 'ends_on' => 'end date',
        'revenue_share_bps' => 'revenue share', 'data_terms' => 'data terms',
    ];

    /** @return array{0: array<string, mixed>, 1: string} */
    private function validated(Request $request, bool $bps = false, bool $start = false): array
    {
        if ($start) {
            // A new partnership always starts as a draft.
            $request->merge(['status' => Partnership::STATUS_DRAFT]);
        }

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

    private function page(PartnershipRevenueShare $share, array $extra = []): View
    {
        $district = District::default();
        $partnership = Partnership::forDistrict($district);
        $figures = MerchantFee::showsFiguresTo(auth()->user());

        return view('bid-admin.partnership', $extra + [
            'district' => $district,
            'partnership' => $partnership?->load('bid'),
            'changes' => $partnership?->changes()->with('changedBy')->get() ?? collect(),
            // May this person change (or start) it? Only they get the form; everyone else gets the read-only note.
            'change' => Gate::allows('change-partnership'),
            'figures' => $figures,
            'month' => $partnership && $figures ? $share->thisMonth($partnership) : null,
            'since' => $partnership && $figures ? $share->sinceStart($partnership) : null,
            'review' => null,
        ]);
    }
}
