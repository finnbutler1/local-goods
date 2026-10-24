<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Models\CreditPurchase;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Corrections\CorrectionLimits;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The Corrections queue (ADR 0028): requests to fix money mistakes and the
 * decisions on them. Only the platform admin decides (approve, reject,
 * retry, close), always with a written reason the requester sees; the BID
 * admin and judges see the same page read-only. People by initials, staff by
 * initials. Nothing here is ever sent to the AI.
 */
class CorrectionsController extends Controller
{
    public const DONE_SHOWN = 30;

    public function __construct(private CorrectionDecisions $decisions) {}

    public function index(): View
    {
        $with = ['redemption.employee', 'redemption.merchant', 'redemption.items', 'redemption.stripePayout', 'payout.merchant',
            'employer', 'employee', 'merchant', 'items.redemptionItem', 'actions.creditPurchase', 'actions.payout', 'decidedBy'];

        $open = Correction::with($with)->whereIn('status', Correction::OPEN_STATUSES)->orderBy('id')->get();
        $done = Correction::with($with)->whereNotIn('status', Correction::OPEN_STATUSES)->latest('updated_at')->latest('id')->take(self::DONE_SHOWN)->get();

        return view('bid-admin.corrections', [
            'open' => $open->map(fn (Correction $c) => ['correction' => $c] + $this->preview($c)),
            'done' => $done,
            'decide' => Gate::allows('decide-corrections'),
        ]);
    }

    /** What the admin needs to decide: what is left, and how it would be paid. */
    private function preview(Correction $correction): array
    {
        if ($correction->type === Correction::TYPE_REDEMPTION_REFUND) {
            $redemption = $correction->redemption;
            $refunded = CorrectionLimits::refundedQuantities($redemption, $correction->id);

            return [
                'left_cents' => $redemption->amount_cents - CorrectionLimits::redemptionRefundedCents($redemption, $correction->id),
                'path' => CorrectionLimits::refundPath($redemption),
                'items' => $redemption->items->map(fn ($item) => ['item' => $item, 'left' => $item->quantity - ($refunded[$item->id] ?? 0)]),
            ];
        }

        if ($correction->type === Correction::TYPE_EMPLOYER_REFUND) {
            $balance = max(0, $correction->employer->balanceCents());
            $purchases = CreditPurchase::where('employer_id', $correction->employer_id)->whereIn('status', CreditPurchase::PAID_STATUSES)->get();

            return ['left_cents' => $balance, 'plan' => CorrectionLimits::employerPlan($purchases, $balance)['plan']];
        }

        return ['left_cents' => $correction->payout->amount_cents];
    }

    public function approve(Request $request, Correction $correction): RedirectResponse
    {
        $bag = 'correction'.$correction->id;
        $rules = ['reason' => ['required', 'string', 'min:5', 'max:500']];
        $dollars = ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/'];

        $data = $request->validateWithBag($bag, $rules + match (true) {
            $correction->type === Correction::TYPE_REDEMPTION_REFUND && $correction->redemption->items()->exists() => ['items' => ['required', 'array'], 'items.*' => ['integer', 'min:0', 'max:99']],
            $correction->type === Correction::TYPE_MERCHANT_ADJUSTMENT => [],
            default => ['amount' => $dollars],
        }, ['amount.regex' => 'Enter an amount in dollars, like 4.50.', 'items.required' => 'Choose at least one item to refund.']);

        return $this->decide($correction, function () use ($correction, $request, $data) {
            $by = $request->user();

            return match ($correction->type) {
                Correction::TYPE_REDEMPTION_REFUND => $this->decisions->approveRedemptionRefund($correction, $by, $data['reason'],
                    array_map('intval', $data['items'] ?? []), isset($data['amount']) ? Money::toCents($data['amount']) : null),
                Correction::TYPE_EMPLOYER_REFUND => $this->decisions->approveEmployerRefund($correction, $by, $data['reason'], Money::toCents($data['amount'])),
                Correction::TYPE_MERCHANT_ADJUSTMENT => $this->decisions->approveMerchantAdjustment($correction, $by, $data['reason']),
            };
        });
    }

    public function reject(Request $request, Correction $correction): RedirectResponse
    {
        $data = $request->validateWithBag('correction'.$correction->id, ['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->decide($correction, fn () => $this->decisions->reject($correction, $request->user(), $data['reason']));
    }

    public function retry(Request $request, Correction $correction): RedirectResponse
    {
        return $this->decide($correction, fn () => $this->decisions->retry($correction, $request->user()));
    }

    public function close(Request $request, Correction $correction): RedirectResponse
    {
        $data = $request->validateWithBag('correction'.$correction->id, ['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->decide($correction, fn () => $this->decisions->close($correction, $request->user(), $data['reason']));
    }

    private function decide(Correction $correction, callable $action): RedirectResponse
    {
        Gate::authorize('decide-corrections');
        $back = redirect()->to(route('bid-admin.corrections').'#correction-'.$correction->id);

        try {
            $done = $action()->load(['redemption.employee', 'redemption.merchant', 'employer', 'payout.merchant']);
        } catch (InvalidArgumentException $e) {
            return $back->withInput()->with('error', 'Nothing changed: '.$e->getMessage());
        }

        return $back->with($done->status === Correction::STATUS_FAILED ? 'error' : ($done->status === Correction::STATUS_PROCESSING ? 'notice' : 'status'), self::outcome($done));
    }

    /** The toast after a decision: the amount, the place and the person by initials. */
    public static function outcome(Correction $c): string
    {
        $who = match ($c->type) {
            Correction::TYPE_REDEMPTION_REFUND => $c->redemption->employee->initials().' at '.$c->redemption->merchant->name,
            Correction::TYPE_EMPLOYER_REFUND => $c->employer->name,
            Correction::TYPE_MERCHANT_ADJUSTMENT => $c->payout->merchant->name,
        };

        return match ($c->status) {
            Correction::STATUS_REJECTED => 'Not approved: '.$who.' sees your reason.',
            Correction::STATUS_CLOSED => 'Closed without a refund for '.$who.'.'.($c->type === Correction::TYPE_EMPLOYER_REFUND ? ' Any held credits are back to give.' : ''),
            Correction::STATUS_FAILED => $c->typeLabel().' of '.$c->amountLabel().' for '.$who.' did not go through: '.$c->failure_reason.' Retry it, or close it.',
            Correction::STATUS_PROCESSING => $c->typeLabel().' of '.$c->amountLabel().' for '.$who.' sent to Stripe (test mode). It completes when Stripe confirms.'
                .($c->failure_reason ? ' '.$c->failure_reason : ''),
            default => $c->typeLabel().' of '.$c->amountLabel().' for '.$who.' completed'.($c->actions->every->isStripe() ? ' in Stripe test mode.' : ' (simulated, no real money).'),
        };
    }
}
