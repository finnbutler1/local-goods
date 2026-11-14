<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\BidProgramFeePayment;
use App\Models\District;
use App\Services\Districts\DistrictProgramSettings;
use App\Services\Payments\BidProgramFeeCheckout;
use App\Services\Payments\BidProgramFees;
use App\Services\Payments\StripeGatewayException;
use App\Support\BidProgramFee;
use App\Support\MerchantFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The BID annual program fee (ADR 0044): the district's tier, this program
 * year's state and the payment history. A BID admin pays through Stripe's
 * hosted Checkout while the fee is switched on in Stripe test mode; the
 * amount is the tier's, set on the server. The platform admin sets the
 * district's member businesses and start date (with an audit row) and may
 * write a note on a payment; nobody refunds it in the app. Judges read the
 * page in words, without amounts or buttons.
 */
class ProgramFeeController extends Controller
{
    public function index(Request $request): View
    {
        $district = District::default();
        $user = $request->user();
        $year = BidProgramFee::programYear($district);
        $current = $year ? BidProgramFeePayment::where('fee_key', BidProgramFee::key($district, $year['year']))->first() : null;
        $setsDistrict = Gate::allows('set-district-program');

        return view('bid-admin.program-fee', [
            'district' => $district,
            'setUp' => BidProgramFee::isSetUp($district),
            'tier' => $district->member_businesses !== null ? BidProgramFee::tierFor($district->member_businesses) : null,
            'year' => $year,
            'current' => $current,
            'payments' => $district->programFeePayments()->with('noteBy')->orderByDesc('program_year')->get(),
            'figures' => MerchantFee::showsFiguresTo($user),
            'charges' => BidProgramFee::charges(),
            'canPay' => BidProgramFee::charges() && Gate::allows('pay-bid-program-fee'),
            'setsDistrict' => $setsDistrict,
            'writesNotes' => Gate::allows('note-bid-program-fee'),
            'changes' => $setsDistrict ? $district->programChanges()->with('changedBy')->get() : collect(),
            'returned' => $request->query('checkout'),
        ]);
    }

    /** Opens Stripe's Checkout for this program year. The price is the tier's; nothing in the request sets it. */
    public function pay(Request $request, BidProgramFees $fees, BidProgramFeeCheckout $checkout): RedirectResponse
    {
        Gate::authorize('pay-bid-program-fee');
        abort_unless(BidProgramFee::charges(), 404);

        try {
            $payment = $fees->request(District::default(), $request->user());

            return redirect()->away($checkout->start($payment));
        } catch (InvalidArgumentException|StripeGatewayException $e) {
            return redirect()->route('bid-admin.program-fee')->with('error', 'Payment didn\'t start: '.$e->getMessage().' No charge was made.');
        }
    }

    /** The platform admin sets the district's member businesses and program start date, with a reason (audit row). */
    public function district(Request $request, DistrictProgramSettings $settings): RedirectResponse
    {
        Gate::authorize('set-district-program');
        $data = $request->validate([
            'member_businesses' => ['required', 'integer', 'min:1', 'max:'.DistrictProgramSettings::MAX_MEMBER_BUSINESSES],
            'program_starts_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $settings->change($request->user(), District::default(), (int) $data['member_businesses'], $data['program_starts_on'], $data['reason']);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('bid-admin.program-fee')->with('error', $e->getMessage());
        }

        return redirect()->route('bid-admin.program-fee')->with('status', 'District details saved and logged with your initials.');
    }

    /** A platform admin's note on a payment. Moves no money. */
    public function note(Request $request, BidProgramFeePayment $payment, BidProgramFees $fees): RedirectResponse
    {
        Gate::authorize('note-bid-program-fee');
        $data = $request->validate(['note' => ['required', 'string', 'min:2', 'max:2000']]);

        $fees->note($payment, $request->user(), $data['note']);

        return redirect()->route('bid-admin.program-fee')->with('status', 'Note saved on program year '.$payment->program_year.'. No money moved.');
    }
}
