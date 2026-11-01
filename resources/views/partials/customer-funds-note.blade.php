{{--
    Where an employer's money is held (ADR 0034), on Buy credits and its
    receipt. Words only, never a fee or figure: with the fee off the employer
    page never says "fee" (BuyCreditsFlowTest). $stripe: Stripe test mode.
--}}
<p class="{{ $class ?? 'mt-3 text-sm' }}" data-customer-funds-note>
    @if ($stripe)
        Your money is held in the Local Goods customer funds account (Stripe test mode) and stays yours until your staff spend it.
        It is never used for Local Goods' own expenses. Money Local Goods earns is moved out separately, by a recorded sweep.
    @else
        Your money is recorded as customer funds (simulated, no real money) and stays yours until your staff spend it.
        It is never used for Local Goods' own expenses.
    @endif
</p>
