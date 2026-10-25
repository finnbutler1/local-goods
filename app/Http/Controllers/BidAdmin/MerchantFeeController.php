<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\MerchantFeeChange;
use App\Services\MerchantFeeSettings;
use App\Support\MerchantFee;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Fee settings (ADR 0029): the merchant transaction fee and every change to
 * it. The BID admin and judges see it read-only; only the platform admin
 * changes it, in two steps (review writes nothing, then confirm). Staff by
 * initials only.
 */
class MerchantFeeController extends Controller
{
    /** The sale the review works the new fee out on. */
    public const EXAMPLE_CENTS = 633;

    public function index(): View
    {
        return $this->page();
    }

    /** Step 1: check the new fee and show what changes. Writes nothing. */
    public function review(Request $request): View
    {
        Gate::authorize('change-merchant-fee');
        [$bps, $fixed] = $this->validated($request);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason']);

        return $this->page(['review' => ['fee' => new MerchantFee($bps, $fixed), 'reason' => $reason]]);
    }

    /** Step 2: the confirmed change, against the setting that was reviewed. */
    public function store(Request $request, MerchantFeeSettings $settings): RedirectResponse
    {
        Gate::authorize('change-merchant-fee');
        $data = $request->validate([
            'bps' => ['required', 'integer', 'min:0', 'max:'.MerchantFee::MAX_BPS],
            'fixed_cents' => ['required', 'integer', 'min:0', 'max:'.MerchantFee::MAX_FIXED_CENTS],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'reviewed_change_id' => ['required', 'integer', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the change.']);

        $old = MerchantFee::current();

        try {
            $settings->change($request->user(), (int) $data['bps'], (int) $data['fixed_cents'], $data['reason'], (int) $data['reviewed_change_id']);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('bid-admin.fees')->with('error', $e->getMessage());
        }

        $new = MerchantFee::current();

        return redirect()->route('bid-admin.fees')->with('status', 'Local Goods fee changed from '.self::short($old).' to '.self::short($new).'. Applies to new redemptions only.');
    }

    public static function short(MerchantFee $fee): string
    {
        return $fee->isNone() ? 'none' : $fee->label();
    }

    /** @return array{0: int, 1: int} basis points and fixed cents */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'percent' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'fixed' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
        ], [
            'percent.regex' => 'Enter a percent like 2.5 (0 for none).',
            'fixed.regex' => 'Enter dollars like 0.10 (0 for none).',
        ]);

        $bps = Money::toCents($data['percent']);
        $fixed = Money::toCents($data['fixed']);

        if ($bps > MerchantFee::MAX_BPS) {
            throw ValidationException::withMessages(['percent' => 'The percent can be at most '.MerchantFee::percent(MerchantFee::MAX_BPS).'.']);
        }

        if ($fixed > MerchantFee::MAX_FIXED_CENTS) {
            throw ValidationException::withMessages(['fixed' => 'The fixed amount can be at most $1.00.']);
        }

        return [$bps, $fixed];
    }

    private function page(array $extra = []): View
    {
        return view('bid-admin.fees', $extra + [
            'current' => MerchantFee::current(),
            'changes' => MerchantFeeChange::with('changedBy')->latest('id')->get(),
            'change' => Gate::allows('change-merchant-fee'),
            'exampleCents' => self::EXAMPLE_CENTS,
            'review' => null,
        ]);
    }
}
