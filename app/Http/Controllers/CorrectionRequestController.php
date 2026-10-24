<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Corrections\CorrectionRequests;
use App\Support\CorrectionReasons;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * "Request a correction" (ADR 0028): an employee or a merchant about a
 * cleared redemption, a merchant about a Stripe payout, HR about unspent
 * credits. Asking moves no money; a platform admin decides. Someone else's
 * item is a 404, so ids can't be probed. Errors go in their own bag with
 * the item's key, so only the form that was sent shows them.
 */
class CorrectionRequestController extends Controller
{
    public const ERRORS = 'correction';

    public function __construct(private CorrectionRequests $requests) {}

    public function employeeRedemption(Request $request, Redemption $redemption): RedirectResponse
    {
        abort_unless($request->user()->employee?->id === $redemption->employee_id, 404);

        return $this->ask($request, CorrectionReasons::EMPLOYEE_REDEMPTION, 'redemption:'.$redemption->id,
            fn ($reason, $note) => $this->requests->forRedemption($request->user(), $redemption, $reason, $note),
            'Refund requested for your '.Money::format($redemption->amount_cents).' at '.$redemption->merchant->name.'. A Local Goods admin will decide; the answer shows on it.',
            route('employee.wallet').'#recent-heading');
    }

    public function merchantRedemption(Request $request, Redemption $redemption): RedirectResponse
    {
        abort_unless($request->user()->merchant?->id === $redemption->merchant_id, 404);

        return $this->ask($request, CorrectionReasons::MERCHANT_REDEMPTION, 'redemption:'.$redemption->id,
            fn ($reason, $note) => $this->requests->forRedemption($request->user(), $redemption, $reason, $note),
            'Refund requested for '.$redemption->employee->initials().'\'s '.Money::format($redemption->amount_cents).'. A Local Goods admin will decide; the answer shows on it.',
            route('merchant.dashboard').'#received');
    }

    public function merchantPayout(Request $request, MerchantPayout $merchantPayout): RedirectResponse
    {
        abort_unless($request->user()->merchant?->id === $merchantPayout->merchant_id, 404);

        return $this->ask($request, CorrectionReasons::MERCHANT_PAYOUT, 'payout:'.$merchantPayout->id,
            fn ($reason, $note) => $this->requests->forPayout($request->user(), $merchantPayout, $reason, $note),
            'Question sent about your '.Money::format($merchantPayout->amount_cents).' payout. A Local Goods admin will check it with Stripe; the answer shows on it.',
            route('merchant.dashboard').'#payouts-heading');
    }

    public function employerCredits(Request $request): RedirectResponse
    {
        $employer = Employer::orderBy('id')->firstOrFail();

        return $this->ask($request, CorrectionReasons::EMPLOYER_CREDITS, 'employer:'.$employer->id,
            fn ($reason, $note) => $this->requests->forEmployer($request->user(), $employer, $reason, $note),
            'Refund of unspent credits requested for '.$employer->name.'. A Local Goods admin will decide; the answer shows here.',
            route('employer.dashboard').'#refund-credits');
    }

    private function ask(Request $request, string $context, string $for, callable $make, string $done, string $back): RedirectResponse
    {
        Gate::authorize('request-corrections');

        $data = $request->validateWithBag(self::ERRORS, [
            'reason' => ['required', Rule::in(array_keys(CorrectionReasons::for($context)))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reason.required' => 'Choose what went wrong.', 'reason.in' => 'Choose what went wrong.', 'note.max' => 'Keep the note to 500 characters.']);

        try {
            $make($data['reason'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return redirect()->to($back)->withInput(['correction_for' => $for])->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('status', $done);
    }
}
